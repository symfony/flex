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
class CopyFromRecipeConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = [])
    {
        $this->write('Copying files from recipe');
        $options = array_merge($this->options->toArray(), $options);

        $lock->add($recipe->getName(), ['files' => $this->copyFiles($config, $recipe->getFiles(), $options)]);
    }

    public function unconfigure(Recipe $recipe, $config, Lock $lock)
    {
        $this->write('Removing files from recipe');
        $rootDir = $this->options->get('root-dir');

        foreach ($this->options->getRemovableFiles($recipe, $lock) as $file) {
            if ('.git' !== $file) { // never remove the main Git directory, even if it was created by a recipe
                $this->removeFile($this->path->concatenate([$rootDir, $file]));
            }
        }
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        foreach ($recipeUpdate->getOriginalRecipe()->getFiles() as $filename => $data) {
            $filename = $this->resolveTargetFolder($filename, $originalConfig);
            $recipeUpdate->setOriginalFile($filename, $data['contents']);
        }

        $files = [];
        foreach ($recipeUpdate->getNewRecipe()->getFiles() as $filename => $data) {
            $filename = $this->resolveTargetFolder($filename, $newConfig);
            $recipeUpdate->setNewFile($filename, $data['contents']);

            $files[] = $this->getLocalFilePath($recipeUpdate->getRootDir(), $filename);
        }

        $recipeUpdate->getLock()->add($recipeUpdate->getPackageName(), ['files' => $files]);
    }

    /**
     * @param array<string, string> $config
     */
    private function resolveTargetFolder(string $path, array $config): string
    {
        foreach ($config as $key => $target) {
            if (str_starts_with($path, $key)) {
                return $this->options->expandTargetDir($target).substr($path, \strlen($key));
            }
        }

        return $path;
    }

    private function copyFiles(array $manifest, array $files, array $options): array
    {
        $copiedFiles = [];
        $to = $options['root-dir'] ?? '.';

        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === substr($source, -1)) {
                $copiedFiles = array_merge(
                    $copiedFiles,
                    $this->copyDir($source, $this->path->concatenate([$to, $target]), $files, $options)
                );
            } else {
                $copiedFiles[] = $this->copyFile($this->path->concatenate([$to, $target]), $files[$source]['contents'], $files[$source]['executable'], $options);
            }
        }

        return $copiedFiles;
    }

    private function copyDir(string $source, string $target, array $files, array $options): array
    {
        $copiedFiles = [];
        foreach ($files as $file => $data) {
            if (str_starts_with($file, $source)) {
                $file = $this->path->concatenate([$target, substr($file, \strlen($source))]);
                $copiedFiles[] = $this->copyFile($file, $data['contents'], $data['executable'], $options);
            }
        }

        return $copiedFiles;
    }

    private function copyFile(string $to, string $contents, bool $executable, array $options): string
    {
        $basePath = $options['root-dir'] ?? '.';
        $copiedFile = $this->getLocalFilePath($basePath, $to);

        if (!$this->options->shouldWriteFile($to, $options['force'] ?? false, $options['assumeYesForPrompts'] ?? false)) {
            return $copiedFile;
        }

        if (!is_dir(\dirname($to))) {
            mkdir(\dirname($to), 0777, true);
        }

        file_put_contents($to, $this->options->expandTargetDir($contents));
        if ($executable) {
            @chmod($to, fileperms($to) | 0111);
        }

        $this->write(\sprintf('  Created <fg=green>"%s"</>', $this->path->relativize($to)));

        return $copiedFile;
    }

    private function removeFile(string $to)
    {
        if (!file_exists($to)) {
            return;
        }

        @unlink($to);
        $this->write(\sprintf('  Removed <fg=green>"%s"</>', $this->path->relativize($to)));

        if (0 === \count(glob(\dirname($to).'/*', \GLOB_NOSORT))) {
            @rmdir(\dirname($to));
        }
    }

    private function getLocalFilePath(string $basePath, $destination): string
    {
        return str_replace($basePath.\DIRECTORY_SEPARATOR, '', $destination);
    }
}
