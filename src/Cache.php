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

    public function setSymfonyRequire(string $symfonyRequire, IOInterface $io = null)
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

        $symfonyPackages = [];
        $symfonySymfony = $data['packages']['symfony/symfony'];

        foreach ($symfonySymfony as $version => $composerJson) {
            if ('dev-master' === $version) {
                $normalizedVersion = $this->versionParser->normalize($composerJson['extra']['branch-alias']['dev-master']);
            } else {
                $normalizedVersion = $composerJson['version_normalized'];
            }

            if ($this->symfonyConstraints->matches(new Constraint('==', $normalizedVersion))) {
                $symfonyPackages += $composerJson['replace'];
            } else {
                if (null !== $this->io) {
                    $this->io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</info>', $this->symfonyRequire));
                    $this->io = null;
                }
                unset($symfonySymfony[$version]);
            }
        }

        if (!$symfonySymfony) {
            // ignore requirements: their intersection with versions of symfony/symfony is empty
            return $data;
        }

        $data['packages']['symfony/symfony'] = $symfonySymfony;
        unset($symfonySymfony['dev-master']);

        foreach ($data['packages'] as $name => $versions) {
            if (!isset($symfonyPackages[$name]) || null === $devMasterAlias = $versions['dev-master']['extra']['branch-alias']['dev-master'] ?? null) {
                continue;
            }
            $devMaster = $versions['dev-master'];
            $versions = array_intersect_key($versions, $symfonySymfony);

            if ($this->symfonyConstraints->matches(new Constraint('==', $this->versionParser->normalize($devMasterAlias)))) {
                $versions['dev-master'] = $devMaster;
            }

            if ($versions) {
                $data['packages'][$name] = $versions;
            }
        }

        return $data;
    }
}
