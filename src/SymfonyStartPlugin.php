<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\ClassLoader\ClassCollectionLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;

class SymfonyStartPlugin implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->initOptions();
    }

    public function installConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->configure($package, $name, $this->getRecipesDir().'/'.$name);
            $this->io->write('');
        }
    }

    public function updateConfig(PackageEvent $event)
    {
    }

    public function removeConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->unconfigure($package, $name, $this->getRecipesDir().'/'.$name);
        }
    }

    public function postInstall(Event $event)
    {
        $this->postUpdate($event);
    }

    public function postUpdate(Event $event)
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        if (!$repo->findPackage('symfony/framework-bundle', new EmptyConstraint())) {
            return;
        }

        if (!$repo->findPackage('symfony/console', new EmptyConstraint())) {
// FIXME: we need a way to disable the automatic run of cache:clear ans assets:install
//        via the composer extra configuration
            $this->io->writeError('<warning>The symfony/console package is required if you want to automatically clear the cache and install assets.</warning>');

            return;
        }

// FIXME: may be better to just let users configure which commands to run automatically via a config as well?
// FIXME: at least, we should not configure the bin dir, but the bin/console script
        if (!is_dir($this->options['bin-dir'])) {
            $this->io->writeError(sprintf('<warning>The "%s" (%s) specified in "composer.json" was not found in "%s", can not run automatic post commands.</warning>', 'bin-dir', $this->options['bin-dir'], getcwd()));

            return;
        }

// FIXME: this should be moved to the recipe of symfony/framework-bundle instead
        $this->clearCache();
        $this->installAssets();
    }

    private function clearCache()
    {
        // FIXME: check that APP_ENV is taken into account, the same for the APP_DEBUG, ...
// FIXME: see which flags to pass (not about keeping the possibility to not warmup the cache here)
// FIXME: The problem is that the output will be filtered by the StreamIO of Composer-API, how to display something still?
        $this->execute('cache:clear');
    }

    private function installAssets()
    {
        $this->execute('assets:install --symlink --relative '.escapeshellarg($this->options['web-dir']));
    }

    private function execute($cmd)
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$php = $phpFinder->find(false)) {
            throw new \RuntimeException('The PHP executable could not be found, add it to your PATH and try again.');
        }

        $arguments = $phpFinder->findArguments();
        if (false !== $ini = php_ini_loaded_file()) {
            $arguments[] = '--php-ini='.$ini;
        }
        $phpArgs = implode(' ', array_map('escapeshellarg', $arguments));

        $console = escapeshellarg($this->options['bin-dir'].'/console');
        if ($this->io->isDecorated()) {
            $console .= ' --ansi';
        }
        $process = new Process($php.($phpArgs ? ' '.$phpArgs : '').' '.$console.' '.$cmd, null, null, null, $this->composer->getConfig()->get('process-timeout'));
        $io = $this->io;
        $process->run(function ($type, $buffer) use ($io) { $io->write($buffer, false); });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf("An error occurred when executing the \"%s\" command:\n\n%s\n\n%s.", escapeshellarg($cmd), $process->getOutput(), $process->getErrorOutput()));
        }
    }

    private function filterPackageNames(Package $package)
    {
        foreach ($package->getNames() as $name) {
            if (!is_dir($this->getRecipesDir().'/'.$name)) {
                continue;
            }

            yield $name;
        }
    }

    private function getConfigurator($name)
    {
        $class = 'Symfony\Recipes\\'.$this->getPackageNamespace($name).'\Configurator';
        if (!class_exists($class)) {
            $class = 'Symfony\Start\PackageConfigurator';
        }

        return new $class($this->composer, $this->io, $this->options);
    }

    private function initOptions()
    {
        $this->options = array_merge(array(
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'web-dir' => 'web',
//            'cache-warmup' => true,
        ), $this->composer->getPackage()->getExtra());

//        $this->options['cache-warmup'] = getenv('CACHE_WARMUP') ?: $this->options['cache-warmup'];
    }

    public function getPackageNamespace($package)
    {
        list($vendor, $project) = explode('/', $package);

        $nameFixer = function ($name) {
            return str_replace(array('.', '_', '-'), array('', '', ''), $name);
        };

        return $nameFixer($vendor).'\\'.$nameFixer($project);
    }

    private function getRecipesDir()
    {
        return __DIR__.'/../recipes';
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'installConfig',
            PackageEvents::POST_PACKAGE_UPDATE => 'updateConfig',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'removeConfig',
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
        );
    }
}
