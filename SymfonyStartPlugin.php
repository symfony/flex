<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
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
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->unconfigure($package, $name, __DIR__.'/recipes/'.$name);
        }
    }

    public function postCreate(Event $event)
    {
        // create .env
        copy(getcwd().'/.env.dist', getcwd().'/.env');

        $requirements = array(
            new PackageRequirement('symfony/framework-bundle','3.2.x-dev', false),
        );

        $json = new JsonFile(Factory::getComposerFile());
        if (!$this->updateFileCleanly($json, $requirements)) {
            $def = $json->read();
            foreach ($requirements as $requirement) {
                $def[$requirement->getRequireKey()][$requirement->getPackage()] = $requirement->getConstraint();
                unset($def[$requirement->getRemoveKey()][$requirement->getPackage()]);
            }
            $json->write($def);
        }
    }

    public function postInstall(Event $event)
    {
        
    }

    public function postUpdate(Event $event)
    {
        
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

    private function updateFileCleanly($json, array $requirements)
    {
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        foreach ($requirements as $requirement) {
            if (!$manipulator->addLink($requirement->getRequireKey(), $requirement->getPackage(), $requirement->getConstraint())) {
                return false;
            }
            if (!$manipulator->removeSubNode($requirement->getRemoveKey(), $requirement->getPackage())) {
                return false;
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'installConfig',
            PackageEvents::POST_PACKAGE_UPDATE => 'updateConfig',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'removeConfig',

            ScriptEvents::POST_CREATE_PROJECT_CMD => 'postCreate',

            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
        );
    }
}

class PackageRequirement
{
    private $package;
    private $constraint;
    private $dev;

    public function __construct($package, $constraint, $dev)
    {
        $this->package = $package;
        $this->constraint = $constraint;
        $this->dev = (bool) $dev;

        $versionParser = new VersionParser();
        $versionParser->parseConstraints($constraint);
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function isDev()
    {
        return $this->dev;
    }

    public function getRemoveKey()
    {
        return $this->dev ? 'require' : 'require-dev';
    }

    public function getRequireKey()
    {
        return $this->dev ? 'require-dev' : 'require';
    }
}
