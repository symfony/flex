<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Composer\Package\Package;

class PackageConfigurator
{
    private $composer;
    private $io;
    private $options;

    public function __construct(Composer $composer, IOInterface $io, $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
    }

    public function getOption($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    public function configure(Package $package, $name, $recipeDir)
    {
        $manifest = $this->loadManifest($recipeDir);

        if (isset($manifest['bundles'])) {
            $this->registerBundle($this->parseBundles($manifest['bundles']));
        }

        $this->copyData($package, $manifest, $recipeDir);

        if (isset($manifest['env'])) {
            $this->registerEnv($package, $manifest['env'], $name, $recipeDir);
        }

        if (isset($manifest['composer-scripts'])) {
            $this->registerComposerScripts($manifest['composer-scripts']);
        }
    }

    public function unconfigure(Package $package, $name, $recipeDir)
    {
        $manifest = $this->loadManifest($recipeDir);

        if (isset($manifest['bundles'])) {
            $this->removeBundle($this->parseBundles($manifest['bundles']));
        }

        $this->removeData($package, $manifest, $recipeDir);

        if (isset($manifest['env'])) {
            $this->removeEnv($package, $manifest['env'], $name, $recipeDir);
        }

        if (isset($manifest['composer-scripts'])) {
            $this->removeComposerScripts($manifest['composer-scripts']);
        }
    }

    private function registerBundle($bundles)
    {
        $this->io->write('    Enabling the package as a Symfony bundle');
// FIXME: be sure to not add a bundle twice
// FIXME: be sure that FrameworkBundle is always first
        $bundlesini = getcwd().'/conf/bundles.ini';
        $contents = file_exists($bundlesini) ? file_get_contents($bundlesini) : '';
        foreach ($bundles as $class => $envs) {
            $contents .= "$class = $envs\n";
        }
        file_put_contents($bundlesini, ltrim($contents));
    }

    private function removeBundle($bundles)
    {
        $this->io->write('    Disabling the Symfony bundle');
        $bundlesini = getcwd().'/conf/bundles.ini';
        $contents = file_exists($bundlesini) ? file_get_contents($bundlesini) : '';
        foreach (array_keys($bundles) as $class) {
            $contents = preg_replace('{^'.preg_quote($class).'.+$}m', '', $contents);
            $contents = preg_replace("/\n+/", "\n", $contents);
        }
        file_put_contents($bundlesini, ltrim($contents));
    }

    private function copyData(Package $package, $manifest, $recipeDir)
    {
        if (isset($manifest['parameters'])) {
            $this->io->write('    Setting parameters');
            $this->updateParametersIni($manifest['parameters']);
        }

        if (isset($manifest['copy-from-recipe']) || isset($manifest['copy-from-package'])) {
            $this->io->write('    Setting configuration and copying files');

            if (isset($manifest['copy-from-recipe'])) {
                $this->copyFiles($manifest['copy-from-recipe'], $recipeDir, getcwd());
            }

            if (isset($manifest['copy-from-package'])) {
                $packageDir = $this->composer->getInstallationManager()->getInstallPath($package);
                $this->copyFiles($manifest['copy-from-package'], $packageDir, getcwd());
            }
        }
    }

    private function removeData(Package $package, $manifest, $recipeDir)
    {
// FIXME: what about parameters.ini, difficult to revert that (too many possible side effect
//        between bundles changing the same value)
        if (!isset($manifest['copy-from-recipe']) && !isset($manifest['copy-from-package'])) {
            return;
        }

        $this->io->write('    Removing configuration and files');

        if (isset($manifest['copy-from-recipe'])) {
            $this->removeFiles($manifest['copy-from-recipe'], $recipeDir, getcwd());
        }

        if (isset($manifest['copy-from-package'])) {
            $packageDir = $this->composer->getInstallationManager()->getInstallPath($package);
            $this->removeFiles($manifest['copy-from-package'], $packageDir, getcwd());
        }
    }

    private function registerEnv(Package $package, $vars, $name, $recipeDir)
    {
        $this->io->write('    Adding environment variable defaults');
        $data = sprintf("\n###> %s ###\n", $name);
        foreach ($vars as $key => $value) {
            $data .= "$key=$value\n";
        }
        $data .= sprintf("###< %s ###\n", $name);
        file_put_contents(getcwd().'/.env.dist', $data, FILE_APPEND);
        file_put_contents(getcwd().'/.env', $data, FILE_APPEND);
    }

    private function removeEnv(Package $package, $name, $recipeDir)
    {
        foreach (array('.env', '.env.dist') as $file) {
            $env = getcwd().'/'.$file;
            if (!file_exists($env)) {
                continue;
            }

            $contents = preg_replace(sprintf('{\n+###> %s ###.*###< %s ###\n+}s', $name, $name), "\n", file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }

            $this->io->write(sprintf('    Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }

    public function registerComposerScripts($scripts)
    {
        $json = new JsonFile(Factory::getComposerFile());

        $jsonContents = $json->read();
        $autoScripts = isset($jsonContents['scripts']['auto-scripts']) ? $jsonContents['scripts']['auto-scripts'] : array();
        $autoScripts = array_merge($autoScripts, $scripts);

        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('scripts', 'auto-scripts', $autoScripts);

        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function removeComposerScripts($scripts)
    {
        $json = new JsonFile(Factory::getComposerFile());

        $jsonContents = $json->read();
        $autoScripts = isset($jsonContents['scripts']['auto-scripts']) ? $jsonContents['scripts']['auto-scripts'] : array();
        foreach (array_keys($scripts) as $cmd) {
            unset($autoScripts[$cmd]);
        }

        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('scripts', 'auto-scripts', $autoScripts);

        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    private function parseBundles($manifest)
    {
        $bundles = array();
        foreach ($manifest as $class => $envs) {
            $bundles[$class] = $envs;
        }

        return $bundles;
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

    private function copyFiles($manifest, $from, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
// FIXME: how to manage different versions/branches?
// FIXME: never override an existing file, or at least ask the question! Or display a diff, for files that should not be modified like for symfony/requirements
// FIXME: ADD the possibility to fill-in some parameters via questions (and sensible default values)
                $this->copyDir($from.'/'.$source, $to.'/'.$target);
            } else {
// FIXME: it does not keep fs rights! executable fe bin/console?
                copy($from.'/'.$source, $to.'/'.$target);
            }
        }
    }

    private function removeFiles($manifest, $from, $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->expandTargetDir($target);
            if ('/' === $source[strlen($source) - 1]) {
                $this->removeFilesFromDir($from.'/'.$source, $to.'/'.$target);
            } else {
                @unlink($to.'/'.$target);
            }
        }
    }

// FIXME: duplocated in SymfonyStartPlugin
    private function expandTargetDir($target)
    {
        $options = $this->options;

        return preg_replace_callback('{%(.+?)%}', function ($matches) use ($options) {
// FIXME: we should have a validator checking recipes when they are merged into the repo
// so that exceptions here are just not possible
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($options[$option])) {
                throw new \InvalidArgumentException(sprintf('Placeholder "%s" does not exist.', $matches[1]));
            }

            return $options[$option];
        }, $target);
    }

    private function copyDir($source, $target)
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!is_dir($new = $target.'/'.$iterator->getSubPathName())) {
                    mkdir($new);
                }
            } else {
// FIXME: it does not keep fs rights! executable fe bin/console?
                copy($item, $target.'/'.$iterator->getSubPathName());
            }
        }
    }

    private function removeFilesFromDir($source, $target)
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                // that removes the dir only if it is empty
                @rmdir($target.'/'.$iterator->getSubPathName());
            } else {
                @unlink($target.'/'.$iterator->getSubPathName());
            }
        }
    }

    private function loadManifest($recipeDir)
    {
        $manifest = $this->readIniRaw($recipeDir.'/manifest.ini');

        // check that there are not unknown keys
        if ($diff = array_diff(array_keys($manifest), array('bundles', 'copy-from-recipe', 'copy-from-package', 'parameters', 'env', 'composer-scripts'))) {
            throw new \InvalidArgumentException(sprintf('Unknown keys "%s" in package "%s" manifest.', implode('", "', $diff), $name));
        }

        return $manifest;
    }
}
