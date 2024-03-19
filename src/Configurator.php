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
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Configurator
{
    private $configurators;
    private $postInstallConfigurators;
    private $cache;

    public function __construct(
        private Composer $composer,
        private IOInterface $io,
        private Options $options,
    ) {
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
            'dockerfile' => Configurator\DockerfileConfigurator::class,
            'docker-compose' => Configurator\DockerComposeConfigurator::class,
        ];
        $this->postInstallConfigurators = [
            'add-lines' => Configurator\AddLinesConfigurator::class,
        ];
    }

    public function install(Recipe $recipe, Lock $lock, array $options = [])
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $lock, $options);
            }
        }
    }

    /**
     * Run after all recipes have been installed to run post-install configurators.
     */
    public function postInstall(Recipe $recipe, Lock $lock, array $options = [])
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->postInstallConfigurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $lock, $options);
            }
        }
    }

    public function populateUpdate(RecipeUpdate $recipeUpdate): void
    {
        $originalManifest = $recipeUpdate->getOriginalRecipe()->getManifest();
        $newManifest = $recipeUpdate->getNewRecipe()->getManifest();
        $allConfigurators = array_merge($this->configurators, $this->postInstallConfigurators);
        foreach (array_keys($allConfigurators) as $key) {
            if (!isset($originalManifest[$key]) && !isset($newManifest[$key])) {
                continue;
            }

            $this->get($key)->update($recipeUpdate, $originalManifest[$key] ?? [], $newManifest[$key] ?? []);
        }
    }

    public function unconfigure(Recipe $recipe, Lock $lock)
    {
        $manifest = $recipe->getManifest();

        $allConfigurators = array_merge($this->configurators, $this->postInstallConfigurators);

        foreach (array_keys($allConfigurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->unconfigure($recipe, $manifest[$key], $lock);
            }
        }
    }

    private function get($key): AbstractConfigurator
    {
        if (!isset($this->configurators[$key]) && !isset($this->postInstallConfigurators[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown configurator "%s".', $key));
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = isset($this->configurators[$key]) ? $this->configurators[$key] : $this->postInstallConfigurators[$key];

        return $this->cache[$key] = new $class($this->composer, $this->io, $this->options);
    }
}
