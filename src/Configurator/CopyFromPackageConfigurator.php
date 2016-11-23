<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class CopyFromPackageConfigurator extends AbstractCopyConfigurator
{
    public function configure(Recipe $recipe, $config)
    {
        $this->io->write('    Setting configuration and copying files');

        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->copyFiles($config, $packageDir, getcwd());
    }

    public function unconfigure(Recipe $recipe, $config)
    {
        $this->io->write('    Removing configuration and files');
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($recipe->getPackage());
        $this->removeFiles($config, $packageDir, getcwd());
    }
}
