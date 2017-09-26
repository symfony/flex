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
        $lines = [];
        foreach (file($target) as $line) {
            $lines[] = $line;
            if (!preg_match('/^parameters\:/', $line)) {
                continue;
            }
            foreach ($parameters as $key => $value) {
                // FIXME: var_export() only works for basics types, but we don't have access to the Symfony YAML component here
                $lines[] = sprintf("    %s: %s%s", $key, var_export($value, true), "\n");
            }
        }
        file_put_contents($target, implode('', $lines));
    }
}
