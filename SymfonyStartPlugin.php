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

        $dir = __DIR__.'/../symfony-start/config-repo/'.$package->getName();
        if (!is_dir($dir)) {
            return;
        }

        $this->io->write(sprintf('    Auto-configuring "%s" as a Symfony bundle', $package->getName()));
        $this->addBundle($package, $dir);
        $this->addBundleConfig($package, $dir);
    }

    public function updateConfig(PackageEvent $event)
    {
    }

    public function removeConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();

        $dir = __DIR__.'/../symfony-start/config-repo/'.$package->getName();
        if (!is_dir($dir)) {
            return;
        }

        $this->io->write(sprintf('    Auto-deconfiguring "%s" as a Symfony bundle', $package->getName()));
        $this->removeBundle($package, $dir);
        $this->removeBundleConfig($package, $dir);
    }

    private function addBundle($package, $dir)
    {
        $this->io->write(sprintf('      - Adding "%s" from bundles.ini', $package->getName()));
        $bundlesini = getcwd().'/conf/bundles.ini';
// FIXME: be sure to not add a bundle twice
        $contents = file_get_contents($bundlesini);
        foreach ($this->parseBundles($dir) as $class => $envs) {
            $contents .= "$class = $envs\n";
        }
        file_put_contents($bundlesini, $contents);
    }

    private function addBundleConfig($package, $dir)
    {
        $target = getcwd().'/conf';
        if (!is_dir($dir.'/conf')) {
            return;
        }
        $this->io->write(sprintf('      - Configuring "%s"', $package->getName()));
// FIXME: make this conf/ directory configurable via composer.json
// FIXME: how to manage different versions/branches?
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/conf', \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
// FIXME: ADD the possibility to fill-in some parameters via questions (and sensible default values)
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!is_dir($new = $target.'/'.$iterator->getSubPathName())) {
                    mkdir($new);
                }
            } else {
                copy($item, $target.'/'.$iterator->getSubPathName());
            }
        }
    }

    private function removeBundle($package, $dir)
    {
        $this->io->write(sprintf('      - Removing "%s" from bundles.ini', $package->getName()));
        $bundlesini = getcwd().'/conf/bundles.ini';
        $contents = file_get_contents($bundlesini);
        foreach (array_keys($this->parseBundles($dir)) as $class) {
            $contents = preg_replace('/^'.preg_quote($class, '/').'.+$/m', '', $contents);
            $contents = preg_replace("/\n+/", "\n", $contents);
        }
        file_put_contents($bundlesini, $contents);
    }

    private function removeBundleConfig($package, $dir)
    {
        $target = getcwd().'/conf';
        if (!is_dir($dir.'/conf')) {
            return;
        }
        $this->io->write(sprintf('      - Removing configuration for "%s"', $package->getName()));
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/conf', \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                @unlink($target.'/'.$iterator->getSubPathName());
            }
        }
    }

    private function parseBundles($dir)
    {
        $bundles = [];
        foreach (parse_ini_file($dir.'/bundles.ini') as $class => $envs) {
            $bundles[$class] = $envs;
        }

        return $bundles;
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
