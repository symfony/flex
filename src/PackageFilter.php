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

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class PackageFilter
{
    private $versions;
    private $versionParser;
    private $symfonyRequire;
    private $symfonyConstraints;
    private $downloader;
    private $io;

    public function __construct(IOInterface $io, string $symfonyRequire, Downloader $downloader)
    {
        $this->versionParser = new VersionParser();
        $this->symfonyRequire = $symfonyRequire;
        $this->symfonyConstraints = $this->versionParser->parseConstraints($symfonyRequire);
        $this->downloader = $downloader;
        $this->io = $io;
    }

    /**
     * @param PackageInterface[] $data
     *
     * @return PackageInterface[]
     */
    public function removeLegacyPackages(array $data): array
    {
        if (!$this->symfonyConstraints || !$data) {
            return $data;
        }

        $knownVersions = $this->getVersions();
        $filteredPackages = [];
        $symfonyPackages = [];
        $oneSymfony = false;
        foreach ($data as $package) {
            $name = $package->getName();
            $version = $package->getVersion();
            if ('symfony/symfony' !== $name && !isset($knownVersions['splits'][$name])) {
                $filteredPackages[] = $package;
                continue;
            }

            if (null !== $alias = $package->getExtra()['branch-alias'][$version] ?? null) {
                $version = $this->versionParser->normalize($alias);
            }

            if ($this->symfonyConstraints->matches(new Constraint('==', $version))) {
                $filteredPackages[] = $package;
                $oneSymfony = $oneSymfony || 'symfony/symfony' === $name;
                continue;
            }

            if ('symfony/symfony' === $name) {
                $symfonyPackages[] = $package;
            } elseif (null !== $this->io) {
                $this->io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</>', $this->symfonyRequire));
                $this->io = null;
            }
        }

        if ($symfonyPackages && !$oneSymfony) {
            $filteredPackages = array_merge($filteredPackages, $symfonyPackages);
        }

        return $filteredPackages;
    }

    private function getVersions(): array
    {
        if (null !== $this->versions) {
            return $this->versions;
        }

        $versions = $this->downloader->getVersions();
        $this->downloader = null;
        $okVersions = [];

        foreach ($versions['splits'] as $name => $vers) {
            foreach ($vers as $i => $v) {
                if (!isset($okVersions[$v])) {
                    $okVersions[$v] = false;

                    for ($j = 0; $j < 60; ++$j) {
                        if ($this->symfonyConstraints->matches(new Constraint('==', $v.'.'.$j.'.0'))) {
                            $okVersions[$v] = true;
                            break;
                        }
                    }
                }

                if (!$okVersions[$v]) {
                    unset($vers[$i]);
                }
            }

            if (!$vers || $vers === $versions['splits'][$name]) {
                unset($versions['splits'][$name]);
            }
        }

        return $this->versions = $versions;
    }
}
