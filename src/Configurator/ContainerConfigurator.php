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
        // FIXME: hard to do, but as adding parameters should be very rare, that's fine
    }

    private function updateParametersIni($parameters)
    {
        $target = getcwd().'/etc/container.yaml';
        $contents = file_get_contents($target);
        foreach ($parameters as $key => $value) {
            // FIXME: var_export() only works for basics types, but we don't have access to the Symfony YAML component here
            $value = var_export($value, true);
            $count = 0;
            $contents = preg_replace('{^( *)'.$key.'( *):( *).*$}im', "$1$key$2:$3$value", $contents, -1, $count);
            if (!$count) {
                $contents .= "    $key: $value".PHP_EOL;
            }
        }

        file_put_contents($target, $contents);
    }
}
