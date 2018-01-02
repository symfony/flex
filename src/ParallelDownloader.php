<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Config;
use Composer\Downloader\FileDownloader;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\RemoteFilesystem;

/**
 * Speedup Composer by downloading packages in parallel.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ParallelDownloader extends RemoteFilesystem
{
    private $io;
    private $config;
    private $urlsCount;
    private $bytesTransferred;
    private $bytesMax;
    private $lastProgress;
    private $fileUrls;

    public function __construct(IOInterface $io, Config $config)
    {
        $this->io = $io;
        $this->config = $config;
        $rfs = Factory::createRemoteFilesystem($io, $config);
        parent::__construct($io, $config, $rfs->getOptions(), $rfs->isTlsDisabled());
    }

    public function populateCacheDir(array $operations)
    {
        $this->bytesMax = $this->bytesTransferred = 0;
        $this->fileUrls = [];
        $cacheDir = rtrim($this->config->get('cache-files-dir'), '\/').DIRECTORY_SEPARATOR;
        $getCacheKey = function (PackageInterface $package, $processedUrl) { return $this->getCacheKey($package, $processedUrl); };
        $getCacheKey = \Closure::bind($getCacheKey, new FileDownloader($this->io, $this->config), FileDownloader::class);

        foreach ($operations as $op) {
            if ('install' === $op->getJobType()) {
                $package = $op->getPackage();
            } elseif ('update' === $op->getJobType()) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            if (!$originUrl = $package->getDistUrl()) {
                continue;
            }

            if ($package->getDistMirrors()) {
                $originUrl = current($package->getDistUrls());
            }

            if (!preg_match('/^https?:/', $originUrl) || !parse_url($originUrl, PHP_URL_HOST)) {
                continue;
            }

            if (file_exists($file = $cacheDir.$getCacheKey($package, $originUrl))) {
                continue;
            }

            @mkdir(dirname($file), 0775, true);

            if (!is_dir(dirname($file))) {
                continue;
            }

            if (preg_match('#^https://api\.github\.com/repos(/[^/]++/[^/]++/)zipball(.++)$#', $originUrl, $m)) {
                $fileUrl = sprintf('https://codeload.github.com%slegacy.zip%s', $m[1], $m[2]);
            } else {
                $fileUrl = $originUrl;
            }

            $this->fileUrls[] = [$originUrl, $fileUrl, $file];
        }

        if (!$this->urlsCount = count($this->fileUrls)) {
            return;
        }

        $this->lastProgress = 0;
        $this->io->writeError('');
        $this->io->writeError(sprintf('<info>Prefetching %d packages 🎶</info>', $this->urlsCount));
        $this->io->writeError('  - Connecting', false);
        $this->io->writeError(' (<comment>0%</comment>)', false);
        try {
            $this->getNext();
            $this->io->overwriteError(' (<comment>100%</comment>)');
        } finally {
            $this->io->writeError('');
        }
    }

    protected function callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        parent::callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);

        if (STREAM_NOTIFY_FILE_SIZE_IS === $notificationCode) {
            $this->bytesMax += $bytesMax;
        }

        if (!$bytesMax || STREAM_NOTIFY_PROGRESS !== $notificationCode) {
            return;
        }

        if ($this->fileUrls) {
            $progress = $this->urlsCount ? intval(100 * ($this->urlsCount - count($this->fileUrls)) / $this->urlsCount) : 100;
        } else {
            $progress = $this->bytesTransferred + $bytesTransferred;
            $progress = intval(100 * $progress / $this->bytesMax);
        }

        if ($bytesTransferred === $bytesMax) {
            $this->bytesTransferred += $bytesMax;
        }

        if ($progress - $this->lastProgress >= 5) {
            $this->lastProgress = $progress;
            $this->io->overwriteError(sprintf(' (<comment>%d%%</comment>)', $progress), false);
        }

        if ($this->fileUrls) {
            $this->getNext();
        }
    }

    private function getNext()
    {
        while ($this->fileUrls) {
            try {
                list($originUrl, $fileUrl, $file) = array_pop($this->fileUrls);
                if (!$this->fileUrls) {
                    $this->lastProgress = 0;
                    $this->io->overwriteError(' (<comment>100%</comment>)');
                    $this->io->writeError('  - Downloading', false);
                    $this->io->writeError(' (<comment>0%</comment>)', false);
                }
                $this->get($originUrl, $fileUrl, [], $file, false);
            } catch (TransportException $e) {
                --$this->urlsCount;
            }
        }
    }
}
