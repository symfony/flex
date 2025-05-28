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
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Options
{
    private $options;
    private $writtenFiles = [];
    private $io;
    private $lockData;

    public function __construct(array $options = [], ?IOInterface $io = null, ?Lock $lock = null)
    {
        $this->options = $options;
        $this->io = $io;
        $this->lockData = $lock?->all() ?? [];
    }

    public function get(string $name)
    {
        return $this->options[$name] ?? null;
    }

    public function expandTargetDir(string $target): string
    {
        $result = preg_replace_callback('{%(.+?)%}', function ($matches) {
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($this->options[$option])) {
                return $matches[0];
            }

            return rtrim($this->options[$option], '/');
        }, $target);

        $phpunitDistFiles = [
            'phpunit.xml.dist' => true,
            'phpunit.dist.xml' => true,
        ];

        $rootDir = $this->get('root-dir');

        if (null === $rootDir || !isset($phpunitDistFiles[$result]) || !is_dir($rootDir) || file_exists($rootDir.'/'.$result)) {
            return $result;
        }

        unset($phpunitDistFiles[$result]);
        $otherPhpunitDistFile = key($phpunitDistFiles);

        return file_exists($rootDir.'/'.$otherPhpunitDistFile) ? $otherPhpunitDistFile : $result;
    }

    public function shouldWriteFile(string $file, bool $overwrite, bool $skipQuestion): bool
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

        if ($skipQuestion) {
            return true;
        }

        exec('git status --short --ignored --untracked-files=all -- '.ProcessExecutor::escape($file).' 2>&1', $output, $status);

        if (0 !== $status) {
            return $this->io && $this->io->askConfirmation(\sprintf('Cannot determine the state of the "%s" file, overwrite anyway? [y/N] ', $file), false);
        }

        if (empty($output[0]) || preg_match('/^[ AMDRCU][ D][ \t]/', $output[0])) {
            return true;
        }

        $name = basename($file);
        $name = \strlen($output[0]) - \strlen($name) === strrpos($output[0], $name) ? substr($output[0], 3) : $name;

        return $this->io && $this->io->askConfirmation(\sprintf('File "%s" has uncommitted changes, overwrite? [y/N] ', $name), false);
    }

    public function getRemovableFiles(Recipe $recipe, Lock $lock): array
    {
        if (null === $removableFiles = $this->lockData[$recipe->getName()]['files'] ?? null) {
            $removableFiles = [];
            foreach (array_keys($recipe->getFiles()) as $source => $target) {
                if (str_ends_with($source, '/')) {
                    $removableFiles[] = $this->expandTargetDir($target);
                }
            }
        }

        unset($this->lockData[$recipe->getName()]);
        $lockedFiles = array_count_values(array_merge(...array_column($lock->all(), 'files')));

        $nonRemovableFiles = [];
        foreach ($removableFiles as $i => $file) {
            if (isset($lockedFiles[$file])) {
                $nonRemovableFiles[] = $file;
                unset($removableFiles[$i]);
            }
        }

        if ($nonRemovableFiles && $this->io) {
            $this->io?->writeError('    <warning>The following files are still referenced by other recipes, you might need to adjust them manually:</warning>');
            foreach ($nonRemovableFiles as $file) {
                $this->io?->writeError('      - '.$file);
            }
        }

        return array_values($removableFiles);
    }

    public function toArray(): array
    {
        return $this->options;
    }
}
