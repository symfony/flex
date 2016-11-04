<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\IO\IOInterface;
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
        $this->registerBundle($package, $name, $recipeDir);
        $this->copyData($package, $name, $recipeDir);
        $this->registerEnv($package, $name, $recipeDir);
    }

    public function unconfigure(Package $package, $name, $recipeDir)
    {
        $this->removeBundle($package, $name, $recipeDir);
        $this->removeData($package, $name, $recipeDir);
        $this->removeEnv($package, $name, $recipeDir);
    }

    private function registerBundle(Package $package, $name, $recipeDir)
    {
        $bundlesini = getcwd().'/conf/bundles.ini';
        if (!file_exists($bundlesini)) {
            return;
        }

        if (!$bundles = $this->parseBundles($recipeDir)) {
            return;
        }

        $this->io->write('    Enabling the package as a Symfony bundle');
// FIXME: be sure to not add a bundle twice
// FIXME: be sure that FrameworkBundle is always first
        $contents = file_get_contents($bundlesini);
        foreach ($bundles as $class => $envs) {
            $contents .= "$class = $envs\n";
        }
        file_put_contents($bundlesini, ltrim($contents));
    }

    private function copyData(Package $package, $name, $recipeDir)
    {
        if (is_file($recipeDir.'/parameters.ini')) {
            $this->io->write('    Setting parameters');
            $this->updateParametersIni($recipeDir.'/parameters.ini');
        }

        if (!is_file($recipeDir.'/manifest.ini')) {
            return;
        }

        $this->io->write('    Setting configuration and copying files');

        $manifest = parse_ini_file($recipeDir.'/manifest.ini', true);
        if (false === $manifest || array() === $manifest) {
            throw new InvalidArgumentException(sprintf('The "%s" file is not valid.', $recipeDir.'/manifest.ini'));
        }

        $targetDir = getcwd();
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($package);
        if (isset($manifest['recipe'])) {
            $this->copyFiles($manifest['recipe'], $recipeDir, $targetDir);
        }
        if (isset($manifest['package'])) {
            $this->copyFiles($manifest['package'], $packageDir, $targetDir);
        }
    }

    private function registerEnv(Package $package, $name, $recipeDir)
    {
        $env = $recipeDir.'/env';
        if (!file_exists($env)) {
            return;
        }

        $this->io->write('    Adding environment variable defaults');
        $data = sprintf("\n###> %s ###\n", $name);
        $data .= file_get_contents($env);
        $data .= sprintf("###< %s ###\n", $name);
        file_put_contents(getcwd().'/.env.dist', $data, FILE_APPEND);
        file_put_contents(getcwd().'/.env', $data, FILE_APPEND);
    }

    private function removeBundle(Package $package, $name, $recipeDir)
    {
        $bundlesini = getcwd().'/conf/bundles.ini';
        if (!file_exists($bundlesini)) {
            return;
        }

        if (!$bundles = $this->parseBundles($recipeDir)) {
            return;
        }

        $this->io->write('    Disabling the Symfony bundle');
        $contents = file_get_contents($bundlesini);
        foreach (array_keys($bundles) as $class) {
            $contents = preg_replace('{^'.preg_quote($class).'.+$}m', '', $contents);
            $contents = preg_replace("/\n+/", "\n", $contents);
        }
        file_put_contents($bundlesini, ltrim($contents));
    }

    private function removeData(Package $package, $name, $recipeDir)
    {
// FIXME: what about parameters.ini, difficult to revert that (too many possible side effect
//        between bundles changing the same value)

        if (!is_file($recipeDir.'/manifest.ini')) {
            return;
        }

        $this->io->write('    Removing configuration and files');

        $manifest = parse_ini_file($recipeDir.'/manifest.ini', true);
        if (false === $manifest || array() === $manifest) {
            throw new InvalidArgumentException(sprintf('The "%s" file is not valid.', $recipeDir.'/manifest.ini'));
        }

        $targetDir = getcwd();
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($package);
        if (isset($manifest['recipe'])) {
            $this->removeFiles($manifest['recipe'], $recipeDir, $targetDir);
        }
        if (isset($manifest['package'])) {
            $this->removeFiles($manifest['package'], $packageDir, $targetDir);
        }
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

    private function parseBundles($recipeDir)
    {
        if (!is_file($recipeDir.'/bundles.ini')) {
            return [];
        }

        $bundles = [];
        foreach (parse_ini_file($recipeDir.'/bundles.ini') as $class => $envs) {
            $bundles[$class] = $envs;
        }

        return $bundles;
    }

    private function updateParametersIni($iniFile)
    {
        $target = getcwd().'/conf/parameters.ini';
        $original = $this->readIniRaw($target);
        $changes = $this->readIniRaw($iniFile);
        $contents = rtrim(file_get_contents($target), "\n")."\n";
        foreach ($changes as $key => $value) {
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
}
