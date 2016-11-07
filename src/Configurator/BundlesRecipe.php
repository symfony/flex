<?php

namespace Symfony\Start\Configurator;

use Symfony\Start\Recipe;

class BundlesConfigurator extends AbstractConfigurator
{
    private function configure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Enabling the package as a Symfony bundle');
// FIXME: be sure to not add a bundle twice
// FIXME: be sure that FrameworkBundle is always first
        $bundlesini = getcwd().'/conf/bundles.ini';
        $contents = file_exists($bundlesini) ? file_get_contents($bundlesini) : '';
        foreach ($this->parseBundles($bundles) as $class => $envs) {
            $contents .= "$class = $envs\n";
        }
        file_put_contents($bundlesini, ltrim($contents));
    }

    private function unconfigure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Disabling the Symfony bundle');
        $bundlesini = getcwd().'/conf/bundles.ini';
        $contents = file_exists($bundlesini) ? file_get_contents($bundlesini) : '';
        foreach (array_keys($this->parseBundles($bundles)) as $class) {
            $contents = preg_replace('{^'.preg_quote($class).'.+$}m', '', $contents);
            $contents = preg_replace("/\n+/", "\n", $contents);
        }
        file_put_contents($bundlesini, ltrim($contents));
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
