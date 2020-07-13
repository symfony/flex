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
use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositorySet;
use Symfony\Flex\Unpack\Operation;
use Symfony\Flex\Unpack\Result;

class Unpacker
{
    private $composer;
    private $resolver;

    public function __construct(Composer $composer, PackageResolver $resolver)
    {
        $this->composer = $composer;
        $this->resolver = $resolver;
    }

    public function unpack(Operation $op): Result
    {
        $result = new Result();
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
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

            $versionSelector = null;
            $result->addUnpacked($pkg);
            foreach ($pkg->getRequires() as $link) {
                if ('php' === $link->getTarget()) {
                    continue;
                }

                $constraint = $link->getPrettyConstraint();
                $constraint = substr($this->resolver->parseVersion($link->getTarget(), $constraint, !$package['dev']), 1) ?: $constraint;

                if ('*' === $constraint && $subPkg = $localRepo->findPackage($link->getTarget(), '*')) {
                    if (null === $versionSelector) {
                        $pool = class_exists(RepositorySet::class) ? RepositorySet::class : Pool::class;
                        $pool = new $pool($this->composer->getPackage()->getMinimumStability(), $this->composer->getPackage()->getStabilityFlags());
                        $pool->addRepository(new CompositeRepository($this->composer->getRepositoryManager()->getRepositories()));
                        $versionSelector = new VersionSelector($pool);
                    }

                    $constraint = $versionSelector->findRecommendedRequireVersion($subPkg);
                }

                if (!$manipulator->addLink($package['dev'] ? 'require-dev' : 'require', $link->getTarget(), $constraint, $op->shouldSort())) {
                    throw new \RuntimeException(sprintf('Unable to unpack package "%s".', $link->getTarget()));
                }
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());

        return $result;
    }
}
