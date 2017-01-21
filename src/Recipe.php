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

    public function getPackage()
    {
        return $this->package;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getData()
    {
        return $this->data;
    }
}
