<?php

namespace Symfony\Start\Configurator;

use Symfony\Start\Recipe;

class CopyFromPackageConfigurator extends AbstractCopyConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Setting configuration and copying files');

        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->copyFiles($config, $packageDir, getcwd());
    }

    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->removeFiles($config, $packageDir, getcwd());
    }
}
