<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class SymfonyStartPlugin implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;
    private $map;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
        $map = array(
            'bundles' => Configurator\BundlesConfigurator::class,
            'composer-scripts' => Configurator\ComposerScriptsConfigurator::class,
            'copy-from-recipe' => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env' => Configurator\EnvConfigurator::class,
            'parameters' => Configurator\ParametersConfigurator::class,
        );
        foreach ($map as $key => $class) {
            $this->map[$key] = new $class($composer, $io, $this->options);
        }
    }

    public function configurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $this->install(new Recipe($package, $name, $this->getRecipesDir().'/'.$name));
        }
    }

    public function reconfigurePackage(PackageEvent $event)
    {
    }

    public function unconfigurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $this->uninstall(new Recipe($package, $name, $this->getRecipesDir().'/'.$name));
        }
    }

    public function postInstall(Event $event)
    {
        $this->postUpdate($event);
    }

    public function postUpdate(Event $event)
    {
        if (!file_exists(getcwd().'/.env')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
    }

    public function executeAutoScripts(Event $event)
    {
        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        if (isset($jsonContents['scripts']['auto-scripts'])) {
            $executor = new ScriptExecutor($this->composer, $this->io, $this->options);
            foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
                $executor->execute($type, $cmd);
            }
        }

        $event->stopPropagation();
    }

    private function install(Recipe $recipe)
    {
        $json = new JsonFile($recipe->getDir().'/manifest.json', null, $this->io);
        $manifest = $json->read();
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->configure($recipe, $config);
        }
    }

    private function uninstall(Recipe $recipe)
    {
        $json = new JsonFile($recipe->getDir().'/manifest.json', null, $this->io);
        $manifest = $json->read();
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->unconfigure($recipe, $config);
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
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
            'auto-scripts' => 'executeAutoScripts',
        );
    }
}
