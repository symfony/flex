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
class CopyFromRecipeConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Setting configuration and copying files');
        $this->copyFiles($config, $recipe->getFiles(), getcwd());
    }

    public function unconfigure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');
        $this->removeFiles($config, $recipe->getFiles(), getcwd());
    }

    private function copyFiles($manifest, $files, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
                $this->copyDir($source, $to.'/'.$target, $files);
            } else {
                $this->copyFile($to.'/'.$target, $files[$source]);
            }
        }
    }

    private function copyDir($source, $target, $files)
    {
        foreach ($files as $file => $data) {
            if (0 === strpos($file, $source)) {
                $file = $target.'/'.substr($file, strlen($source));
                $this->copyFile($file, $data['contents']);
                if ($data['executable']) {
                    @chmod($file, fileperms($file) | 0111);
                }
            }
        }
    }

    private function copyFile($to, $contents)
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }

        if (!file_exists($to)) {
            file_put_contents($to, $contents);
        }
    }

    private function removeFiles($manifest, $files, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
                foreach (array_keys($files) as $file) {
                    if (0 === strpos($file, $source)) {
                        $this->removeFile($to.'/'.$target.'/'.substr($file, strlen($source)));
                    }
                }
            } else {
                $this->removeFile($to.'/'.$target);
            }
        }
    }

    private function removeFile($to)
    {
        @unlink($to);

        if (0 === count(glob(dirname($to).'/*', GLOB_NOSORT))) {
            @rmdir(dirname($to));
        }
    }
}
