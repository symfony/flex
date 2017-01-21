<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class CopyFromRecipeConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Setting configuration and copying files');

        $this->copyFiles($config, $recipe->getData(), getcwd());
    }

    public function unconfigure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');

        $this->removeFiles($config, $recipe->getData(), getcwd());
    }

    private function copyFiles($manifest, $data, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
// FIXME: how to manage different versions/branches?
// FIXME: never override an existing file, or at least ask the question! Or display a diff, for files that should not be modified like for symfony/requirements
// FIXME: ADD the possibility to fill-in some parameters via questions (and sensible default values)
                $this->copyDir($source, $to.'/'.$target, $data);
            } else {
                $this->copyFile($to.'/'.$target, $data['files'][$source]);
            }
        }
    }

    private function copyDir($source, $target, $data)
    {
        foreach ($data['files'] as $file => $contents) {
            if (0 === strpos($file, $source)) {
                $this->copyFile($target.'/'.substr($file, strlen($source)), $contents);
            }
        }
    }

    private function copyFile($to, $contents)
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }

// FIXME: we need a way to say if a file should be executable
        file_put_contents($to, $contents);
    }

    private function removeFiles($manifest, $data, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
                foreach (array_keys($data['files']) as $file) {
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
