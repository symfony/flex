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
    private const ENDPOINT = 'https://symfony.sh';

    private $io;
    private $sess;
    private $cache;
    private $rfs;
    private $degradedMode = false;
    private $endpoint;
    private $flexId;
    private $allowContrib = false;

    public function __construct(Composer $composer, IoInterface $io)
    {
        $this->io = $io;
        $config = $composer->getConfig();
        $this->endpoint = rtrim(getenv('SYMFONY_ENDPOINT') ?: self::ENDPOINT, '/');
        $this->rfs = Factory::createRemoteFilesystem($io, $config);
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->endpoint));
        $this->sess = bin2hex(random_bytes(16));
    }

    public function setFlexId(?string $id): void
    {
        $this->flexId = $id;
    }

    public function allowContrib(bool $allow): void
    {
        $this->allowContrib = $allow;
    }

    /**
     * Decodes a JSON HTTP response body.
     *
     * @param string $path    The path to get on the server
     * @param array  $headers An array of HTTP headers
     */
    public function get(string $path, array $headers = []): Response
    {
        $headers[] = 'Package-Session: '.$this->sess;
        $url = $this->endpoint.'/'.ltrim($path, '/');
        $cacheKey = ltrim($path, '/');

        try {
            if ($contents = $this->cache->read($cacheKey)) {
                $cachedResponse = Response::fromJson(json_decode($contents, true));
                if ($lastModified = $cachedResponse->getHeader('last-modified')) {
                    $response = $this->fetchFileIfLastModified($url, $cacheKey, $lastModified, $headers);
                    if (304 === $response->getStatusCode()) {
                        $response = new Response($cachedResponse->getBody(), $response->getOrigHeaders(), 304);
                    }

                    return $response;
                }
            }

            return $this->fetchFile($url, $cacheKey, $headers);
        } catch (TransportException $e) {
            if (404 === $e->getStatusCode()) {
                return new Response($e->getResponse(), $e->getHeaders(), 404);
            }

            throw $e;
        }
    }

    private function fetchFile(string $url, string $cacheKey, iterable $headers): Response
    {
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if ($contents = $this->cache->read($cacheKey)) {
                    $this->switchToDegradedMode($e, $url);

                    return Response::fromJson(JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey));
                }

                throw $e;
            }
        }
    }

    private function fetchFileIfLastModified(string $url, string $cacheKey, string $lastModifiedTime, iterable $headers): Response
    {
        $headers[] = 'If-Modified-Since: '.$lastModifiedTime;
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);
                if (304 === $this->rfs->findStatusCode($this->rfs->getLastHeaders())) {
                    return new Response('', $this->rfs->getLastHeaders(), 304);
                }

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                $this->switchToDegradedMode($e, $url);

                return new Response('', [], 304);
            }
        }
    }

    private function parseJson(string $json, string $url, string $cacheKey): Response
    {
        $data = JsonFile::parseJson($json, $url);
        if (!empty($data['warning'])) {
            $this->io->writeError('<warning>Warning from '.$url.': '.$data['warning'].'</warning>');
        }
        if (!empty($data['info'])) {
            $this->io->writeError('<info>Info from '.$url.': '.$data['info'].'</info>');
        }

        $response = new Response($data, $this->rfs->getLastHeaders());
        if (null !== $response->getHeader('last-modified')) {
            $this->cache->write($cacheKey, json_encode($response));
        }

        return $response;
    }

    private function switchToDegradedMode(\Exception $e, string $url): void
    {
        if (!$this->degradedMode) {
            $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
            $this->io->writeError('<warning>'.$url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
        }
        $this->degradedMode = true;
    }

    private function getOptions(array $headers): array 
    {
        $options = ['http' => ['header' => $headers]];

        if ($this->flexId) {
            $options['http']['header'][] = 'Project: '.$this->flexId;
        }

        if ($this->allowContrib) {
            $options['http']['header'][] = 'Allow-Contrib: 1';
        }

        return $options;
    }
}
