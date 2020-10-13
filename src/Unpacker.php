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

use Composer\Composer;
use Composer\Config\JsonConfigSource;
use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Locker;
use Composer\Package\Version\VersionSelector;
use Composer\Plugin\PluginInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\VersionParser;
use Symfony\Flex\Unpack\Operation;
use Symfony\Flex\Unpack\Result;

class Unpacker
{
    private $composer;
    private $resolver;
    private $dryRun;
    private $versionParser;

    public function __construct(Composer $composer, PackageResolver $resolver, bool $dryRun)
    {
        $this->composer = $composer;
        $this->resolver = $resolver;
        $this->dryRun = $dryRun;
        $this->versionParser = new VersionParser();
    }

    public function unpack(Operation $op, Result $result = null, &$links = []): Result
    {
        if (null === $result) {
            $result = new Result();
        }

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($op->getPackages() as $package) {
            $pkg = $localRepo->findPackage($package['name'], $package['version'] ?: '*');
            $pkg = $pkg ?? $this->composer->getRepositoryManager()->findPackage($package['name'], $package['version'] ?: '*');

            // not unpackable or no --unpack flag or empty packs (markers)
            if (
                null === $pkg ||
                'symfony-pack' !== $pkg->getType() ||
                !$op->shouldUnpack() ||
                0 === \count($pkg->getRequires()) + \count($pkg->getDevRequires())
            ) {
                $result->addRequired($package['name'].($package['version'] ? ':'.$package['version'] : ''));

                continue;
            }

            if (!$result->addUnpacked($pkg)) {
                continue;
            }

            $versionSelector = null;
            foreach ($pkg->getRequires() as $link) {
                if ('php' === $link->getTarget()) {
                    continue;
                }

                $constraint = $link->getPrettyConstraint();
                $constraint = substr($this->resolver->parseVersion($link->getTarget(), $constraint, !$package['dev']), 1) ?: $constraint;

                if ($subPkg = $localRepo->findPackage($link->getTarget(), '*')) {
                    if ('symfony-pack' === $subPkg->getType()) {
                        $subOp = new Operation(true, $op->shouldSort());
                        $subOp->addPackage($subPkg->getName(), $constraint, $package['dev']);
                        $result = $this->unpack($subOp, $result, $links);
                        continue;
                    }

                    if ('*' === $constraint) {
                        if (null === $versionSelector) {
                            $pool = class_exists(RepositorySet::class) ? RepositorySet::class : Pool::class;
                            $pool = new $pool($this->composer->getPackage()->getMinimumStability(), $this->composer->getPackage()->getStabilityFlags());
                            $pool->addRepository(new CompositeRepository($this->composer->getRepositoryManager()->getRepositories()));
                            $versionSelector = new VersionSelector($pool);
                        }

                        $constraint = $versionSelector->findRecommendedRequireVersion($subPkg);
                    }
                }

                $linkName = $link->getTarget();
                $linkType = $package['dev'] ? 'require-dev' : 'require';
                $constraint = $this->versionParser->parseConstraints($constraint);

                if (isset($links[$linkName])) {
                    $links[$linkName]['constraints'][] = $constraint;
                    if ('require' === $linkType) {
                        $links[$linkName]['type'] = 'require';
                    }
                } else {
                    $links[$linkName] = [
                        'type' => $linkType,
                        'name' => $linkName,
                        'constraints' => [$constraint],
                    ];
                }
            }
        }

        if ($this->dryRun || 1 < \func_num_args()) {
            return $result;
        }

        $jsonPath = Factory::getComposerFile();
        $jsonContent = file_get_contents($jsonPath);
        $jsonStored = json_decode($jsonContent, true);
        $jsonManipulator = new JsonManipulator($jsonContent);

        foreach ($links as $link) {
            // nothing to do, package is already present in the "require" section
            if (isset($jsonStored['require'][$link['name']])) {
                continue;
            }

            if (isset($jsonStored['require-dev'][$link['name']])) {
                // nothing to do, package is already present in the "require-dev" section
                if ('require-dev' === $link['type']) {
                    continue;
                }

                // removes package from "require-dev", because it will be moved to "require"
                // save stored constraint
                $link['constraints'][] = $this->versionParser->parseConstraints($jsonStored['require-dev'][$link['name']]);
                $jsonManipulator->removeSubNode('require-dev', $link['name']);
            }

            $constraint = end($link['constraints']);

            if (!$jsonManipulator->addLink($link['type'], $link['name'], $constraint->getPrettyString(), $op->shouldSort())) {
                throw new \RuntimeException(sprintf('Unable to unpack package "%s".', $link['name']));
            }
        }

        file_put_contents($jsonPath, $jsonManipulator->getContents());

        return $result;
    }

    public function updateLock(Result $result, IOInterface $io): void
    {
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonConfigSource($json);
        $locker = $this->composer->getLocker();
        $lockData = $locker->getLockData();

        foreach ($result->getUnpacked() as $package) {
            $manipulator->removeLink('require-dev', $package->getName());
            foreach ($lockData['packages-dev'] as $i => $pkg) {
                if ($package->getName() === $pkg['name']) {
                    unset($lockData['packages-dev'][$i]);
                }
            }
            $manipulator->removeLink('require', $package->getName());
            foreach ($lockData['packages'] as $i => $pkg) {
                if ($package->getName() === $pkg['name']) {
                    unset($lockData['packages'][$i]);
                }
            }
        }
        $jsonContent = file_get_contents($json->getPath());
        $lockData['packages'] = array_values($lockData['packages']);
        $lockData['packages-dev'] = array_values($lockData['packages-dev']);
        $lockData['content-hash'] = Locker::getContentHash($jsonContent);
        $lockFile = new JsonFile(substr($json->getPath(), 0, -4).'lock', null, $io);

        if (!$this->dryRun) {
            $lockFile->write($lockData);
        }

        // force removal of files under vendor/
        if (version_compare('2.0.0', PluginInterface::PLUGIN_API_VERSION, '>')) {
            $locker = new Locker($io, $lockFile, $this->composer->getRepositoryManager(), $this->composer->getInstallationManager(), $jsonContent);
        } else {
            $locker = new Locker($io, $lockFile, $this->composer->getInstallationManager(), $jsonContent);
        }
        $this->composer->setLocker($locker);
    }
}
