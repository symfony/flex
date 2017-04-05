<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Composer;
use Composer\Console\Application;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Flex implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;
    private $configurator;
    private $downloader;
    private $postInstallOutput = [''];
    private static $activated = true;

    public function activate(Composer $composer, IOInterface $io)
    {
        if (!extension_loaded('openssl')) {
            self::$activated = false;
            $this->io->writeError('<warning>Symfony Flex has been disabled. You must enable the openssl extension in your php.ini to use it.</warning>');

            return;
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->downloader = new Downloader($composer, $io);
        $this->downloader->setFlexId($this->getFlexId());

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $trace) {
            if (isset($trace['object']) && $trace['object'] instanceof Application) {
                $resolver = new PackageResolver($this->downloader);
                $trace['object']->add(new Command\RequireCommand($resolver));
                $trace['object']->add(new Command\UpdateCommand($resolver));
                $trace['object']->add(new Command\RemoveCommand($resolver));
                break;
            }
        }
    }

    public function configureProject(Event $event)
    {
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        // 'name' and 'description' are only required for public packages
        $manipulator->removeProperty('name');
        $manipulator->removeProperty('description');
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function configurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package, 'install') as $name => $data) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $recipe = new Recipe($package, $name, $data);
            $this->configurator->install($recipe);

            $manifest = $recipe->getManifest();
            if (isset($manifest['post-install-output'])) {
                $this->postInstallOutput = array_merge($this->postInstallOutput, $manifest['post-install-output'], ['']);
            }
        }
    }

    public function reconfigurePackage(PackageEvent $event)
    {
    }

    public function unconfigurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package, 'uninstall') as $name => $data) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $this->configurator->unconfigure(new Recipe($package, $name, $data));
        }
    }

    public function postInstall(Event $event)
    {
        $this->postUpdate($event);
    }

    public function postUpdate(Event $event)
    {
        if (!file_exists(getcwd().'/.env') && file_exists(getcwd().'/.env.dist')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
    }

    public function executeAutoScripts(Event $event)
    {
        $event->stopPropagation();

        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        $executor = new ScriptExecutor($this->composer, $this->io, $this->options);
        foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
            $executor->execute($type, $cmd);
        }

        $this->io->write($this->postInstallOutput);
    }

    private function filterPackageNames(PackageInterface $package, $operation)
    {
        // FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        if ($body = $this->getPackageRecipe($package, $name, $operation)) {
            yield $name => $body;
        }
    }

    private function initOptions()
    {
        $options = array_merge([
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'web-dir' => 'web',
        ], $this->composer->getPackage()->getExtra());

        return new Options($options);
    }

    private function getPackageRecipe(PackageInterface $package, $name, $operation)
    {
        $headers = ['Package-Operation: '.$operation];
        if ($date = $package->getReleaseDate()) {
            $headers[] = 'Package-Release: '.$date->format(\DateTime::RFC3339);
        }

        if ($alias = $package->getExtra()['branch-alias']['dev-master'] ?? null) {
            $version = $alias;
        } else {
            $version = $package->getPrettyVersion();
        }

        return $this->downloader->getContents(sprintf('/recipes/%s/%s', $name, $version), $headers);
    }

    private function getFlexId()
    {
        $extra = $this->composer->getPackage()->getExtra();

        // don't want to be registered
        if (getenv('FLEX_SKIP_REGISTRATION') || !isset($extra['flex-id'])) {
            return;
        }

        // already registered
        if ($extra['flex-id']) {
            return $extra['flex-id'];
        }

        // get a new ID
        $id = $this->downloader->getContents('/ulid')['ulid'];

        // update composer.json
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addProperty('extra.flex-id', $id);
        file_put_contents($json->getPath(), $manipulator->getContents());

        return $id;
    }

    public static function getSubscribedEvents()
    {
        if (!self::$activated) {
            return [];
        }

        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'configurePackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'reconfigurePackage',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'unconfigurePackage',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
            'auto-scripts' => 'executeAutoScripts',
        ];
    }
}
