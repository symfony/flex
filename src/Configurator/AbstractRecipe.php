<?php

namespace Symfony\Start\Configurator;

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

    public function configure(Recipe $recipe, $config);

    public function unconfigure(Recipe $recipe, $config);
}
