<?php

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\PackageEvent;

class SymfonyStartPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
//        $installer = new SymfonyStartBundleManager($io, $composer);
//        $composer->getInstallationManager()->addInstaller($installer);
    }

    public function installConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();

        $dir = __DIR__.'/../../fabpot/symfony-start/config-repo/'.$package->getName();
        if (!is_dir($dir)) {
            return;
        }

        $this->io->write(sprintf('Auto-configuring "%s" as a Symfony bundle', $package->getName()));

        print getcwd()."***\n";
    }

    public function updateConfig(PackageEvent $event)
    {
        
    }

    public function removeConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();

        $dir = __DIR__.'/../../fabpot/symfony-start/config-repo/'.$package->getName();
        if (!is_dir($dir)) {
            return;
        }

        $this->io->write(sprintf('Auto-deconfiguring "%s" as a Symfony bundle', $package->getName()));

        print getcwd()."***\n";
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'installConfig',
            PackageEvents::POST_PACKAGE_UPDATE => 'updateConfig',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'removeConfig',
        );
    }
}

/*
class SymfonyStartBundleManager
{
}
*/
