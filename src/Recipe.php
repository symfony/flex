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

use Composer\Package\Package;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Recipe
{
    private $package;
    private $name;
    private $data;

    public function __construct(Package $package, $name, $data)
    {
        $this->package = $package;
        $this->name = $name;
        $this->data = $data;
    }

    /**
     * @return Package
     */
    public function getPackage()
    {
        return $this->package;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getManifest()
    {
        if (!isset($this->data['manifest'])) {
            throw new \LogicException(sprintf('Manifest is not available for recipe "%s".', $name));
        }

        return $this->data['manifest'];
    }

    public function getFiles()
    {
        return $this->data['files'] ?? [];
    }
}
