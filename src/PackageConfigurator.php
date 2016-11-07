<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;

class PackageConfigurator
{
    private $composer;
    private $io;
    private $options;
    private $map;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;

        $map = array(
            'bundles' => Configurator\BundlesConfigurator::class,
            'composer-scripts' => Configurator\ComposerScriptsConfigurator::class,
            'copy-from-recipe' => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env' => Configurator\EnvConfigurator::class,
            'parameters' => Configurator\ParametersConfigurator::class,
        );
        foreach ($map as $key => $class) {
            $this->map[$key] = new $class($composer, $io, $this->options);
        }
    }

    public function configure(Recipe $recipe)
    {
        $manifest = json_decode(file_get_contents($recipe->getDir().'/manifest.ini'));
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->configure($recipe, $config);
        }
    }

    public function unconfigure(Recipe $recipe)
    {
        $manifest = json_decode(file_get_contents($recipe->getDir().'/manifest.ini'));
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->unconfigure($recipe, $config);
        }
    }
}
