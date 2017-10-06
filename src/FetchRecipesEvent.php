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

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\Event;
use Composer\Package\PackageInterface;

/**
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
class FetchRecipesEvent extends Event
{
    private $operations;

    private $manifests = [];

    /**
     * @param string               $name       The event name
     * @param OperationInterface[] $operations The operations of Composer
     * @param array                $manifests  The manifests
     */
    public function __construct(string $name, array $operations, array $manifests)
    {
        parent::__construct($name);
        $this->operations = $operations;
        $this->manifests = $manifests;
    }

    /**
     * @return OperationInterface[]
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function addManifest(PackageInterface $package, array $manifest)
    {
        $name = $package->getName();
        $manifest['is_contrib'] = true;
        if (!isset($manifest['origin'])) {
            $manifest['origin'] = sprintf('%s:%s@composer-plugin recipe', $name, $package->getPrettyVersion());
        }
        $this->manifests[$name] = $manifest;
    }

    public function hasManifest(string $name): bool
    {
        return isset($this->manifests[$name]);
    }

    public function getManifest(string $name): array
    {
        return $this->manifests[$name] ?? [];
    }

    public function getManifests(): array
    {
        return $this->manifests;
    }
}
