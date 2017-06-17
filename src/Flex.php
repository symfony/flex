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
use Composer\Command\InstallCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\UpdateOperation;
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
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Application as ConsoleApplication;

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
    private $runningCommand;
    private $operations = [];
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
        $extra = $composer->getPackage()->getExtra();
        $this->downloader->allowContrib($extra['symfony']['allow-contrib'] ?? false);
        $this->downloader->setRepositories($extra['symfony']['repositories'] ?? []);
        $this->runningCommand = function () { return; };

        $search = 3;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $trace) {
            if (!isset($trace['object'])) {
                continue;
            }

            if ($trace['object'] instanceof Application) {
                --$search;
                $app = $trace['object'];
                $resolver = new PackageResolver($this->downloader);
                $app->add(new Command\RequireCommand($resolver));
                $app->add(new Command\UpdateCommand($resolver));
                $app->add(new Command\RemoveCommand($resolver));

                $r = new \ReflectionProperty(ConsoleApplication::class, 'runningCommand');
                $r->setAccessible(true);
                $this->runningCommand = function () use ($app, $r) {
                    return $r->getValue($app);
                };
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
        if (($this->runningCommand)() instanceof InstallCommand) {
            return;
        }

        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        // 'name' and 'description' are only required for public packages
        $manipulator->removeProperty('name');
        $manipulator->removeProperty('description');
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function record(PackageEvent $event): void
    {
        $this->operations[] = $event->getOperation();
    }

    public function install(Event $event): void
    {
        if (($this->runningCommand)() instanceof InstallCommand) {
            return;
        }

        $this->update($event);
    }

    public function update(Event $event): void
    {
        if (($this->runningCommand)() instanceof InstallCommand) {
            return;
        }

        if (!file_exists(getcwd().'/.env') && file_exists(getcwd().'/.env.dist')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }

        list($recipes, $vulnerabilities) = $this->fetchRecipes();
        if ($vulnerabilities) {
            $this->io->writeError(sprintf('<info>Vulnerabilities: %d package%s</>', count($vulnerabilities), count($recipes) > 1 ? 's' : ''));
        }
        foreach ($vulnerabilities as $name => $vulns) {
            foreach ($vulns as $v) {
                $this->io->writeError(sprintf('  - <error>Vulnerability on %s</>: %s', $name, $v));
            }
        }

        if (!$recipes) {
            return;
        }

        $this->io->writeError(sprintf('<info>Symfony operations: %d recipe%s</>', count($recipes), count($recipes) > 1 ? 's' : ''));
        foreach ($recipes as $recipe) {
            if ($recipe->isNotInstallable() && $recipe->isEmpty()) {
                $this->io->writeError(sprintf('  - <warning>Ignoring</> recipe %s', $this->formatOrigin($recipe->getOrigin())));
                $this->io->writeError('    Enable via composer config extra.symfony.allow-contrib true');
                continue;
            }

            switch ($recipe->getJob()) {
                case 'install':
                    $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->install($recipe);
                    $manifest = $recipe->getManifest();
                    if (isset($manifest['post-install-output'])) {
                        foreach ($manifest['post-install-output'] as $line) {
                            $this->postInstallOutput[] = $this->options->expandTargetDir($line);
                        }
                        $this->postInstallOutput[] = '';
                    }
                    break;
                case 'update':
                    break;
                case 'uninstall':
                    $this->io->writeError(sprintf('  - Unconfiguring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->unconfigure($recipe);
                    break;
            }
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

    private function fetchRecipes(): array
    {
        $devPackages = null;
        $data = $this->downloader->getRecipes($this->operations);
        $manifests = $data['manifests'] ?? [];
        $recipes = [];
        foreach ($this->operations as $i => $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            // FIXME: getNames() can return n names
            $name = $package->getNames()[0];
            $job = $operation->getJobType();

            if (isset($manifests[$name])) {
                $recipes[] = new Recipe($package, $name, $job, $manifests[$name]);
            }

            $noRecipe = !isset($manifests[$name]) || (isset($manifests[$name]['not_installable']) && $manifests[$name]['not_installable']);
            if ($noRecipe && 'symfony-bundle' === $package->getType()) {
                $manifest = [];
                $bundle = new SymfonyBundle($this->composer, $package, $job);
                if (null === $devPackages) {
                    $devPackages = array_map(function ($package) { return $package['name']; }, $this->composer->getLocker()->getLockData()['packages-dev']);
                }
                $envs = in_array($name, $devPackages) ? ['dev', 'test'] : ['all'];
                foreach ($bundle->getClassNames() as $class) {
                    $manifest['manifest']['bundles'][$class] = $envs;
                }
                if ($manifest) {
                    $manifest['origin'] = sprintf('%s:%s@auto-generated recipe', $name, $package->getPrettyVersion());
                    $recipes[] = new Recipe($package, $name, $job, $manifest);
                }
            }
        }
        $this->operations = [];

        return [$recipes, $data['vulnerabilities'] ?? []];
    }

    private function initOptions(): Options
    {
        $options = array_merge([
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'var-dir' => 'var',
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
        if (0 === strpos($version, 'dev-') && isset($package->getExtra()['branch-alias'])) {
            $branchAliases = $package->getExtra()['branch-alias'];
            if (($alias = $branchAliases[$version]) || ($alias = $branchAliases['dev-master'])) {
                $version = $alias;
            }
        }

        return $this->downloader->get(sprintf('/m/%s/%s', $name, $version), $headers);
    }

    private function getFlexId(): ?string
    {
        $extra = $this->composer->getPackage()->getExtra();

        // don't want to be registered
        if (getenv('SYMFONY_SKIP_REGISTRATION') || !isset($extra['symfony']['id'])) {
            return null;
        }

        // already registered
        if ($extra['symfony']['id']) {
            return $extra['symfony']['id'];
        }

        // get a new ID
        $id = $this->downloader->get('/ulid')->getBody()['ulid'];

        // update composer.json
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addProperty('extra.symfony.id', $id);
        file_put_contents($json->getPath(), $manipulator->getContents());

        return $id;
    }

    private function formatOrigin(string $origin): string
    {
        // symfony/translation:3.3@github.com/symfony/recipes:master
        if (!preg_match('/^([^\:]+?)\:([^\@]+)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</> (<comment>%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>'.$matches[3].'</>' : $matches[3]);
    }

    public static function getSubscribedEvents(): iterable
    {
        if (!self::$activated) {
            return [];
        }

        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'record',
            PackageEvents::POST_PACKAGE_UPDATE => 'record',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'record',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::POST_UPDATE_CMD => 'update',
            'auto-scripts' => 'executeAutoScripts',
        ];
    }
}
