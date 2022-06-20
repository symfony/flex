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

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class GitignoreConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $vars, Lock $lock, array $options = []): bool
    {
        if (!$this->shouldConfigure($this->composer, $this->io, $recipe)) {
            return false;
        }

        $this->write('Adding entries to .gitignore');

        $this->configureGitignore($recipe, $vars, $options['force'] ?? false);

        return true;
    }

    public function unconfigure(Recipe $recipe, $vars, Lock $lock)
    {
        $file = $this->options->get('root-dir').'/.gitignore';
        if (!file_exists($file)) {
            return;
        }

        $contents = preg_replace(sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->getName(), $recipe->getName(), "\n"), "\n", file_get_contents($file), -1, $count);
        if (!$count) {
            return;
        }

        $this->write('Removing entries in .gitignore');
        file_put_contents($file, ltrim($contents, "\r\n"));
    }

    public function configureKey(): string
    {
        return 'gitignore';
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        if (!$this->shouldConfigure($this->composer, $this->io, $recipeUpdate->getNewRecipe())) {
            return;
        }

        $recipeUpdate->setOriginalFile(
            '.gitignore',
            $this->getContentsAfterApplyingRecipe($recipeUpdate->getRootDir(), $recipeUpdate->getOriginalRecipe(), $originalConfig)
        );

        $recipeUpdate->setNewFile(
            '.gitignore',
            $this->getContentsAfterApplyingRecipe($recipeUpdate->getRootDir(), $recipeUpdate->getNewRecipe(), $newConfig)
        );
    }

    private function configureGitignore(Recipe $recipe, array $vars, bool $update)
    {
        $gitignore = $this->options->get('root-dir').'/.gitignore';
        if (!$update && $this->isFileMarked($recipe, $gitignore)) {
            return;
        }

        $data = '';
        foreach ($vars as $value) {
            $value = $this->options->expandTargetDir($value);
            $data .= "$value\n";
        }
        $data = "\n".ltrim($this->markData($recipe, $data), "\r\n");

        if (!$this->updateData($gitignore, $data)) {
            file_put_contents($gitignore, $data, \FILE_APPEND);
        }
    }

    private function getContentsAfterApplyingRecipe(string $rootDir, Recipe $recipe, $vars): ?string
    {
        if (0 === \count($vars)) {
            return null;
        }

        $file = $rootDir.'/.gitignore';
        $originalContents = file_exists($file) ? file_get_contents($file) : null;

        $this->configureGitignore(
            $recipe,
            $vars,
            true
        );

        $updatedContents = file_exists($file) ? file_get_contents($file) : null;

        if (null === $originalContents) {
            if (file_exists($file)) {
                unlink($file);
            }
        } else {
            file_put_contents($file, $originalContents);
        }

        return $updatedContents;
    }
}
