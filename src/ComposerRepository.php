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
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository as BaseComposerRepository;
use Composer\Util\RemoteFilesystem;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ComposerRepository extends BaseComposerRepository
{
    private $providerFiles;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, RemoteFilesystem $rfs = null)
    {
        parent::__construct($repoConfig, $io, $config, $eventDispatcher, $rfs);

        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
    }

    protected function loadProviderListings($data)
    {
        if (null !== $this->providerFiles) {
            parent::loadProviderListings($data);

            return;
        }

        $data = [$data];

        while ($data) {
            $this->providerFiles = [];
            foreach ($data as $data) {
                $this->loadProviderListings($data);
            }

            $loadingFiles = $this->providerFiles;
            $this->providerFiles = null;
            $data = [];
            $this->rfs->download($loadingFiles, function (...$args) use (&$data) {
                $data[] = $this->fetchFile(...$args);
            });
        }
    }

    protected function fetchFile($filename, $cacheKey = null, $sha256 = null, $storeLastModifiedTime = false)
    {
        if (null !== $this->providerFiles) {
            $this->providerFiles[] = [$filename, $cacheKey, $sha256, $storeLastModifiedTime];

            return [];
        }

        $data = parent::fetchFile($filename, $cacheKey, $sha256, $storeLastModifiedTime);

        if (0 === strpos($filename, 'http://packagist.org/p/symfony/') || 0 === strpos($filename, 'https://packagist.org/p/symfony/')) {
            $data = $this->cache->removeLegacyTags($data);
        }

        return $data;
    }
}
