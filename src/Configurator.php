<?php

namespace Symfony\Flex;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;

class Configurator
{
    private $composer;
    private $io;
    private $options;
    private $configurators;
    private $cache;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
        $this->configurators = array(
            'bundles' => Configurator\BundlesConfigurator::class,
            'composer-scripts' => Configurator\ComposerScriptsConfigurator::class,
            'copy-from-recipe' => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env' => Configurator\EnvConfigurator::class,
            'container' => Configurator\ContainerConfigurator::class,
            'makefile' => Configurator\MakefileConfigurator::class,
        );
    }

    public function install(Recipe $recipe)
    {
        foreach ($recipe->getManifest() as $key => $config) {
            $this->get($key)->configure($recipe, $config);
        }
    }

    public function unconfigure(Recipe $recipe)
    {
        foreach ($recipe->getManifest() as $key => $config) {
            $this->get($key)->unconfigure($recipe, $config);
        }
    }

    /**
     * @return Configurator\AbstractConfigurator
     */
    private function get($key)
    {
        if (!isset($this->configurators[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown configurator "%s".', $key));
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->configurators[$key];

        return $this->cache[$key] = new $class($this->composer, $this->io, $this->options);
    }
}
