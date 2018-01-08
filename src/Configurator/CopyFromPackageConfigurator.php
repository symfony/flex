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

use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class CopyFromPackageConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->write('Setting configuration and copying files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->copyFiles($config, $packageDir, getcwd());
    }

    public function unconfigure(Recipe $recipe, $config)
    {
        $this->write('Removing configuration and files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->removeFiles($config, $packageDir, getcwd());
    }

    private function copyFiles(array $manifest, string $from, string $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === substr($source, -1)) {
                $this->copyDir(
                    $this->concatenatePathParts([$from, $source]),
                    $this->concatenatePathParts([$to, $target])
                );
            } else {
                $targetPath = $this->concatenatePathParts([$to, $target]);
                if (!is_dir(dirname($targetPath))) {
                    mkdir(dirname($targetPath), 0777, true);
                    $this->write(sprintf(
                        'Created directory <fg=green>"%s"</>',
                        $this->relativizePath($targetPath)
                    ));
                }

                if (!file_exists($targetPath)) {
                    $this->copyFile(
                        $this->concatenatePathParts([$from, $source]),
                        $targetPath
                    );
                }
            }
        }
    }

    private function removeFiles(array $manifest, string $from, string $to)
    {
        foreach ($manifest as $source => $target) {
            $targetPath = $this->concatenatePathParts([$to, $target]);
            if ('/' === substr($source, -1)) {
                $this->removeFilesFromDir(
                    $this->concatenatePathParts([$from, $source]),
                    $this->concatenatePathParts([$to, $target])
                );
            } else {
                @unlink($targetPath);
                $this->write(sprintf(
                    'Removed file <fg=green>"%s"</>',
                    $this->relativizePath($targetPath)
                ));
            }
        }
    }

    private function copyDir(string $source, string $target)
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $targetPath = $this->concatenatePathParts([$target, $iterator->getSubPathName()]);
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath);
                    $this->write(sprintf(
                        'Created directory <fg=green>"%s"</>',
                        $this->relativizePath($targetPath)
                    ));
                }
            } elseif (!file_exists($targetPath)) {
                $this->copyFile($item, $targetPath);
            }
        }
    }

    public function copyFile(string $source, string $target)
    {
        if (file_exists($target)) {
            return;
        }

        copy($source, $target);
        @chmod($target, fileperms($target) | (fileperms($source) & 0111));
        $this->write(sprintf(
            'Created file <fg=green>"%s"</>',
            $this->relativizePath($target)
        ));
    }

    private function removeFilesFromDir(string $source, string $target)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $targetPath = $this->concatenatePathParts([$target, $iterator->getSubPathName()]);
            if ($item->isDir()) {
                // that removes the dir only if it is empty
                @rmdir($targetPath);
                $this->write(sprintf(
                    'Removed directory <fg=green>"%s"</>',
                    $this->relativizePath($targetPath)
                ));
            } else {
                @unlink($targetPath);
                $this->write(sprintf(
                    'Removed file <fg=green>"%s"</>',
                    $this->relativizePath($targetPath)
                ));
            }
        }
    }

    private function relativizePath(string $absolutePath): string
    {
        $relativePath = str_replace(getcwd(), '.', $absolutePath);

        return is_dir($absolutePath) ? rtrim($relativePath, '/').'/' : $relativePath;
    }

    private function concatenatePathParts(array $parts): string
    {
        return array_reduce($parts, function (string $initial, string $next): string {
            return rtrim($initial, '/').'/'.ltrim($next, '/');
        }, '');
    }
}
