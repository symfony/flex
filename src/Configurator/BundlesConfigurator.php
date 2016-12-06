<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class BundlesConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $bundles)
    {
        $this->io->write('    Enabling the package as a Symfony bundle');
// FIXME: be sure that FrameworkBundle is always first
        $file = $this->getConfFile();
        $registered = $this->load($file);
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
        $file = $this->getConfFile();
        if (!file_exists($file)) {
            return;
        }

        $registered = $this->load($file);
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

    private function load($file)
    {
        $bundles = file_exists($file) ? (require $file) : [];
        if (!is_array($bundles)) {
            $bundles = [];
        }

        return $bundles;
    }

    private function dump($file, $bundles)
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($bundles as $class => $envs) {
            $contents .= "    '$class' => [";
            foreach (array_keys($envs) as $env) {
                $contents .= "'$env' => true, ";
            }
            $contents = substr($contents, 0, -2)."],\n";
        }
        $contents .= "];\n";

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }

    private function getConfFile()
    {
        return getcwd().'/conf/bundles.php';
    }
}
