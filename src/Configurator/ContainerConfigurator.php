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
class ContainerConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $parameters, Lock $lock, array $options = []): bool
    {
        if (!$this->shouldConfigure($this->composer, $this->io, $recipe)) {
            return false;
        }

        $this->write('Setting parameters');

        file_put_contents(
            $this->options->get('root-dir').'/'.$this->getServicesPath(),
            $this->configureParameters($parameters)
        );

        return true;
    }

    public function unconfigure(Recipe $recipe, $parameters, Lock $lock)
    {
        $this->write('Unsetting parameters');
        $target = $this->options->get('root-dir').'/'.$this->getServicesPath();
        $lines = [];
        foreach (file($target) as $line) {
            if ($this->removeParameters(1, $parameters, $line)) {
                continue;
            }
            $lines[] = $line;
        }
        file_put_contents($target, implode('', $lines));
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        if (!$this->shouldConfigure($this->composer, $this->io, $recipeUpdate->getNewRecipe())) {
            return;
        }

        if ($originalConfig) {
            $recipeUpdate->setOriginalFile(
                $this->getServicesPath(),
                $this->configureParameters($originalConfig, true)
            );
        }

        if ($newConfig) {
            $recipeUpdate->setNewFile(
                $this->getServicesPath(),
                $this->configureParameters($newConfig, true)
            );
        }
    }

    public function configureKey(): string
    {
        return 'container';
    }

    private function configureParameters(array $parameters, bool $update = false): string
    {
        $target = $this->options->get('root-dir').'/'.$this->getServicesPath();
        $endAt = 0;
        $isParameters = false;
        $lines = [];
        foreach (file($target) as $i => $line) {
            $lines[] = $line;
            if (!$isParameters && !preg_match('/^parameters:/', $line)) {
                continue;
            }
            if (!$isParameters) {
                $isParameters = true;
                continue;
            }
            if (!preg_match('/^\s+.*/', $line) && '' !== trim($line)) {
                $endAt = $i - 1;
                $isParameters = false;
                continue;
            }
            foreach ($parameters as $key => $value) {
                $matches = [];
                if (preg_match(sprintf('/^\s+%s\:/', preg_quote($key, '/')), $line, $matches)) {
                    if ($update) {
                        $lines[$i] = substr($line, 0, \strlen($matches[0])).' '.str_replace("'", "''", $value)."\n";
                    }

                    unset($parameters[$key]);
                }
            }
        }

        if ($parameters) {
            $parametersLines = [];
            if (!$endAt) {
                $parametersLines[] = "parameters:\n";
            }
            foreach ($parameters as $key => $value) {
                if (\is_array($value)) {
                    $parametersLines[] = sprintf("    %s:\n%s", $key, $this->dumpYaml(2, $value));
                    continue;
                }
                $parametersLines[] = sprintf("    %s: '%s'%s", $key, str_replace("'", "''", $value), "\n");
            }
            if (!$endAt) {
                $parametersLines[] = "\n";
            }
            array_splice($lines, $endAt, 0, $parametersLines);
        }

        return implode('', $lines);
    }

    private function removeParameters($level, $params, $line)
    {
        foreach ($params as $key => $value) {
            if (\is_array($value) && $this->removeParameters($level + 1, $value, $line)) {
                return true;
            }
            if (preg_match(sprintf('/^(\s{%d}|\t{%d})+%s\:/', 4 * $level, $level, preg_quote($key, '/')), $line)) {
                return true;
            }
        }

        return false;
    }

    private function dumpYaml($level, $array): string
    {
        $line = '';
        foreach ($array as $key => $value) {
            $line .= str_repeat('    ', $level);
            if (!\is_array($value)) {
                $line .= sprintf("%s: '%s'\n", $key, str_replace("'", "''", $value));
                continue;
            }
            $line .= sprintf("%s:\n", $key).$this->dumpYaml($level + 1, $value);
        }

        return $line;
    }

    private function getServicesPath(): string
    {
        return $this->options->expandTargetDir('%CONFIG_DIR%/services.yaml');
    }
}
