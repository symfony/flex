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
        foreach ($this->parse($bundles) as $class => $envs) {
            foreach ($envs as $env) {
                $registered[$class][$env] = true;
            }
        }
        $this->dump($file, $registered);
    }

    public function unconfigure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Disabling the Symfony bundle');
        $file = getcwd().'/conf/bundles.php';
        if (!file_exists($file)) {
            return;
        }

        $registered = require $file;
        foreach (array_keys($this->parse($bundles)) as $class) {
            unset($registered[$class]);
        }
        $this->dump($file, $registered);
    }

    private function parse($manifest)
    {
        $bundles = array();
        foreach ($manifest as $class => $envs) {
            $bundles[$class] = $envs;
        }

        return $bundles;
    }

    private function dump($file, $bundles)
    {
        $contents = "<?php\nreturn [\n";
        foreach ($bundles as $class => $envs) {
            $contents .= "    $class => [";
            foreach (array_keys($envs) as $env) {
                $contents .= "$env => true, ";
            }
            $contents = substr($contents, -2)."],\n";
        }
        $contents .= "\n];\n";
        file_put_contents($file, $contents);
    }
}
