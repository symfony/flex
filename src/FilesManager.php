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

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;

/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class FilesManager
{
    private $io;
    protected $path;

    private $writtenFiles = [];
    private $files;

    public function __construct(IOInterface $io, Lock $lock, string $rootDir)
    {
        $this->io = $io;

        $this->path = new Path($rootDir);
        $this->files = array_count_values(
            array_map(
                function (string $file) {
                    return realpath($file) ?: '';
                }, array_reduce(
                    array_column($lock->all(), 'files'),
                    function (array $carry, array $package) {
                        return array_merge($carry, $package);
                    },
                    []
                )
            )
        );
    }

    public function shouldWriteFile(string $file, bool $overwrite): bool
    {
        if (isset($this->writtenFiles[$file])) {
            return false;
        }
        $this->writtenFiles[$file] = true;

        if (!file_exists($file)) {
            return true;
        }

        if (!$overwrite) {
            return false;
        }

        if (!filesize($file)) {
            return true;
        }

        exec('git status --short --ignored --untracked-files=all -- '.ProcessExecutor::escape($file).' 2>&1', $output, $status);

        if (0 !== $status) {
            return (bool) $this->io && $this->io->askConfirmation(sprintf('Cannot determine the state of the "%s" file, overwrite anyway? [y/N] ', $file), false);
        }

        if (empty($output[0]) || preg_match('/^[ AMDRCU][ D][ \t]/', $output[0])) {
            return true;
        }

        $name = basename($file);
        $name = \strlen($output[0]) - \strlen($name) === strrpos($output[0], $name) ? substr($output[0], 3) : $name;

        return (bool) $this->io && $this->io->askConfirmation(sprintf('File "%s" has uncommitted changes, overwrite? [y/N] ', $name), false);
    }

    public function getRemovableFilesFromRecipeAndLock(Recipe $recipe): array
    {
        $removableFiles = $recipe->getFiles();
        // Compare file paths by their real path to abstract OS differences
        foreach (array_keys($removableFiles) as $file) {
            $file = realpath($file);
            if (!isset($this->files[$file])) {
                continue;
            }

            --$this->files[$file];

            if ($this->files[$file] <= 0) {
                unset($removableFiles[$file]);
            }
        }

        return $removableFiles;
    }
}
