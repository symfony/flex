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
        $this->io->write('    Setting parameters');
        $this->updateParametersIni($parameters);
    }

    public function unconfigure(Recipe $recipe, $parameters)
    {
// FIXME: what about config.yaml, difficult to revert that (too many possible side effect
//        between bundles changing the same value)
    }

    private function updateParametersIni($parameters)
    {
        $target = getcwd().'/etc/container.yaml';
        $contents = file_get_contents($target);
        foreach ($parameters as $key => $value) {
// FIXME: we don't have access to YAML here :( Or can we?
// so, only string, bools, int... work here
            $value = var_export($value, true);
            $count = 0;
            $contents = preg_replace('{^( *)'.$key.'( *):( *).*$}im', "$1$key$2:$3$value", $contents, -1, $count);
            if (!$count) {
                $contents .= "    $key: $value\n";
            }
        }

        file_put_contents($target, $contents);
    }
}
