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
                $this->copyDir($from.'/'.$source, $to.'/'.$target);
            } else {
                if (!is_dir(dirname($to.'/'.$target))) {
                    mkdir(dirname($to.'/'.$target), 0777, true);
                }

                if (!file_exists($to.'/'.$target)) {
                    $this->copyFile($from.'/'.$source, $to.'/'.$target);
                }
            }
        }
    }

    private function removeFiles(array $manifest, string $from, string $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === substr($source, -1)) {
                $this->removeFilesFromDir($from.'/'.$source, $to.'/'.$target);
            } else {
                @unlink($to.'/'.$target);
            }
        }
    }

    private function copyDir(string $source, string $target)
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!is_dir($new = $target.'/'.$iterator->getSubPathName())) {
                    mkdir($new);
                }
            } elseif (!file_exists($target.'/'.$iterator->getSubPathName())) {
                $this->copyFile($item, $target.'/'.$iterator->getSubPathName());
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
    }

    private function removeFilesFromDir(string $source, string $target)
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                // that removes the dir only if it is empty
                @rmdir($target.'/'.$iterator->getSubPathName());
            } else {
                @unlink($target.'/'.$iterator->getSubPathName());
            }
        }
    }
}
