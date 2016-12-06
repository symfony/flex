<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class ParametersConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $parameters)
    {
        $this->io->write('    Setting parameters');
        $this->updateParametersIni($parameters);
    }

    public function unconfigure(Recipe $recipe, $parameters)
    {
// FIXME: what about parameters.yaml, difficult to revert that (too many possible side effect
//        between bundles changing the same value)
    }

    private function updateParametersIni($parameters)
    {
        $target = getcwd().'/conf/parameters.yaml';
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
