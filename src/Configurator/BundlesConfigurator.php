<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class BundlesConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $bundles): void
    {
        $this->write('Enabling the package as a Symfony bundle');
        $file = $this->getConfFile();
        $registered = $this->load($file);
        $classes = $this->parse($bundles);
        if (isset($classes[$fwb = 'Symfony\Bundle\FrameworkBundle\FrameworkBundle'])) {
            foreach ($classes[$fwb] as $env) {
                $registered[$fwb][$env] = true;
            }
            unset($classes[$fwb]);
        }
        foreach ($classes as $class => $envs) {
            foreach ($envs as $env) {
                $registered[$class][$env] = true;
            }
        }
        $this->dump($file, $registered);
    }

    public function unconfigure(Recipe $recipe, $bundles): void
    {
        $this->write('Disabling the Symfony bundle');
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

    private function parse(iterable $manifest): iterable
    {
        $bundles = [];
        foreach ($manifest as $class => $envs) {
            $bundles[ltrim($class, '\\')] = $envs;
        }

        return $bundles;
    }

    private function load(string $file): iterable
    {
        $bundles = file_exists($file) ? (require $file) : [];
        if (!is_array($bundles)) {
            $bundles = [];
        }

        return $bundles;
    }

    private function dump(string $file, iterable $bundles): void
    {
        $contents = '<?php'.PHP_EOL.PHP_EOL.'return ['.PHP_EOL;
        foreach ($bundles as $class => $envs) {
            $contents .= "    '$class' => [";
            foreach (array_keys($envs) as $env) {
                $contents .= "'$env' => true, ";
            }
            $contents = substr($contents, 0, -2).'],'.PHP_EOL;
        }
        $contents .= '];'.PHP_EOL;

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file);
        }
    }

    private function getConfFile(): string
    {
        return getcwd().'/etc/bundles.php';
    }
}
