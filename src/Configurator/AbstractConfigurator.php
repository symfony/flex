<?php

namespace Symfony\Start\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Symfony\Start\Options;
use Symfony\Start\Recipe;

abstract class AbstractConfigurator
{
    protected $composer;
    protected $io;
    protected $options;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
    }

    abstract public function configure(Recipe $recipe, $config);

    abstract public function unconfigure(Recipe $recipe, $config);
}
