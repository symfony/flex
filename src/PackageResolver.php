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

use Composer\Package\Version\VersionParser;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class PackageResolver
{
    private static $cache;

    private $downloader;

    public function __construct(Downloader $downloader)
    {
        $this->downloader = $downloader;
    }

    public function resolve(array $packages = [])
    {
        $parser = new VersionParser();
        $installs = [];
        foreach ($parser->parseNameVersionPairs($packages) as $require) {
            if (false === strpos($require['name'], '/')) {
                if (null === self::$cache) {
                    self::$cache = $this->downloader->getContents('/aliases.json');
                }

                while (isset(self::$cache[$require['name']])) {
                    $require['name'] = self::$cache[$require['name']];
                }
            }

            $installs[] = $require['name'].$this->parseVersion($require['name'], isset($require['version']) ? $require['version'] : null);
        }

        return array_unique($installs);
    }

    private function parseVersion($package, $version)
    {
        return $version ? ':'.$version : '';
    }
}
