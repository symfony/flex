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
use Symfony\Start\PackageConfigurator;

class SymfonyStartPlugin implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
    }

    public function configurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $configurator = new PackageConfigurator($this->composer, $this->io, $this->options);
            $configurator->configure($package, $name, $this->getRecipesDir().'/'.$name);
            $this->io->write('');
        }
    }

    public function updatePackage(PackageEvent $event)
    {
    }

    public function unconfigurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $configurator = new PackageConfigurator($this->composer, $this->io, $this->options);
            $configurator->unconfigure($package, $name, $this->getRecipesDir().'/'.$name);
        }
    }

    public function executeAutoScripts(Event $event)
    {
        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        if (isset($jsonContents['scripts']['auto-scripts'])) {
            $process = new ScriptExecutor($this->composer, $this->io, $this->options);
            foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
                $process->execute($type, $cmd);
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

    private function initOptions()
    {
        $options = array_merge(array(
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'web-dir' => 'web',
//            'cache-warmup' => true,
        ), $this->composer->getPackage()->getExtra());

//        $options['cache-warmup'] = getenv('CACHE_WARMUP') ?: $options['cache-warmup'];

        return new Options($options);
    }

    private function getRecipesDir()
    {
        return __DIR__.'/../recipes';
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'configurePackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'updatePackage',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'unconfigurePackage',
            'auto-scripts' => 'executeAutoScripts',
        );
    }
}
