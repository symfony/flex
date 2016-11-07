<?php

namespace Symfony\Start\Configurator;

use Symfony\Start\Recipe;

class ParametersConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $parameters)
    {
        $this->io->write('    Setting parameters');
        $this->updateParametersIni($parameters);
    }

    public function unconfigure(Recipe $recipe, $parameters)
    {
// FIXME: what about parameters.ini, difficult to revert that (too many possible side effect
//        between bundles changing the same value)
    }

    private function updateParametersIni($parameters)
    {
        $target = getcwd().'/conf/parameters.ini';
        $original = $this->readIniRaw($target);
        $contents = rtrim(file_get_contents($target), "\n")."\n";
        foreach ($parameters as $key => $value) {
            if (isset($original['parameters'][$key])) {
                // replace value
                $contents = preg_replace('{^( *)'.$key.'( *)=( *).*$}im', "$1$key$2=$3$value", $contents);
            } else {
                // add a new entry
                $contents .= "  $key = $value\n";
            }
        }

        file_put_contents($target, $contents);
    }

    private function readIniRaw($file)
    {
        // first pass to catch parsing errors
        $result = parse_ini_file($file, true);
        if (false === $result || array() === $result) {
            throw new InvalidArgumentException(sprintf('The "%s" file is not valid.', $file));
        }

        // real raw parsing
        return parse_ini_file($file, true, INI_SCANNER_RAW);
    }
}
