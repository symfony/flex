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
    private $dryRun;
    private $jsonPath;
    private $manipulator;

    public function __construct(Composer $composer, PackageResolver $resolver, bool $dryRun)
    {
        $this->composer = $composer;
        $this->resolver = $resolver;
        $this->dryRun = $dryRun;
    }

    public function unpack(Operation $op, Result $result = null): Result
    {
        if (null === $result) {
            $result = new Result();
            $this->jsonPath = Factory::getComposerFile();
            $this->manipulator = new JsonManipulator(file_get_contents($this->jsonPath));
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
                        $result = $this->unpack($subOp, $result);
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

                if (!$this->manipulator->addLink($package['dev'] ? 'require-dev' : 'require', $link->getTarget(), $constraint, $op->shouldSort())) {
                    throw new \RuntimeException(sprintf('Unable to unpack package "%s".', $link->getTarget()));
                }
            }
        }

        if (!$this->dryRun && 1 === \func_num_args()) {
            file_put_contents($this->jsonPath, $this->manipulator->getContents());
        }

        return $result;
    }
}
