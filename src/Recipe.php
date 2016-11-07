<?php

namespace Symfony\Start;

use Composer\Package\Package;

class Recipe
{
    private $package;
    private $name;
    private $dir;

    public function __construct(Package $package, $name, $dir)
    {
        $this->package = $package;
        $this->name = $name;
        $this->recipeDir = $recipeDir;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDir()
    {
        return $this->dir;
    }
}
