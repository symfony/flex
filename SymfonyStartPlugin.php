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

        $this->io->write('    Detected auto-configuration settings');
        $this->addBundle($package, $dir);
        $this->addBundleConfig($package, $dir);
        $this->io->write('');
    }

    public function updateConfig(PackageEvent $event)
    {
    }

// FIXME: actually, this mechanism works for any package
// When installing Doctrine annotation, we can override the framework annotations to true for instance?

    public function removeConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();

        $dir = __DIR__.'/../symfony-start/config-repo/'.$package->getName();
        if (!is_dir($dir)) {
            return;
        }

        $this->io->write('    Auto-deconfiguring');
        $this->removeBundle($package, $dir);
        $this->removeBundleConfig($package, $dir);
    }

    private function addBundle($package, $dir)
    {
        $bundlesini = getcwd().'/conf/bundles.ini';
        if (!file_exists($bundlesini)) {
            return;
        }
        $this->io->write('    Enabling the package as a Symfony bundle');
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
        $this->io->write('    Setting default bundle configuration');
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
        $bundlesini = getcwd().'/conf/bundles.ini';
        if (!file_exists($bundlesini)) {
            return;
        }
        $this->io->write('    Disabling the package from Symfony bundles');
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
        $this->io->write('    Removing bundle configuration');
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
