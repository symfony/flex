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

use Composer\Composer;
use Composer\IO\IOInterface;
use Symfony\Flex\Configurator\AbstractConfigurator;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
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
        // ordered list of configurators
        $this->configurators = [
            'bundles' => Configurator\BundlesConfigurator::class,
            'copy-from-recipe' => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env' => Configurator\EnvConfigurator::class,
            'container' => Configurator\ContainerConfigurator::class,
            'makefile' => Configurator\MakefileConfigurator::class,
            'composer-scripts' => Configurator\ComposerScriptsConfigurator::class,
            'gitignore' => Configurator\GitignoreConfigurator::class,
        ];
    }

    public function install(Recipe $recipe)
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key]);
            }
        }
    }

    public function unconfigure(Recipe $recipe)
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->unconfigure($recipe, $manifest[$key]);
            }
        }
    }

    public function add(string $name, string $class)
    {
        if (false === strpos($name, '/')) {
            throw new \InvalidArgumentException(sprintf('Flex configurator name "%s" must be prefixed with the vendor name, ex. "foo/custom-configurator".', $name));
        }

        if (isset($this->configurators[$name])) {
            throw new \InvalidArgumentException(sprintf('Flex configurator with the name "%s" already exists.', $name));
        }

        if (!is_subclass_of($class, AbstractConfigurator::class)) {
            throw new \InvalidArgumentException(sprintf('Flex configurator class "%s" must extend the class "%s".', $class, AbstractConfigurator::class));
        }

        $this->configurators[$name] = $class;
    }

    private function get($key): AbstractConfigurator
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
