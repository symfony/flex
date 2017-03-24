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

use Composer\Factory;
use Composer\IO\NullIO;
use Symfony\Flex\Downloader;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class PackageResolver
{
    private static $cache;

    public function __construct(Downloader $downloader)
    {
        if (null === self::$cache) {
            self::$cache = $downloader->getContents('/aliases.json');
        }
    }

    public function resolve(array $packages = [])
    {
        $installs = [];
        foreach ($packages as $package) {
            if (false === strpos($package, '/')) {
                while (isset(self::$cache[$package])) {
                    $package = self::$cache[$package];
                }
            }

            $installs[] = $package;
        }

        return array_unique($installs);
    }

    private function lookup($name)
    {
        while (isset(self::$cache[$name])) {
            $name = self::$cache[$name];
        }

        return $name;
    }
}
