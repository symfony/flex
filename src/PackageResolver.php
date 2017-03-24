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

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class PackageResolver
{
    private static $cache;

    private $downloader

    public function __construct(Downloader $downloader)
    {
        $this->downloader = $downloader;
    }

    public function resolve(array $packages = [])
    {
        $installs = [];
        foreach ($packages as $package) {
            if (false === strpos($package, '/')) {
                if (null === self::$cache) {
                    self::$cache = $this->downloader->getContents('/aliases.json');
                }

                while (isset(self::$cache[$package])) {
                    $package = self::$cache[$package];
                }
            }

            $installs[] = $package;
        }

        return array_unique($installs);
    }
}
