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

use Composer\Command\CreateProjectCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\PackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
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
    private $isInstall = false;
    private static $activated = true;

    public function activate(Composer $composer, IOInterface $io)
    {
        if (!extension_loaded('openssl')) {
            self::$activated = false;
            $this->io->writeError('<warning>Symfony Flex has been disabled. You must enable the openssl extension in your "php.ini" file.</warning>');

            return;
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->downloader = new Downloader($composer, $io);
        $this->downloader->setFlexId($this->getFlexId());

        $search = 3;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $trace) {
            if (!isset($trace['object'])) {
                continue;
            }

            if ($trace['object'] instanceof Application) {
                --$search;
                $resolver = new PackageResolver($this->downloader);
                $trace['object']->add(new Command\RequireCommand($resolver));
                $trace['object']->add(new Command\UpdateCommand($resolver));
                $trace['object']->add(new Command\RemoveCommand($resolver));
            } elseif ($trace['object'] instanceof Installer) {
                --$search;
                $trace['object']->setSuggestedPackagesReporter(new SuggestedPackagesReporter(new NullIO()));
            } elseif ($trace['object'] instanceof CreateProjectCommand) {
                --$search;
                if ($io instanceof ConsoleIO) {
                    $p = new \ReflectionProperty($io, 'input');
                    $p->setAccessible(true);
                    $p->getValue($io)->setInteractive(false);
                }
            }

            if (0 === $search) {
                break;
            }
        }
    }

    public function configureProject(Event $event): void
    {
        if ($this->isInstall) {
            return;
        }

        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        // 'name' and 'description' are only required for public packages
        $manipulator->removeProperty('name');
        $manipulator->removeProperty('description');
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function configurePackage(PackageEvent $event): void
    {
        if ($this->isInstall) {
            return;
        }

        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package, 'install') as $name => $response) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $recipe = new Recipe($package, $name, $response->getBody());
            $this->configurator->install($recipe);

            $manifest = $recipe->getManifest();
            if (isset($manifest['post-install-output'])) {
                $this->postInstallOutput = array_merge($this->postInstallOutput, $manifest['post-install-output'], ['']);
            }
        }
    }

    public function reconfigurePackage(PackageEvent $event): void
    {
        if ($this->isInstall) {
            return;
        }

        $package = $event->getOperation()->getTargetPackage();
        // called for the side effect of checking security issues
        $this->filterPackageNames($package, 'update');
    }

    public function unconfigurePackage(PackageEvent $event): void
    {
        if ($this->isInstall) {
            return;
        }

        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package, 'uninstall') as $name => $response) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $this->configurator->unconfigure(new Recipe($package, $name, $response->getBody()));
        }
    }

    public function postInstall(Event $event): void
    {
        if ($this->isInstall) {
            return;
        }

        $this->postUpdate($event);
    }

    public function postUpdate(Event $event): void
    {
        if ($this->isInstall) {
            return;
        }

        if (!file_exists(getcwd().'/.env') && file_exists(getcwd().'/.env.dist')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
    }

    public function executeAutoScripts(Event $event): void
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

    public function disableFlexOnInstall(CommandEvent $event): void
    {
        if ('install' === $event->getCommandName()) {
            $this->isInstall = true;
        }
    }

    private function filterPackageNames(PackageInterface $package, string $operation)
    {
        // FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        $response = $this->getPackageRecipe($package, $name, $operation);

        if (('install' === $operation || 'update' === $operation) && $values = $response->getHeaders('Security-Adv')) {
            $this->io->writeError('    <error>  Package has known vulnerabilities  </>');
            foreach ($values as $value) {
                $this->io->writeError(sprintf('      %s', $value));
            }
        }

        if (200 === $response->getStatusCode()) {
            yield $name => $response;
        }
    }

    private function initOptions(): Options
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

    private function getPackageRecipe(PackageInterface $package, string $name, string $operation): Response
    {
        $headers = ['Package-Operation: '.$operation];
        if ($date = $package->getReleaseDate()) {
            $headers[] = 'Package-Release: '.$date->format(\DateTime::RFC3339);
        }

        $version = $package->getPrettyVersion();
        if ('dev-master' === $version && $alias = $package->getExtra()['branch-alias']['dev-master'] ?? null) {
            $version = $alias;
        }

        return $this->downloader->get(sprintf('/recipes/%s/%s', $name, $version), $headers);
    }

    private function getFlexId(): ?string
    {
        $extra = $this->composer->getPackage()->getExtra();

        // don't want to be registered
        if (getenv('FLEX_SKIP_REGISTRATION') || !isset($extra['flex-id'])) {
            return null;
        }

        // already registered
        if ($extra['flex-id']) {
            return $extra['flex-id'];
        }

        // get a new ID
        $id = $this->downloader->get('/ulid')->getBody()['ulid'];

        // update composer.json
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addProperty('extra.flex-id', $id);
        file_put_contents($json->getPath(), $manipulator->getContents());

        return $id;
    }

    public static function getSubscribedEvents(): iterable
    {
        if (!self::$activated) {
            return [];
        }

        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'configurePackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'reconfigurePackage',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'unconfigurePackage',
            PluginEvents::COMMAND => 'disableFlexOnInstall',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
            'auto-scripts' => 'executeAutoScripts',
        ];
    }
}
