<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Harmony\Flex;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\AuthHelper;
use Composer\Util\Bitbucket;
use Composer\Util\GitHub;
use Composer\Util\GitLab;
use Composer\Util\Platform;
use Composer\Util\RemoteFilesystem;
use Composer\Util\StreamContextFactory;
use Harmony\Flex\Repository\HarmonyRepository;
use Harmony\Flex\Util\Harmony as HarmonyUtil;

/**
 * Speedup Composer by downloading packages in parallel.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ParallelDownloader extends RemoteFilesystem
{

    /** @var IOInterface $io */
    private $io;

    /** @var Config $config */
    private $config;

    private $scheme;

    private $bytesMax;

    private $originUrl;

    private $fileUrl;

    private $retry;

    private $lastProgress;

    /** @var CurlDownloader $downloader */
    private $downloader;

    /** @var bool $quiet */
    private $quiet = true;

    /** @var bool $progress */
    private $progress = true;

    /** @var $nextCallback */
    private $nextCallback;

    /** @var $downloadCount */
    private $downloadCount;

    /** @var array $options */
    private $options = [];

    /** @var array $nextOptions */
    private $nextOptions = [];

    /** @var $sharedState */
    private $sharedState;

    /** @var $fileName */
    private $fileName;

    /** @var array $lastHeaders */
    private $lastHeaders = [];

    /** @var bool $cacheNext */
    public static $cacheNext = false;

    /** @var array $cache */
    private static $cache = [];

    private        $retryAuthFailure;

    private        $storeAuth;

    /** @var bool $degradedMode */
    private $degradedMode = false;

    private $redirects;

    /** @var int $maxRedirects */
    private $maxRedirects = 20;

    /**
     * ParallelDownloader constructor.
     *
     * @param IOInterface $io
     * @param Config      $config
     * @param array       $options
     * @param bool        $disableTls
     */
    public function __construct(IOInterface $io, Config $config, array $options = [], $disableTls = false)
    {
        $this->io     = $io;
        $this->config = $config;
        if (!method_exists(parent::class, 'getRemoteContents')) {
            $this->io->writeError('Composer >=1.7 not found, downloads will happen in sequence', true,
                IOInterface::DEBUG);
        } elseif (!\extension_loaded('curl')) {
            $this->io->writeError('ext-curl not found, downloads will happen in sequence', true, IOInterface::DEBUG);
        } else {
            $this->downloader = new CurlDownloader();
        }
        parent::__construct($io, $config, $options, $disableTls);
    }

    /**
     * @param array    $nextArgs
     * @param callable $nextCallback
     * @param bool     $quiet
     * @param bool     $progress
     */
    public function download(array &$nextArgs, callable $nextCallback, bool $quiet = true, bool $progress = true)
    {
        $previousState       = [
            $this->quiet,
            $this->progress,
            $this->downloadCount,
            $this->nextCallback,
            $this->sharedState
        ];
        $this->quiet         = $quiet;
        $this->progress      = $progress;
        $this->downloadCount = \count($nextArgs);
        $this->nextCallback  = $nextCallback;
        $this->sharedState   = (object)[
            'bytesMaxCount'     => 0,
            'bytesMax'          => 0,
            'bytesTransferred'  => 0,
            'nextArgs'          => &$nextArgs,
            'nestingLevel'      => 0,
            'maxNestingReached' => false,
            'lastProgress'      => 0,
            'lastUpdate'        => microtime(true),
        ];

        if (!$this->quiet) {
            if (!$this->downloader && method_exists(parent::class, 'getRemoteContents')) {
                $this->io->writeError('<warning>Enable the "cURL" PHP extension for faster downloads</warning>');
            }
            $note = '\\' === \DIRECTORY_SEPARATOR ? '' : (false !== stripos(PHP_OS, 'darwin') ? '🎵' : '🎶');
            $note .= $this->downloader ? ('\\' !== \DIRECTORY_SEPARATOR ? ' 💨' : '') : '';
            $this->io->writeError('');
            $this->io->writeError(sprintf('<info>Prefetching %d packages</info> %s', $this->downloadCount, $note));
            $this->io->writeError('  - Downloading', false);
            if ($this->progress) {
                $this->io->writeError(' (<comment>0%</comment>)', false);
            }
        }
        try {
            $this->getNext();
            if ($this->quiet) {
                // no-op
            } elseif ($this->progress) {
                $this->io->overwriteError(' (<comment>100%</comment>)');
            } else {
                $this->io->writeError(' (<comment>100%</comment>)');
            }
        } finally {
            if (!$this->quiet) {
                $this->io->writeError('');
            }
            list($this->quiet, $this->progress, $this->downloadCount, $this->nextCallback, $this->sharedState)
                = $previousState;
        }
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return array Options
     */
    public function getOptions()
    {
        $options           = array_replace_recursive(parent::getOptions(), $this->nextOptions);
        $this->nextOptions = [];

        return $options;
    }

    /**
     * Returns the headers of the last request
     *
     * @return array
     */
    public function getLastHeaders(): array
    {
        return $this->lastHeaders ?? parent::getLastHeaders();
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function setNextOptions(array $options)
    {
        $this->nextOptions = parent::getOptions() !== $options ? $options : [];

        return $this;
    }

    /**
     * @param string $originUrl
     * @param string $fileUrl
     * @param string $fileName
     * @param bool   $progress
     * @param array  $options
     *
     * @return bool|string
     * @throws \Http\Client\Exception
     * @throws \Throwable
     */
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = [])
    {
        $options           = array_replace_recursive($this->nextOptions, $options);
        $this->nextOptions = [];
        $rfs               = clone $this;
        $rfs->fileName     = $fileName;
        $rfs->progress     = $this->progress && $progress;

        try {
            return $rfs->get($originUrl, $fileUrl, $options, $fileName, $rfs->progress);
        } finally {
            $rfs->lastHeaders  = [];
            $this->lastHeaders = $rfs->getLastHeaders();
        }
    }

    /**
     * @param string $originUrl
     * @param string $fileUrl
     * @param bool   $progress
     * @param array  $options
     *
     * @return bool|string
     * @throws \Http\Client\Exception
     * @throws \Throwable
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = [])
    {
        return $this->copy($originUrl, $fileUrl, null, $progress, $options);
    }

    /**
     * Get notification action.
     *
     * @internal
     *
     * @param      $notificationCode
     * @param      $severity
     * @param      $message
     * @param      $messageCode
     * @param      $bytesTransferred
     * @param      $bytesMax
     * @param bool $nativeDownload
     */
    public function callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax,
                                $nativeDownload = true)
    {
        if (!$nativeDownload && STREAM_NOTIFY_SEVERITY_ERR === $severity) {
            throw new TransportException($message, $messageCode);
        }

        parent::callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);

        if (!$state = $this->sharedState) {
            return;
        }

        if (STREAM_NOTIFY_FILE_SIZE_IS === $notificationCode) {
            ++ $state->bytesMaxCount;
            $state->bytesMax += $bytesMax;
        }

        if (!$bytesMax || STREAM_NOTIFY_PROGRESS !== $notificationCode) {
            if ($state->nextArgs && !$nativeDownload) {
                $this->getNext();
            }

            return;
        }

        if (0 < $state->bytesMax) {
            $progress = $state->bytesMaxCount / $this->downloadCount;
            $progress *= 100 * ($state->bytesTransferred + $bytesTransferred) / $state->bytesMax;
        } else {
            $progress = 0;
        }

        if ($bytesTransferred === $bytesMax) {
            $state->bytesTransferred += $bytesMax;
        }

        if (null !== $state->nextArgs && !$this->quiet && $this->progress && 1 <= $progress - $state->lastProgress) {
            $progressTime = microtime(true);

            if (5 <= $progress - $state->lastProgress || 1 <= $progressTime - $state->lastUpdate) {
                $state->lastProgress = $progress;
                $this->io->overwriteError(sprintf(' (<comment>%d%%</comment>)', $progress), false);
                $state->lastUpdate = microtime(true);
            }
        }

        if (!$nativeDownload || !$state->nextArgs || $bytesTransferred === $bytesMax || $state->maxNestingReached) {
            return;
        }

        if (5 < $state->nestingLevel) {
            $state->maxNestingReached = true;
        } else {
            $this->getNext();
        }
    }

    /**
     * Get file content or copy action.
     *
     * @param string $originUrl         The origin URL
     * @param string $fileUrl           The file URL
     * @param array  $additionalOptions context options
     * @param string $fileName          the local filename
     * @param bool   $progress          Display the progression
     *
     * @throws TransportException|\Exception
     * @throws TransportException            When the file could not be downloaded*@throws \Throwable
     * @throws \Throwable
     * @throws \Http\Client\Exception
     * @return bool|string
     */
    protected function get($originUrl, $fileUrl, $additionalOptions = [], $fileName = null, $progress = true)
    {
        if (strpos($originUrl, '.github.com') === (strlen($originUrl) - 11)) {
            $originUrl = 'github.com';
        }

        if (strpos($originUrl, HarmonyRepository::REPOSITORY_NAME) === (strlen($originUrl) - 14)) {
            $originUrl = HarmonyRepository::REPOSITORY_NAME;
        }

        // Gitlab can be installed in a non-root context (i.e. gitlab.com/foo). When downloading archives the originUrl
        // is the host without the path, so we look for the registered gitlab-domains matching the host here
        if ($this->config && is_array($this->config->get('gitlab-domains')) && false === strpos($originUrl, '/') &&
            !in_array($originUrl, $this->config->get('gitlab-domains'))) {
            foreach ($this->config->get('gitlab-domains') as $gitlabDomain) {
                if (0 === strpos($gitlabDomain, $originUrl)) {
                    $originUrl = $gitlabDomain;
                    break;
                }
            }
            unset($gitlabDomain);
        }

        $this->scheme           = parse_url($fileUrl, PHP_URL_SCHEME);
        $this->bytesMax         = 0;
        $this->originUrl        = $originUrl;
        $this->fileUrl          = $fileUrl;
        $this->fileName         = $fileName;
        $this->progress         = $progress;
        $this->lastProgress     = null;
        $this->retryAuthFailure = true;
        $this->lastHeaders      = [];
        $this->redirects        = 1; // The first request counts.

        // capture username/password from URL if there is one
        if (preg_match('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $fileUrl, $match)) {
            $this->io->setAuthentication($originUrl, rawurldecode($match[1]), rawurldecode($match[2]));
        }

        $tempAdditionalOptions = $additionalOptions;
        if (isset($tempAdditionalOptions['retry-auth-failure'])) {
            $this->retryAuthFailure = (bool)$tempAdditionalOptions['retry-auth-failure'];

            unset($tempAdditionalOptions['retry-auth-failure']);
        }

        $isRedirect = false;
        if (isset($tempAdditionalOptions['redirects'])) {
            $this->redirects = $tempAdditionalOptions['redirects'];
            $isRedirect      = true;

            unset($tempAdditionalOptions['redirects']);
        }

        $options = $this->getOptionsForUrl($originUrl, $tempAdditionalOptions);
        unset($tempAdditionalOptions);

        $origFileUrl = $fileUrl;

        if (isset($options['github-token'])) {
            // only add the access_token if it is actually a github URL (in case we were redirected to S3)
            if (preg_match('{^https?://([a-z0-9-]+\.)*github\.com/}', $fileUrl)) {
                $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token=' . $options['github-token'];
            }
            unset($options['github-token']);
        }

        if (isset($options['gitlab-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token=' . $options['gitlab-token'];
            unset($options['gitlab-token']);
        }

        if (isset($options['http'])) {
            $options['http']['ignore_errors'] = true;
        }

        if ($this->degradedMode && substr($fileUrl, 0, 26) === 'http://repo.packagist.org/') {
            // access packagist using the resolved IPv4 instead of the hostname to force IPv4 protocol
            $fileUrl           = 'http://' . gethostbyname('repo.packagist.org') . substr($fileUrl, 20);
            $degradedPackagist = true;
        }

        if (HarmonyRepository::REPOSITORY_URL === parse_url($fileUrl, PHP_URL_HOST)) {
            $harmonyUtil = new HarmonyUtil($this->io, $this->config, null, $this);
            if ($harmonyUtil->hasAuthenticationBearer()) {
                $options['http']['header'][] = $harmonyUtil->getBearerAuthorizationHeader();
            }
        }

        $ctx = StreamContextFactory::getContext($fileUrl, $options, ['notification' => [$this, 'callbackGet']]);

        $actualContextOptions = stream_context_get_options($ctx);
        $usingProxy           = !empty($actualContextOptions['http']['proxy']) ?
            ' using proxy ' . $actualContextOptions['http']['proxy'] : '';
        $this->io->writeError((substr($origFileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $origFileUrl .
            $usingProxy, true, IOInterface::DEBUG);
        unset($origFileUrl, $actualContextOptions);

        // Check for secure HTTP, but allow insecure Packagist calls to $hashed providers as file integrity is verified with sha256
        if ((!preg_match('{^http://(repo\.)?packagist\.org/p/}', $fileUrl) ||
                (false === strpos($fileUrl, '$') && false === strpos($fileUrl, '%24'))) && empty($degradedPackagist) &&
            $this->config) {
            $this->config->prohibitUrlByConfig($fileUrl, $this->io);
        }

        if ($this->progress && !$isRedirect) {
            $this->io->writeError("Downloading (<comment>connecting...</comment>)", false);
        }

        $errorMessage = '';
        $errorCode    = 0;
        $result       = false;
        set_error_handler(function ($code, $msg) use (&$errorMessage) {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= preg_replace('{^file_get_contents\(.*?\): }', '', $msg);
        });
        try {
            $result = $this->getRemoteContents($originUrl, $fileUrl, $ctx, $http_response_header);

            if (!empty($http_response_header[0])) {
                $statusCode = $this->findStatusCode($http_response_header);
                if (in_array($statusCode, [401, 403]) && $this->retryAuthFailure) {
                    $warning = null;
                    if ($this->findHeaderValue($http_response_header, 'content-type') === 'application/json') {
                        $data = json_decode($result, true);
                        if (!empty($data['warning'])) {
                            $warning = $data['warning'];
                        }
                    }
                    $this->promptAuthAndRetry($statusCode, $this->findStatusMessage($http_response_header), $warning,
                        $http_response_header);
                }
            }

            $contentLength = !empty($http_response_header[0]) ?
                $this->findHeaderValue($http_response_header, 'content-length') : null;
            if ($contentLength && Platform::strlen($result) < $contentLength) {
                // alas, this is not possible via the stream callback because STREAM_NOTIFY_COMPLETED is documented, but not implemented anywhere in PHP
                $e = new TransportException('Content-Length mismatch, received ' . Platform::strlen($result) .
                    ' bytes out of the expected ' . $contentLength);
                $e->setHeaders($http_response_header);
                $e->setStatusCode($this->findStatusCode($http_response_header));
                $e->setResponse($result);
                $this->io->writeError('Content-Length mismatch, received ' . Platform::strlen($result) . ' out of ' .
                    $contentLength . ' bytes: (' . base64_encode($result) . ')', true, IOInterface::DEBUG);

                throw $e;
            }
        }
        catch (\Exception $e) {
            if ($e instanceof TransportException && !empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
                $e->setStatusCode($this->findStatusCode($http_response_header));
            }
            if ($e instanceof TransportException && $result !== false) {
                $e->setResponse($result);
            }
            $result = false;
        }
        if ($errorMessage && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $errorMessage = 'allow_url_fopen must be enabled in php.ini (' . $errorMessage . ')';
        }
        restore_error_handler();
        if (isset($e) && !$this->retry) {
            if (!$this->degradedMode && false !== strpos($e->getMessage(), 'Operation timed out')) {
                $this->degradedMode = true;
                $this->io->writeError('');
                $this->io->writeError([
                    '<error>' . $e->getMessage() . '</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ]);

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName,
                    $this->progress);
            }

            throw $e;
        }

        $statusCode     = null;
        $contentType    = null;
        $locationHeader = null;
        if (!empty($http_response_header[0])) {
            $statusCode     = $this->findStatusCode($http_response_header);
            $contentType    = $this->findHeaderValue($http_response_header, 'content-type');
            $locationHeader = $this->findHeaderValue($http_response_header, 'location');
        }

        // check for bitbucket login page asking to authenticate
        if ($originUrl === 'bitbucket.org' && !$this->isPublicBitBucketDownload($fileUrl) &&
            substr($fileUrl, - 4) === '.zip' && (!$locationHeader || substr($locationHeader, - 4) !== '.zip') &&
            $contentType && preg_match('{^text/html\b}i', $contentType)) {
            $result = false;
            if ($this->retryAuthFailure) {
                $this->promptAuthAndRetry(401);
            }
        }

        // check for gitlab 404 when downloading archives
        if ($statusCode === 404 && $this->config && in_array($originUrl, $this->config->get('gitlab-domains'), true) &&
            false !== strpos($fileUrl, 'archive.zip')) {
            $result = false;
            if ($this->retryAuthFailure) {
                $this->promptAuthAndRetry(401);
            }
        }

        // handle 3xx redirects, 304 Not Modified is excluded
        $hasFollowedRedirect = false;
        if ($statusCode >= 300 && $statusCode <= 399 && $statusCode !== 304 && $this->redirects < $this->maxRedirects) {
            $hasFollowedRedirect = true;
            $result              = $this->handleRedirect($http_response_header, $additionalOptions, $result);
        }

        // fail 4xx and 5xx responses and capture the response
        if ($statusCode && $statusCode >= 400 && $statusCode <= 599) {
            if (!$this->retry) {
                if ($this->progress && !$this->retry && !$isRedirect) {
                    $this->io->overwriteError("Downloading (<error>failed</error>)", false);
                }

                $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded (' .
                    $http_response_header[0] . ')', $statusCode);
                $e->setHeaders($http_response_header);
                $e->setResponse($result);
                $e->setStatusCode($statusCode);
                throw $e;
            }
            $result = false;
        }

        if ($this->progress && !$this->retry && !$isRedirect) {
            $this->io->overwriteError("Downloading (" .
                ($result === false ? '<error>failed</error>' : '<comment>100%</comment>') . ")", false);
        }

        // decode gzip
        if ($result && extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http' && !$hasFollowedRedirect) {
            $contentEncoding = $this->findHeaderValue($http_response_header, 'content-encoding');
            $decode          = $contentEncoding && 'gzip' === strtolower($contentEncoding);

            if ($decode) {
                try {
                    $result = zlib_decode($result);
                    if (!$result) {
                        throw new TransportException('Failed to decode zlib stream');
                    }
                }
                catch (\Exception $e) {
                    if ($this->degradedMode) {
                        throw $e;
                    }

                    $this->degradedMode = true;
                    $this->io->writeError([
                        '',
                        '<error>Failed to decode response: ' . $e->getMessage() . '</error>',
                        '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                    ]);

                    return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName,
                        $this->progress);
                }
            }
        }

        // handle copy command if download was successful
        if (false !== $result && null !== $fileName && !$isRedirect) {
            if ('' === $result) {
                throw new TransportException('"' . $this->fileUrl .
                    '" appears broken, and returned an empty 200 response');
            }

            $errorMessage = '';
            set_error_handler(function ($code, $msg) use (&$errorMessage) {
                if ($errorMessage) {
                    $errorMessage .= "\n";
                }
                $errorMessage .= preg_replace('{^file_put_contents\(.*?\): }', '', $msg);
            });
            $result = (bool)file_put_contents($fileName, $result);
            restore_error_handler();
            if (false === $result) {
                throw new TransportException('The "' . $this->fileUrl . '" file could not be written to ' . $fileName .
                    ': ' . $errorMessage);
            }
        }

        if ($this->retry) {
            $this->retry = false;

            $result = $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName,
                $this->progress);

            if ($this->storeAuth && $this->config) {
                $authHelper = new AuthHelper($this->io, $this->config);
                $authHelper->storeAuth($this->originUrl, $this->storeAuth);
                $this->storeAuth = false;
            }

            return $result;
        }

        if (false === $result) {
            $e = new TransportException('The "' . $this->fileUrl . '" file could not be downloaded: ' . $errorMessage,
                $errorCode);
            if (!empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
            }

            if (!$this->degradedMode && false !== strpos($e->getMessage(), 'Operation timed out')) {
                $this->degradedMode = true;
                $this->io->writeError('');
                $this->io->writeError([
                    '<error>' . $e->getMessage() . '</error>',
                    '<error>Retrying with degraded mode, check https://getcomposer.org/doc/articles/troubleshooting.md#degraded-mode for more info</error>',
                ]);

                return $this->get($this->originUrl, $this->fileUrl, $additionalOptions, $this->fileName,
                    $this->progress);
            }

            throw $e;
        }

        if (!empty($http_response_header[0])) {
            $this->lastHeaders = $http_response_header;
        }

        return $result;
    }

    /**
     * @param       $httpStatus
     * @param null  $reason
     * @param null  $warning
     * @param array $headers
     *
     * @throws \Exception
     * @throws \Http\Client\Exception
     */
    protected function promptAuthAndRetry($httpStatus, $reason = null, $warning = null, $headers = [])
    {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $gitHubUtil = new GitHub($this->io, $this->config, null);
            $message    = "\n";

            $rateLimited = $gitHubUtil->isRateLimited($headers);
            if ($rateLimited) {
                $rateLimit = $gitHubUtil->getRateLimit($headers);
                if ($this->io->hasAuthentication($this->originUrl)) {
                    $message
                        = 'Review your configured GitHub OAuth token or enter a new one to go over the API rate limit.';
                } else {
                    $message = 'Create a GitHub OAuth token to go over the API rate limit.';
                }

                $message = sprintf('GitHub API limit (%d calls/hr) is exhausted, could not fetch ' . $this->fileUrl .
                        '. ' . $message . ' You can also wait until %s for the rate limit to reset.',
                        $rateLimit['limit'], $rateLimit['reset']) . "\n";
            } else {
                $message .= 'Could not fetch ' . $this->fileUrl . ', please ';
                if ($this->io->hasAuthentication($this->originUrl)) {
                    $message .= 'review your configured GitHub OAuth token or enter a new one to access private repos';
                } else {
                    $message .= 'create a GitHub OAuth token to access private repos';
                }
            }

            if (!$gitHubUtil->authorizeOAuth($this->originUrl) && (!$this->io->isInteractive() ||
                    !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))) {
                throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
            }
        } elseif ($this->config && in_array($this->originUrl, $this->config->get('gitlab-domains'), true)) {
            $message    = "\n" . 'Could not fetch ' . $this->fileUrl . ', enter your ' . $this->originUrl .
                ' credentials ' . ($httpStatus === 401 ? 'to access private repos' : 'to go over the API rate limit');
            $gitLabUtil = new GitLab($this->io, $this->config, null);

            if ($this->io->hasAuthentication($this->originUrl) &&
                ($auth = $this->io->getAuthentication($this->originUrl)) && $auth['password'] === 'private-token') {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.",
                    $httpStatus);
            }

            if (!$gitLabUtil->authorizeOAuth($this->originUrl) && (!$this->io->isInteractive() ||
                    !$gitLabUtil->authorizeOAuthInteractively($this->scheme, $this->originUrl, $message))) {
                throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
            }
        } elseif ($this->config && $this->originUrl === 'bitbucket.org') {
            $askForOAuthToken = true;
            if ($this->io->hasAuthentication($this->originUrl)) {
                $auth = $this->io->getAuthentication($this->originUrl);
                if ($auth['username'] !== 'x-token-auth') {
                    $bitbucketUtil = new Bitbucket($this->io, $this->config);
                    $accessToken   = $bitbucketUtil->requestToken($this->originUrl, $auth['username'],
                        $auth['password']);
                    if (!empty($accessToken)) {
                        $this->io->setAuthentication($this->originUrl, 'x-token-auth', $accessToken);
                        $askForOAuthToken = false;
                    }
                } else {
                    throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
                }
            }

            if ($askForOAuthToken) {
                $message       = "\n" . 'Could not fetch ' . $this->fileUrl .
                    ', please create a bitbucket OAuth token to ' .
                    (($httpStatus === 401 || $httpStatus === 403) ? 'access private repos' :
                        'go over the API rate limit');
                $bitBucketUtil = new Bitbucket($this->io, $this->config);
                if (!$bitBucketUtil->authorizeOAuth($this->originUrl) && (!$this->io->isInteractive() ||
                        !$bitBucketUtil->authorizeOAuthInteractively($this->originUrl, $message))) {
                    throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
                }
            }
        } elseif ($this->config && $this->originUrl === HarmonyRepository::REPOSITORY_NAME) {
            $harmonyUtil = new HarmonyUtil($this->io, $this->config, null, $this);
            $message     = "\n";

            $message .= 'Could not fetch ' . $this->fileUrl . ', please ';
            if ($this->io->hasAuthentication($this->originUrl)) {
                $message .= 'review your configured HarmonyCMS OAuth token or enter a new one to access private repos';
            } else {
                $message .= 'create a HarmonyCMS OAuth token to access private repos';
            }

            if (!$this->io->isInteractive() || !$harmonyUtil->authorizeOAuthInteractively($message)) {
                throw new TransportException('Could not authenticate against ' . $this->originUrl, 401);
            }
        } else {
            $this->io->write('!!!DEBUG!!! ' . $this->originUrl);
            // 404s are only handled for github
            if ($httpStatus === 404) {
                return;
            }

            // fail if the console is not interactive
            if (!$this->io->isInteractive()) {
                if ($httpStatus === 401) {
                    $message = "The '" . $this->fileUrl .
                        "' URL required authentication.\nYou must be using the interactive console to authenticate";
                }
                if ($httpStatus === 403) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $reason;
                }

                throw new TransportException($message, $httpStatus);
            }
            // fail if we already have auth
            if ($this->io->hasAuthentication($this->originUrl)) {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.",
                    $httpStatus);
            }

            $this->io->overwriteError('');
            if ($warning) {
                $this->io->writeError('    <warning>' . $warning . '</warning>');
            }
            $this->io->writeError('    Authentication required (<info>' . parse_url($this->fileUrl, PHP_URL_HOST) .
                '</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($this->originUrl, $username, $password);
            $this->storeAuth = $this->config->get('store-auths');
        }

        $this->retry = true;
        throw new TransportException('RETRY');
    }

    /**
     * @param $originUrl
     * @param $additionalOptions
     *
     * @return array
     */
    protected function getOptionsForUrl($originUrl, $additionalOptions)
    {
        $tlsOptions = [];
        $headers    = [];

        if (extension_loaded('zlib')) {
            $headers[] = 'Accept-Encoding: gzip';
        }

        $options = array_replace_recursive($this->options, $tlsOptions, $additionalOptions);
        if (!$this->degradedMode) {
            // degraded mode disables HTTP/1.1 which causes issues with some bad
            // proxies/software due to the use of chunked encoding
            $options['http']['protocol_version'] = 1.1;
            $headers[]                           = 'Connection: close';
        }

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
            } elseif ($this->config && in_array($originUrl, $this->config->get('gitlab-domains'), true)) {
                if ($auth['password'] === 'oauth2') {
                    $headers[] = 'Authorization: Bearer ' . $auth['username'];
                } elseif ($auth['password'] === 'private-token') {
                    $headers[] = 'PRIVATE-TOKEN: ' . $auth['username'];
                }
            } elseif ('bitbucket.org' === $originUrl && $this->fileUrl !== Bitbucket::OAUTH2_ACCESS_TOKEN_URL &&
                'x-token-auth' === $auth['username']) {
                if (!$this->isPublicBitBucketDownload($this->fileUrl)) {
                    $headers[] = 'Authorization: Bearer ' . $auth['password'];
                }
            } elseif (HarmonyRepository::REPOSITORY_NAME === $originUrl) {
                $headers[] = 'Authorization: Bearer ' . $auth['username'];
            } else {
                $authStr   = base64_encode($auth['username'] . ':' . $auth['password']);
                $headers[] = 'Authorization: Basic ' . $authStr;
            }
        }

        $options['http']['follow_location'] = 0;

        if (isset($options['http']['header']) && !is_array($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
        }
        foreach ($headers as $header) {
            $options['http']['header'][] = $header;
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     * @throws \Throwable
     */
    protected function getRemoteContents($originUrl, $fileUrl, $context, array &$responseHeaders = null)
    {
        if (isset(self::$cache[$fileUrl])) {
            $result = self::$cache[$fileUrl];

            if (3 < \func_num_args()) {
                list($responseHeaders, $result) = $result;
            }

            return $result;
        }

        /**
         * Issue with this parts of code, asking indefinitely for an OAuth2 access token
         */
//        if (self::$cacheNext) {
//            self::$cacheNext = false;
//
//            if (3 < \func_num_args()) {
//                $result = $this->getRemoteContents($originUrl, $fileUrl, $context, $responseHeaders);
//                self::$cache[$fileUrl] = [$responseHeaders, $result];
//            } else {
//                $result = $this->getRemoteContents($originUrl, $fileUrl, $context);
//                self::$cache[$fileUrl] = $result;
//            }
//
//            return $result;
//        }

        if (!$this->downloader) {
            return parent::getRemoteContents($originUrl, $fileUrl, $context, $responseHeaders);
        }

        try {
            $result = $this->downloader->get($originUrl, $fileUrl, $context, $this->fileName);

            if (3 < \func_num_args()) {
                list($responseHeaders, $result) = $result;
            }

            return $result;
        }
        catch (TransportException $e) {
            $this->io->writeError('Retrying download: ' . $e->getMessage(), true, IOInterface::DEBUG);

            return parent::getRemoteContents($originUrl, $fileUrl, $context, $responseHeaders);
        }
        catch (\Throwable $e) {
            $responseHeaders = [];
            throw $e;
        }
    }

    /**
     * @link https://github.com/composer/composer/issues/5584
     *
     * @param string $urlToBitBucketFile URL to a file at bitbucket.org.
     *
     * @return bool Whether the given URL is a public BitBucket download which requires no authentication.
     */
    private function isPublicBitBucketDownload($urlToBitBucketFile)
    {
        $domain = parse_url($urlToBitBucketFile, PHP_URL_HOST);
        if (strpos($domain, 'bitbucket.org') === false) {
            // Bitbucket downloads are hosted on amazonaws.
            // We do not need to authenticate there at all
            return true;
        }

        $path = parse_url($urlToBitBucketFile, PHP_URL_PATH);

        // Path for a public download follows this pattern /{user}/{repo}/downloads/{whatever}
        // {@link https://blog.bitbucket.org/2009/04/12/new-feature-downloads/}
        $pathParts = explode('/', $path);

        return count($pathParts) >= 4 && $pathParts[3] == 'downloads';
    }

    /**
     * @param array $http_response_header
     * @param array $additionalOptions
     * @param       $result
     *
     * @return bool|string
     * @throws \Throwable
     * @throws \Http\Client\Exception
     */
    private function handleRedirect(array $http_response_header, array $additionalOptions, $result)
    {
        if ($locationHeader = $this->findHeaderValue($http_response_header, 'location')) {
            if (parse_url($locationHeader, PHP_URL_SCHEME)) {
                // Absolute URL; e.g. https://example.com/composer
                $targetUrl = $locationHeader;
            } elseif (parse_url($locationHeader, PHP_URL_HOST)) {
                // Scheme relative; e.g. //example.com/foo
                $targetUrl = $this->scheme . ':' . $locationHeader;
            } elseif ('/' === $locationHeader[0]) {
                // Absolute path; e.g. /foo
                $urlHost = parse_url($this->fileUrl, PHP_URL_HOST);

                // Replace path using hostname as an anchor.
                $targetUrl = preg_replace('{^(.+(?://|@)' . preg_quote($urlHost) . '(?::\d+)?)(?:[/\?].*)?$}',
                    '\1' . $locationHeader, $this->fileUrl);
            } else {
                // Relative path; e.g. foo
                // This actually differs from PHP which seems to add duplicate slashes.
                $targetUrl = preg_replace('{^(.+/)[^/?]*(?:\?.*)?$}', '\1' . $locationHeader, $this->fileUrl);
            }
        }

        if (!empty($targetUrl)) {
            $this->redirects ++;

            $this->io->writeError('', true, IOInterface::DEBUG);
            $this->io->writeError(sprintf('Following redirect (%u) %s', $this->redirects, $targetUrl), true,
                IOInterface::DEBUG);

            $additionalOptions['redirects'] = $this->redirects;

            return $this->get(parse_url($targetUrl, PHP_URL_HOST), $targetUrl, $additionalOptions, $this->fileName,
                $this->progress);
        }

        if (!$this->retry) {
            $e = new TransportException('The "' . $this->fileUrl .
                '" file could not be downloaded, got redirect without Location (' . $http_response_header[0] . ')');
            $e->setHeaders($http_response_header);
            $e->setResponse($result);

            throw $e;
        }

        return false;
    }

    private function getNext()
    {
        $state = $this->sharedState;
        ++ $state->nestingLevel;

        try {
            while ($state->nextArgs && (!$state->maxNestingReached || 1 === $state->nestingLevel)) {
                try {
                    $state->maxNestingReached = false;
                    ($this->nextCallback)(...array_shift($state->nextArgs));
                }
                catch (TransportException $e) {
                    $this->io->writeError('Skipping download: ' . $e->getMessage(), true, IOInterface::DEBUG);
                }
            }
        } finally {
            -- $state->nestingLevel;
        }
    }
}
