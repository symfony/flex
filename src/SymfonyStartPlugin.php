<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\EventDispatcher\ScriptExecutionException;
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
use Composer\Util\ProcessExecutor;

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
    }

    public function executeAutoScripts(Event $event)
    {
        $process = new ProcessExecutor($this->io);
        $scripts = $this->composer->getPackage()->getScripts();
        $executor = new Executor();
        if (isset($scripts[$event->getName()])) {
            foreach ($scripts[$event->getName()] as $cmd => $type) {
                if ($this->io->isVerbose()) {
                    $this->io->writeError(sprintf('> %s: %s', $event->getName(), $cmd));
                } else {
                    $this->io->writeError(sprintf('> %s', $cmd));
                }
                if (0 !== $exitCode = $process->execute($cmd)) {
                    $this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code %s</error>', $cmd, $event->getName(), $exitCode));

                    throw new ScriptExecutionException(sprintf('Error Output: %s', $process->getErrorOutput()), $exitCode);
                }
            }
        }

        $event->stopPropagation();
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
            'auto-scripts' => 'executeAutoScripts',
        );
    }
}
