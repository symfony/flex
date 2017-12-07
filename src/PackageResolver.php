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
use Composer\Package\Version\VersionParser;
use Composer\Repository\PlatformRepository;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class PackageResolver
{
    private static $SYMFONY_VERSIONS = ['lts', 'previous', 'stable', 'next'];
    private static $aliases;
    private static $versions;
    private $downloader;

    public function __construct(Downloader $downloader)
    {
        $this->downloader = $downloader;
    }

    public function resolve(array $arguments = [], bool $isRequire = false): array
    {
        $versionParser = new VersionParser();

        // first pass split on : and = to separate package names and versions
        $explodedArguments = [];
        foreach ($arguments as $argument) {
            if ((false !== $pos = strpos($argument, ':')) || (false !== $pos = strpos($argument, '='))) {
                $explodedArguments[] = substr($argument, 0, $pos);
                $explodedArguments[] = substr($argument, $pos + 1);
            } else {
                $explodedArguments[] = $argument;
            }
        }

        // second pass to resolve package names
        $packages = [];
        foreach ($explodedArguments as $i => $argument) {
            if (false === strpos($argument, '/') && !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $argument)) {
                if (null === self::$aliases) {
                    self::$aliases = $this->downloader->get('/aliases.json')->getBody();
                }

                if (isset(self::$aliases[$argument])) {
                    $argument = self::$aliases[$argument];
                } else {
                    // is it a version or an alias that does not exist?
                    try {
                        $versionParser->parseConstraints($argument);
                    } catch (\UnexpectedValueException $e) {
                        // is it a special Symfony version?
                        if (!in_array($argument, self::$SYMFONY_VERSIONS, true)) {
                            $this->throwAlternatives($argument, $i);
                        }
                    }
                }
            }

            $packages[] = $argument;
        }

        // third pass to resolve versions
        $requires = [];
        foreach ($versionParser->parseNameVersionPairs($packages) as $package) {
            $requires[] = $package['name'].$this->parseVersion($package['name'], $package['version'] ?? '', $isRequire);
        }

        return array_unique($requires);
    }

    private function parseVersion(string $package, string $version, bool $isRequire): string
    {
        if (0 !== strpos($package, 'symfony/')) {
            return $version ? ':'.$version : '';
        }

        if (null === self::$versions) {
            self::$versions = $this->downloader->get('/versions.json')->getBody();
        }

        if (!isset(self::$versions['splits'][$package])) {
            return $version ? ':'.$version : '';
        }

        if (!$version) {
            try {
                $config = @json_decode(file_get_contents(Factory::getComposerFile()), true);
            } finally {
                if (!$isRequire || !isset($config['require']['symfony/framework-bundle'])) {
                    return '';
                }
            }
            $version = $config['require']['symfony/framework-bundle'];
        } elseif ('next' === $version) {
            $version = '^'.self::$versions[$version].'@dev';
        } elseif (in_array($version, self::$SYMFONY_VERSIONS, true)) {
            $version = '^'.self::$versions[$version];
        }

        return ':'.$version;
    }

    /**
     * @throws \UnexpectedValueException
     */
    private function throwAlternatives(string $argument, int $position)
    {
        $alternatives = [];
        foreach (self::$aliases as $alias => $package) {
            $lev = levenshtein($argument, $alias);
            if ($lev <= strlen($argument) / 3 || false !== strpos($alias, $argument)) {
                $alternatives[$package][] = $alias;
            }
        }

        // First position can only be a package name, not a version
        if ($alternatives || 0 === $position) {
            $message = sprintf('"%s" is not a valid alias.', $argument);
            if ($alternatives) {
                if (1 === count($alternatives)) {
                    $message .= " Did you mean this:\n";
                } else {
                    $message .= " Did you mean one of these:\n";
                }
                foreach ($alternatives as $package => $aliases) {
                    $message .= sprintf("  \"%s\", supported aliases: \"%s\"\n", $package, implode('", "', $aliases));
                }
            }
        } else {
            $message = sprintf('Could not parse version constraint "%s".', $argument);
        }

        throw new \UnexpectedValueException($message);
    }
}
