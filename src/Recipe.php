<?php

namespace Symfony\Flex;

use Composer\Package\Package;

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

    /**
     * @return Manifest
     */
    public function getManifest()
    {
        if (!isset($this->data['manifest'])) {
            throw new \LogicException(sprintf('Manifest is not available for recipe "%s".', $name));
        }

        return $this->data['manifest'];
    }

    public function getFiles()
    {
        if (!isset($this->data['files'])) {
            return array();
        }

        return $this->data['files'];
    }
}
