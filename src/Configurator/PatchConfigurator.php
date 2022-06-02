<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
class PatchConfigurator extends AbstractConfigurator
{
    private const POSITION_START = 'start';
    private const POSITION_END = 'end';
    private const POSITION_AFTER_TARGET = 'after_target';

    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        foreach ($config as $patch) {
            if (!isset($patch['file'])) {
                continue; // misconfiguration
            }

            if (!is_file($file = $this->path->concatenate([$patch['file']]))) {
                continue;
            }

            switch (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                case 'json':
                    $this->patchJsonFile($file, $patch);

                    break;

                case 'yaml':
                    $this->patchYamlFile($file, $patch);

                    break;

                default:
                    $this->patchFile($file, $patch);
            }
        }
    }

    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        // noop - cannot be unconfigured
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        // noop - cannot be updated
    }

    private function patchFile(string $file, array $config): void
    {
        $fileContents = file_get_contents($file);
        $value = $config['value'] ?? '';

        if (false !== strpos($fileContents, $value)) {
            return; // already includes value, skip
        }

        switch ($config['position'] ?? '') {
            case self::POSITION_END:
                $fileContents .= $value;

                break;
            case self::POSITION_START:
                $fileContents = $value.$fileContents;

                break;
            case self::POSITION_AFTER_TARGET:
                if (!isset($config['target'])) {
                    break; // misconfiguration
                }

                $target = $config['target'];

                if (false === strpos($fileContents, $target)) {
                    break; // file does not include target, skip
                }

                $fileContents = str_replace($target, $value, $fileContents);

                break;
        }

        file_put_contents($file, $fileContents);
    }

    private function patchJsonFile(string $file, array $config): void
    {
        // todo
    }

    private function patchYamlFile(string $file, array $config): void
    {
        // todo
    }
}
