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

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Downloader
{
    const ENDPOINT = 'https://flex.symfony.com';

    private $io;
    private $sess;
    private $cache;
    private $rfs;
    private $degradedMode = false;
    private $endpoint;
    private $options = [];

    public function __construct(Composer $composer, IoInterface $io)
    {
        $this->io = $io;
        $config = $composer->getConfig();
        $this->endpoint = rtrim(getenv('FLEX_ENDPOINT') ?: self::ENDPOINT, '/');
        $this->rfs = Factory::createRemoteFilesystem($io, $config);
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->endpoint));
        $this->sess = bin2hex(random_bytes(16));
        $extra = $composer->getPackage()->getExtra();
        if ($extra['flex-id'] ?? false) {
            $this->options['http'] = [
                'header' => ['Flex-ID: '.$extra['flex-id']],
            ];
        }
    }

    /**
     * Decodes a JSON HTTP response body.
     *
     * @param $path The path to get on the Flex server
     */
    public function getContents($path)
    {
        $url = $this->endpoint.'/'.ltrim($path, '/').(false === strpos($path, '&') ? '?' : '&').'s='.$this->sess;
        $cacheKey = ltrim($path, '/');

        try {
            if ($contents = $this->cache->read($cacheKey)) {
                $contents = json_decode($contents, true);
                if (isset($contents['last-modified'])) {
                    $response = $this->fetchFileIfLastModified($url, $cacheKey, $contents['last-modified']);

                    return true === $response ? $contents : $response;
                }
            }

            return $this->fetchFile($url, $cacheKey);
        } catch (TransportException $e) {
            if (404 === $e->getStatusCode()) {
                return;
            }

            throw $e;
        }
    }

    private function fetchFile($filename, $cacheKey)
    {
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $filename, false, $this->options);

                return $this->parseJson($json, $filename, $cacheKey);
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if ($contents = $this->cache->read($cacheKey)) {
                    $this->switchToDegradedMode($e);

                    return JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey);
                }

                throw $e;
            }
        }
    }

    private function fetchFileIfLastModified($filename, $cacheKey, $lastModifiedTime)
    {
        $options = $this->options;
        $retries = 3;
        while ($retries--) {
            try {
                $options['http']['header'][] = 'If-Modified-Since: '.$lastModifiedTime;
                $json = $this->rfs->getContents($this->endpoint, $filename, false, $options);
                if (304 === $this->rfs->findStatusCode($this->rfs->getLastHeaders())) {
                    return true;
                }

                return $this->parseJson($json, $filename, $cacheKey);
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(100000);
                    continue;
                }

                $this->switchToDegradedMode($e);

                return true;
            }
        }
    }

    private function parseJson($json, $filename, $cacheKey)
    {
        $data = JsonFile::parseJson($json, $filename);
        if (!empty($data['warning'])) {
            $this->io->writeError('<warning>Warning from '.$this->endpoint.': '.$data['warning'].'</warning>');
        }
        if (!empty($data['info'])) {
            $this->io->writeError('<info>Info from '.$this->endpoint.': '.$data['info'].'</info>');
        }

        if ($lastModifiedDate = $this->rfs->findHeaderValue($this->rfs->getLastHeaders(), 'last-modified')) {
            $data['last-modified'] = $lastModifiedDate;
            $json = json_encode($data);
        }
        $this->cache->write($cacheKey, $json);

        return $data;
    }

    private function switchToDegradedMode(\Exception $e)
    {
        if (!$this->degradedMode) {
            $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
            $this->io->writeError('<warning>'.$this->endpoint.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
        }
        $this->degradedMode = true;
    }
}
