<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Package;

use Composer\Factory;
use Composer\IO\NullIO;
use Symfony\Flex\Downloader;

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
        if (null === self::$cache) {
            self::initCache();
        }
    }

    public function resolve(array $packages = [])
    {
        $installs = [];
        $names = [];
        foreach ($packages as $package) {
            if (false !== strpos($package, '/')) {
                $installs[] = $package;
                continue;
            }

            if (false !== $ps = $this->lookupPack($package)) {
                foreach ($ps as $p) {
                    $installs[] = $p;
                }
                continue;
            }

            if (false !== $alternative = $this->lookup($package)) {
                $installs[] = $alternative;
                continue;
            }

            $installs[] = $package;
        }

        return $installs;
    }

    private function lookupPack($name)
    {
        $packs = $this->getPackPackages();
        if (!isset($packs[$name])) {
            return false;
        }

        $packages = [];
        foreach ($packs[$name] as $package) {
            $packages[] = $package;
        }

        return $packages;
    }

    private function lookup($name)
    {
        $map = self::$cache['map'];
        if (isset(self::$cache['synonyms'][$name])) {
            $name = self::$cache['synonyms'][$name];
        }

        if (!isset($map[$name])) {
            return false;
        }

        return $map[$name];
    }

    private function getPackPackages()
    {
        $packages = [];
        foreach (self::$cache['packs'] as $name => $pack) {
            $packages[$name] = $pack['packages'];
        }

        return $packages;
    }

    private function initCache()
    {
        self::$cache = $this->downloader->getContents('/packs.json');

        foreach (self::$cache['map'] as $name => $package) {
            self::$cache['map'][$name] = new Package($package[0], $package[1], $package[2]);
        }
        foreach (self::$cache['packs'] as $name => $config) {
            foreach (self::$cache['packs'][$name]['packages'] as $i => $package) {
                self::$cache['packs'][$name]['packages'][$i] = new Package($package[0], $package[1], $package[2]);
            }
        }
    }
}
