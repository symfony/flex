<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\PackageEvent;
use Composer\Script\ScriptEvents;

class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function installConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->configure($package, $name, __DIR__.'/recipes/'.$name);
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
            $this->io->write(sprintf('    Auto-deconfiguring "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->unconfigure($package, $name, __DIR__.'/recipes/'.$name);
        }
    }

    private function filterPackageNames(Package $package)
    {
        foreach ($package->getNames() as $name) {
            if (!is_dir(__DIR__.'/recipes/'.$name)) {
                continue;
            }

            yield $name;
        }
    }

    private function getConfigurator($name)
    {
        $class = 'Symfony\\Start\\'.$name.'\\Configurator';
        if (!class_exists($class)) {
            $class = 'Symfony\Start\PackageConfigurator';
        }

        return new $class($this->composer, $this->io);
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'installConfig',
            PackageEvents::POST_PACKAGE_UPDATE => 'updateConfig',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'removeConfig',

//            ScriptEvents::POST_INSTALL_CMD => 'updateEnv',
//            ScriptEvents::POST_UPDATE_CMD => 'updateEnv',
        );
    }
}
