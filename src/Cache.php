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
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Cache extends BaseCache
{
    private $versionParser;
    private $symfonyRequire;
    private $symfonyConstraints;
    private $io;

    public function setSymfonyRequire(string $symfonyRequire, IOInterface $io)
    {
        $this->versionParser = new VersionParser();
        $this->symfonyRequire = $symfonyRequire;
        $this->symfonyConstraints = $this->versionParser->parseConstraints($symfonyRequire);
        $this->io = $io;
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
        if (!$this->symfonyConstraints || !isset($data['packages']['symfony/symfony'])) {
            return $data;
        }
        $symfonyVersions = $data['packages']['symfony/symfony'];

        foreach ($data['packages'] as $name => $versions) {
            foreach ($versions as $version => $package) {
                if ('symfony/symfony' !== $name && 'self.version' !== ($symfonyVersions[preg_replace('/^(\d++\.\d++)\..*/', '$1.x-dev', $version)]['replace'][$name] ?? null)) {
                    continue;
                }
                $normalizedVersion = $package['extra']['branch-alias'][$version] ?? null;
                $normalizedVersion = $normalizedVersion ? $this->versionParser->normalize($normalizedVersion) : $package['version_normalized'];
                $provider = new Constraint('==', $normalizedVersion);

                if (!$this->symfonyConstraints->matches($provider)) {
                    if ($this->io) {
                        $this->io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</info>', $this->symfonyRequire));
                        $this->io = null;
                    }
                    unset($data['packages'][$name][$version]);
                }
            }
        }

        return $data;
    }
}
