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

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Options
{
    private $options;
    private $filesManager;

    public function __construct(array $options = [], ?FilesManager $filesManager = null)
    {
        $this->options = $options;
        $this->filesManager = $filesManager;
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
        if (null === $this->filesManager) {
            return false;
        }

        return $this->filesManager->shouldWriteFile($file, $overwrite, $skipQuestion);
    }

    public function getRemovableFilesFromRecipeAndLock(Recipe $recipe): array
    {
        if (null === $this->filesManager) {
            return [];
        }

        return $this->filesManager->getRemovableFilesFromRecipeAndLock($recipe);
    }

    public function toArray(): array
    {
        return $this->options;
    }
}
