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
    private $downloader;

    public function __construct(Downloader $downloader)
    {
        $this->downloader = $downloader;
    }

    public function resolve(array $arguments = [], bool $isRequire = false): array
    {
        $versionParser = new VersionParser();
        if (null === self::$aliases) {
            self::$aliases = $this->downloader->get('/aliases.json')->getBody();
        }

        // first pass split on ':', '=' or ' ' to separate package names and versions
        $searchInfo = [];
        foreach ($arguments as $argument) {
            if (
                (false !== $pos = strpos($argument, ':')) ||
                (false !== $pos = strpos($argument, '=')) ||
                (false !== $pos = strpos($argument, ' '))
            ) {
                $name = substr($argument, 0, $pos);
                $version = substr($argument, $pos + 1);
                $searchInfo[$name] = $version;
            } else {
                $searchInfo[$argument] = null;
            }
        }

        // second pass to resolve package names
        $packages = [];
        $i = 0;
        foreach ($searchInfo as $name => $version) {
            $version = self::$aliases[$version] ?? $version;
            $name = self::$aliases[$name] ?? $name;
            $name .= ($version ? ":$version" : '');

            if (
                false === strpos($name, '/') &&
                !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name) &&
                !\in_array($version, ['mirrors', 'nothing'])
            ) {
                // is it a version or an alias that does not exist?
                try {
                    $versionParser->parseConstraints($name);
                } catch (\UnexpectedValueException $e) {
                    // is it a special Symfony version?
                    if (!\in_array($version, self::$SYMFONY_VERSIONS, true)) {
                        $this->throwAlternatives($name, $i);
                    }
                }
            }

            $packages[] = $name;
            ++$i;
        }

        // third pass to resolve versions
        $requires = [];
        foreach ($versionParser->parseNameVersionPairs($packages) as $package) {
            $requires[] = $package['name'].$this->parseVersion($package['name'], $package['version'] ?? '', $isRequire);
        }

        return array_unique($requires);
    }

    public function parseVersion(string $package, string $version, bool $isRequire): string
    {
        if (0 !== strpos($package, 'symfony/')) {
            return $version ? ':'.$version : '';
        }

        $versions = $this->downloader->getVersions();

        if (!isset($versions['splits'][$package])) {
            return $version ? ':'.$version : '';
        }

        if (!$version || '*' === $version) {
            try {
                $config = @json_decode(file_get_contents(Factory::getComposerFile()), true);
            } finally {
                if (!$isRequire || !(isset($config['extra']['symfony']['require']) || isset($config['require']['symfony/framework-bundle']))) {
                    return '';
                }
            }
            $version = $config['extra']['symfony']['require'] ?? $config['require']['symfony/framework-bundle'];
        } elseif ('next' === $version) {
            $version = '^'.$versions[$version].'@dev';
        } elseif (\in_array($version, self::$SYMFONY_VERSIONS, true)) {
            $version = '^'.$versions[$version];
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
            if ($lev <= \strlen($argument) / 3 || false !== strpos($alias, $argument)) {
                $alternatives[$package][] = $alias;
            }
        }

        // First position can only be a package name, not a version
        if ($alternatives || 0 === $position) {
            $message = sprintf('"%s" is not a valid alias.', $argument);
            if ($alternatives) {
                if (1 === \count($alternatives)) {
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
