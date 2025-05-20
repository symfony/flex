<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Configurator;

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Marcin Morawski <marcin@morawskim.pl>
 */
class ComposerCommandsConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $scripts, Lock $lock, array $options = [])
    {
        $json = new JsonFile(Factory::getComposerFile());

        file_put_contents($json->getPath(), $this->configureScripts($scripts, $json));
    }

    public function unconfigure(Recipe $recipe, $scripts, Lock $lock)
    {
        $json = new JsonFile(Factory::getComposerFile());

        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        foreach ($scripts as $key => $command) {
            $manipulator->removeSubNode('scripts', $key);
        }

        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        $json = new JsonFile(Factory::getComposerFile());
        $jsonPath = $json->getPath();
        if (str_starts_with($jsonPath, $recipeUpdate->getRootDir())) {
            $jsonPath = substr($jsonPath, \strlen($recipeUpdate->getRootDir()));
        }
        $jsonPath = ltrim($jsonPath, '/\\');

        $recipeUpdate->setOriginalFile(
            $jsonPath,
            $this->configureScripts($originalConfig, $json)
        );
        $recipeUpdate->setNewFile(
            $jsonPath,
            $this->configureScripts($newConfig, $json)
        );
    }

    private function configureScripts(array $scripts, JsonFile $json): string
    {
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        foreach ($scripts as $cmdName => $script) {
            $manipulator->addSubNode('scripts', $cmdName, $script);
        }

        return $manipulator->getContents();
    }
}
