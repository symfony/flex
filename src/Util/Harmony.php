<?php

namespace Harmony\Flex\Util;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Harmony\Flex\Repository\HarmonyRepository;
use Harmony\Sdk;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Class Harmony
 *
 * @package Harmony\Flex\Util
 */
class Harmony
{

    /** @var IOInterface $io */
    protected $io;

    /** @var Config $config */
    protected $config;

    /** @var ProcessExecutor $process */
    protected $process;

    /** @var RemoteFilesystem $remoteFilesystem */
    protected $remoteFilesystem;

    /** @var array $authConfigSource */
    protected $authConfigSource;

    /**
     * Constructor.
     *
     * @param IOInterface|SymfonyStyle $io               The IO instance
     * @param Config                   $config           The composer configuration
     * @param ProcessExecutor          $process          Process instance, injectable for mocking
     * @param RemoteFilesystem         $remoteFilesystem Remote Filesystem, injectable for mocking
     */
    public function __construct($io, Config $config, ProcessExecutor $process = null,
                                RemoteFilesystem $remoteFilesystem = null)
    {
        $this->io               = $io;
        $this->config           = $config;
        $this->process          = $process ?: new ProcessExecutor();
        $this->remoteFilesystem = $remoteFilesystem;
    }

    /**
     * @param Sdk\Client $client
     *
     * @return string
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function askOAuthInteractively(Sdk\Client $client = null)
    {
        $this->io->writeln("Please provide your HarmonyCMS API OAuth2 Access Token.");

        $url = 'https://' . HarmonyRepository::ACCOUNT_URL . '/settings/api';
        $this->io->writeln(sprintf('Head to %s to retrieve your token.', $url));
        $this->io->writeln(sprintf('It will be stored in "%s" for future use by Composer.',
            $this->config->getAuthConfigSource()->getName()));

        $retries = 3;
        $step    = 1;
        while ($retries --) {
            $token = trim($this->io->askHidden('OAuth2 Access Token (hidden): ', function ($value) {
                return $value;
            }));
            if (!$token) {
                $this->io->error('<warning>No token given, aborting.</warning>');

                return false;
            }

            // No instance of client provided (default behavior)
            if (null === $client) {
                // Set authentication
                $this->io->setAuthentication(HarmonyRepository::REPOSITORY_NAME, $token, 'oauth2');

                try {
                    $this->remoteFilesystem->getContents(HarmonyRepository::REPOSITORY_URL,
                        'https://' . HarmonyRepository::REPOSITORY_URL . '/', false, ['retry-auth-failure' => false]);
                }
                catch (TransportException $e) {
                    if (in_array($e->getCode(), [403, 401])) {
                        $this->io->error('<error>Invalid token provided.</error>');

                        return false;
                    }

                    throw $e;
                }

                // store value in user config
                $this->config->getConfigSource()->removeConfigSetting('harmony-oauth.' .
                    HarmonyRepository::REPOSITORY_NAME);
                $this->config->getAuthConfigSource()->addConfigSetting('harmony-oauth.' .
                    HarmonyRepository::REPOSITORY_NAME, $token);

                $this->io->writeln('<info>Token stored successfully.</info>');

                return $token;
            }

            // Sdk\Client instance, used when creating a new project to interact with HarmonyCMS API
            if (!empty($token) && null !== $client) {
                /** @var Sdk\Receiver\Events $events */
                $events      = $client->getReceiver(Sdk\Client::RECEIVER_EVENTS);
                $tokenStatus = $events->tokenStatus($token);
                if (isset($tokenStatus['status']) && 'authenticated' === $tokenStatus['status']) {
                    // Set authentication
                    $this->io->setAuthentication(HarmonyRepository::REPOSITORY_NAME, $token, 'oauth2');

                    // Authenticate SDK with OAuth2 Bearer token
                    $client->setBearerToken($token);
                    try {
                        // store value in user config
                        $this->config->getConfigSource()->removeConfigSetting('harmony-oauth.' .
                            HarmonyRepository::REPOSITORY_NAME);
                        $this->config->getAuthConfigSource()->addConfigSetting('harmony-oauth.' .
                            HarmonyRepository::REPOSITORY_NAME, $token);

                        $this->io->success('Valid OAuth2 Access Token, welcome "' . $tokenStatus['username'] . '"!');

                        return $token;
                    }
                    catch (IOException $e) {
                        $this->io->error('Error saving your Access Token!');
                    }
                } elseif (isset($tokenStatus['status']) && 'anon.' === $tokenStatus['status']) {
                    $this->io->error(sprintf('[%d/3] Anonymous Access Token provided, please provide an authenticated access token to continue',
                        $step));
                    ++ $step;
                    if ($retries) {
                        usleep(100000);
                        continue;
                    }
                } else {
                    $this->io->error(sprintf('[%d/3] Invalid Access Token provided, please try again', $step));
                    ++ $step;
                    if ($retries) {
                        usleep(100000);
                        continue;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Authorizes a HarmonyCMS domain interactively via OAuth
     *
     * @param  string $message The reason this authorization is required
     *
     * @throws \RuntimeException
     * @throws TransportException|\Exception
     * @throws \Http\Client\Exception
     * @return bool                          true on success
     */
    public function authorizeOAuthInteractively($message = null)
    {
        if ($message) {
            $this->io->writeError($message);
        }

        $this->io->writeln("Please provide your HarmonyCMS API OAuth2 Access Token.");

        $url = 'https://' . HarmonyRepository::ACCOUNT_URL . '/settings/api';
        $this->io->writeln(sprintf('Head to %s to retrieve your token.', $url));
        $this->io->writeln(sprintf('It will be stored in "%s" for future use by Composer.',
            $this->config->getAuthConfigSource()->getName()));

        $token = trim($this->io->askAndHideAnswer('OAuth2 Access Token (hidden): '));

        if (!$token) {
            $this->io->writeError('<warning>No token given, aborting.</warning>');

            return false;
        }

        // Set authentication
        $this->io->setAuthentication(HarmonyRepository::REPOSITORY_NAME, $token, 'oauth2');

        try {
            $this->remoteFilesystem->getContents(HarmonyRepository::REPOSITORY_URL,
                'https://' . HarmonyRepository::REPOSITORY_URL, false, ['retry-auth-failure' => false]);
        }
        catch (TransportException $e) {
            if (in_array($e->getCode(), [403, 401])) {
                $this->io->error('<error>Invalid token provided.</error>');

                return false;
            }

            throw $e;
        }

        // store value in user config
        $this->config->getConfigSource()->removeConfigSetting('harmony-oauth.' . HarmonyRepository::REPOSITORY_NAME);
        $this->config->getAuthConfigSource()->addConfigSetting('harmony-oauth.' . HarmonyRepository::REPOSITORY_NAME,
            $token);

        $this->io->writeln('<info>Token stored successfully.</info>');

        return true;
    }

    /**
     * Extract ratelimit from response.
     *
     * @param array $headers Headers from Composer\Downloader\TransportException.
     *
     * @return array Associative array with the keys limit and reset.
     */
    public function getRateLimit(array $headers)
    {
        $rateLimit = [
            'limit' => '?',
            'reset' => '?',
        ];

        foreach ($headers as $header) {
            $header = trim($header);
            if (false === strpos($header, 'X-RateLimit-')) {
                continue;
            }
            list($type, $value) = explode(':', $header, 2);
            switch ($type) {
                case 'X-RateLimit-Limit':
                    $rateLimit['limit'] = (int)trim($value);
                    break;
                case 'X-RateLimit-Reset':
                    $rateLimit['reset'] = date('Y-m-d H:i:s', (int)trim($value));
                    break;
            }
        }

        return $rateLimit;
    }

    /**
     * Finds whether a request failed due to rate limiting
     *
     * @param array $headers Headers from Composer\Downloader\TransportException.
     *
     * @return bool
     */
    public function isRateLimited(array $headers)
    {
        foreach ($headers as $header) {
            if (preg_match('{^X-RateLimit-Remaining: *0$}i', trim($header))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function hasAuthenticationBearer(): bool
    {
        $jsonFile = new JsonFile($this->config->getAuthConfigSource()->getName(), $this->remoteFilesystem);
        if ($jsonFile->exists()) {
            $this->authConfigSource = $jsonFile->read();

            return isset($this->authConfigSource['harmony-oauth']) &&
                isset($this->authConfigSource['harmony-oauth'][HarmonyRepository::REPOSITORY_NAME]) && !empty
                ($this->authConfigSource['harmony-oauth'][HarmonyRepository::REPOSITORY_NAME]);
        }

        return false;
    }

    /**
     * @return string
     */
    public function getBearerAuthorizationHeader(): string
    {
        return sprintf('Authorization: Bearer %s',
            $this->authConfigSource['harmony-oauth'][HarmonyRepository::REPOSITORY_NAME]);
    }
}