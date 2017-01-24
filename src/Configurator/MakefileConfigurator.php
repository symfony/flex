<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class MakefileConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $definitions)
    {
        $this->io->write('    Adding Makefile entries');
        $data = sprintf("\n###> %s ###\n%s\n###< %s ###\n", $recipe->getName(), $definitions, $recipe->getName());
        file_put_contents(getcwd().'/Makefile', $data, FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        if (!file_exists($makefile = getcwd().'/Makefile')) {
            continue;
        }

        $contents = preg_replace(sprintf('{\n+###> %s ###.*###< %s ###\n+}s', $recipe->getName(), $recipe->getName()), "\n", file_get_contents($makefile), -1, $count);
        if (!$count) {
            continue;
        }

        $this->io->write(sprintf('    Removing Makefile entries from %s', $file));
        file_put_contents($makefile, $contents);
    }
}
