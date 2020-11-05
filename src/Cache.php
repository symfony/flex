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

use Composer\Cache as BaseCache;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Cache extends BaseCache
{
    private $versions;
    private $versionParser;
    private $symfonyRequire;
    private $rootConstraints = [];
    private $symfonyConstraints;
    private $downloader;
    private $io;

    public function setSymfonyRequire(string $symfonyRequire, RootPackageInterface $rootPackage, Downloader $downloader, IOInterface $io = null)
    {
        $this->versionParser = new VersionParser();
        $this->symfonyRequire = $symfonyRequire;
        $this->symfonyConstraints = $this->versionParser->parseConstraints($symfonyRequire);
        $this->downloader = $downloader;
        $this->io = $io;

        foreach ($rootPackage->getRequires() + $rootPackage->getDevRequires() as $name => $link) {
            $this->rootConstraints[$name] = $link->getConstraint();
        }
    }

    public function read($file)
    {
        $content = parent::read($file);

        if (0 === strpos($file, 'provider-symfony$') && \is_array($data = json_decode($content, true))) {
            $content = json_encode($this->removeLegacyTags($data));
        }

        return $content;
    }

    public function removeLegacyTags(array $data): array
    {
        if (!$this->symfonyConstraints || !isset($data['packages'])) {
            return $data;
        }

        foreach ($data['packages'] as $name => $versions) {
            if (!isset($this->getVersions()['splits'][$name])) {
                continue;
            }

            $rootConstraint = $this->rootConstraints[$name] ?? null;
            $rootVersions = [];

            foreach ($versions as $version => $composerJson) {
                if (null !== $alias = $composerJson['extra']['branch-alias'][$version] ?? null) {
                    $normalizedVersion = $this->versionParser->normalize($alias);
                } elseif (null === $normalizedVersion = $composerJson['version_normalized'] ?? null) {
                    continue;
                }

                $constraint = new Constraint('==', $normalizedVersion);

                if ($rootConstraint && $rootConstraint->matches($constraint)) {
                    $rootVersions[$version] = $composerJson;
                }

                if (!$this->symfonyConstraints->matches($constraint)) {
                    if (null !== $this->io) {
                        $this->io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</>', $this->symfonyRequire));
                        $this->io = null;
                    }
                    unset($versions[$version]);
                }
            }

            if ($rootConstraint && !array_intersect_key($rootVersions, $versions)) {
                $versions = $rootVersions;
            }

            $data['packages'][$name] = $versions;
        }

        if (null === $symfonySymfony = $data['packages']['symfony/symfony'] ?? null) {
            return $data;
        }

        foreach ($symfonySymfony as $version => $composerJson) {
            if (null !== $alias = $composerJson['extra']['branch-alias'][$version] ?? null) {
                $normalizedVersion = $this->versionParser->normalize($alias);
            } elseif (null === $normalizedVersion = $composerJson['version_normalized'] ?? null) {
                continue;
            }

            if (!$this->symfonyConstraints->matches(new Constraint('==', $normalizedVersion))) {
                unset($symfonySymfony[$version]);
            }
        }

        if ($symfonySymfony) {
            $data['packages']['symfony/symfony'] = $symfonySymfony;
        }

        return $data;
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
