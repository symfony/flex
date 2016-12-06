<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class BundlesConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Enabling the package as a Symfony bundle');
// FIXME: be sure that FrameworkBundle is always first
        $file = getcwd().'/conf/bundles.php';
        $registered = file_exists($file) ? (require $file) : array();
        foreach ($this->parseBundles($bundles) as $class => $envs) {
            $registered[$class] = $envs;
        }
        file_put_contents($file, sprintf("<?php\nreturn %s;\n", var_export($registered, true)));
    }

    public function unconfigure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Disabling the Symfony bundle');
        $file = getcwd().'/conf/bundles.php';
        if (!file_exists($file)) {
            return;
        }

        $registered = require $file;
        foreach (array_keys($this->parseBundles($bundles)) as $class) {
            unset($registered[$class]);
        }
        file_put_contents($file, sprintf("<?php\nreturn %s;\n", var_export($registered, true)));
    }

    private function parseBundles($manifest)
    {
        $bundles = array();
        foreach ($manifest as $class => $envs) {
            $bundles[$class] = $envs;
        }

        return $bundles;
    }
}
