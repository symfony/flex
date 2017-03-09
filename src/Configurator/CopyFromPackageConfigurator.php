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
        $this->io->write('    Setting configuration and copying files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->copyFiles($config, $packageDir, getcwd());
    }

    public function unconfigure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->removeFiles($config, $packageDir, getcwd());
    }

    private function copyFiles($manifest, $from, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
// FIXME: how to manage different versions/branches?
// FIXME: never override an existing file, or at least ask the question! Or display a diff, for files that should not be modified like for symfony/requirements
// FIXME: ADD the possibility to fill-in some parameters via questions (and sensible default values)
                $this->copyDir($from.'/'.$source, $to.'/'.$target);
            } else {
// FIXME: it does not keep fs rights! executable fe bin/console?
                if (!is_dir(dirname($to.'/'.$target))) {
                    mkdir(dirname($to.'/'.$target), 0777, true);
                }

                if (!file_exists($to.'/'.$target)) {
                    copy($from.'/'.$source, $to.'/'.$target);
                }
            }
        }
    }

    private function removeFiles($manifest, $from, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
                $this->removeFilesFromDir($from.'/'.$source, $to.'/'.$target);
            } else {
                @unlink($to.'/'.$target);
            }
        }
    }

    private function copyDir($source, $target)
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
// FIXME: it does not keep fs rights! executable fe bin/console?
                copy($item, $target.'/'.$iterator->getSubPathName());
            }
        }
    }

    private function removeFilesFromDir($source, $target)
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
