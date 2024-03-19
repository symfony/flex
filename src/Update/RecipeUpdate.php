<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Update;

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;

class RecipeUpdate
{
    /** @var string[] */
    private $originalRecipeFiles = [];
    /** @var string[] */
    private $newRecipeFiles = [];
    private $copyFromPackagePaths = [];

    public function __construct(
        private Recipe $originalRecipe,
        private Recipe $newRecipe,
        private Lock $lock,
        private string $rootDir,
    ) {
    }

    public function getOriginalRecipe(): Recipe
    {
        return $this->originalRecipe;
    }

    public function getNewRecipe(): Recipe
    {
        return $this->newRecipe;
    }

    public function getLock(): Lock
    {
        return $this->lock;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getPackageName(): string
    {
        return $this->originalRecipe->getName();
    }

    public function setOriginalFile(string $filename, ?string $contents): void
    {
        $this->originalRecipeFiles[$filename] = $contents;
    }

    public function setNewFile(string $filename, ?string $contents): void
    {
        $this->newRecipeFiles[$filename] = $contents;
    }

    public function addOriginalFiles(array $files)
    {
        foreach ($files as $file => $contents) {
            if (null === $contents) {
                continue;
            }

            $this->setOriginalFile($file, $contents);
        }
    }

    public function addNewFiles(array $files)
    {
        foreach ($files as $file => $contents) {
            if (null === $contents) {
                continue;
            }

            $this->setNewFile($file, $contents);
        }
    }

    public function getOriginalFiles(): array
    {
        return $this->originalRecipeFiles;
    }

    public function getNewFiles(): array
    {
        return $this->newRecipeFiles;
    }

    public function getCopyFromPackagePaths(): array
    {
        return $this->copyFromPackagePaths;
    }

    public function addCopyFromPackagePath(string $source, string $target)
    {
        $this->copyFromPackagePaths[$source] = $target;
    }
}
