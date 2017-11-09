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
class ContainerConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $parameters)
    {
        $this->write('Setting parameters');
        $this->addParameters($parameters);
    }

    public function unconfigure(Recipe $recipe, $parameters)
    {
        $this->write('Unsetting parameters');
        $target = getcwd().'/config/services.yaml';
        $lines = [];
        foreach (file($target) as $line) {
            foreach (array_keys($parameters) as $key) {
                if (preg_match("/^\s+$key\:/", $line)) {
                    continue 2;
                }
            }
            $lines[] = $line;
        }
        file_put_contents($target, implode('', $lines));
    }

    private function addParameters(array $parameters)
    {
        $target = getcwd().'/config/services.yaml';
        $endAt = 0;
        $isParameters = false;
        $lines = [];
        foreach (file($target) as $i => $line) {
            $lines[] = $line;
            if (!$isParameters && !preg_match('/^parameters\:/', $line)) {
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
                if (preg_match("/^\s+$key\:/", $line)) {
                    unset($parameters[$key]);
                }
            }
        }
        if (!$parameters) {
            return;
        }

        $parametersLines = [];
        if (!$endAt) {
            $parametersLines[] = "parameters:\n";
        }
        foreach ($parameters as $key => $value) {
            // FIXME: var_export() only works for basics types, but we don't have access to the Symfony YAML component here
            $parametersLines[] = sprintf("    %s: %s%s", $key, var_export($value, true), "\n");
        }
        if (!$endAt) {
            $parametersLines[] = "\n";
        }
        array_splice($lines, $endAt, 0, $parametersLines);
        file_put_contents($target, implode('', $lines));
    }
}
