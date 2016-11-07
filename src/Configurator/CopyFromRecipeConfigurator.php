<?php

namespace Symfony\Start\Configurator;

use Symfony\Start\Recipe;

class CopyFromRecipeConfigurator extends AbstractCopyConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Setting configuration and copying files');

        $this->copyFiles($config, $recipe->getDir(), getcwd());
    }

    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');

        $this->removeFiles($config, $recipe->getDir(), getcwd());
    }
}
