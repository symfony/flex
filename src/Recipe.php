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

use Composer\Package\PackageInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Recipe
{
    private $package;
    private $name;
    private $job;
    private $data;

    public function __construct(PackageInterface $package, string $name, string $job, array $data)
    {
        $this->package = $package;
        $this->name = $name;
        $this->job = $job;
        $this->data = $data;
    }

    public function getPackage(): PackageInterface
    {
        return $this->package;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getManifest(): array
    {
        if (!isset($this->data['manifest'])) {
            throw new \LogicException(sprintf('Manifest is not available for recipe "%s".', $this->name));
        }

        return $this->data['manifest'];
    }

    public function getFiles(): array
    {
        return $this->data['files'] ?? [];
    }

    public function getOrigin(): string
    {
        return $this->data['origin'] ?? '';
    }

    public function isContrib(): bool
    {
        return $this->data['is_contrib'] ?? false;
    }

    // for BC, to be removed on 11/30/2017
    public function isNotInstallable(): bool
    {
        return $this->data['not_installable'] ?? false;
    }

    // for BC, to be removed on 11/30/2017
    public function isEmpty(): bool
    {
        return count($this->data) > 0;
    }
}
