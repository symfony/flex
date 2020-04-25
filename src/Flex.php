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

use Composer\Command\GlobalCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\NoopInstaller;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\BasePackage;
use Composer\Package\Comparer\Comparer;
use Composer\Package\Locker;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Flex\Event\UpdateEvent;
use Symfony\Thanks\Thanks;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Flex implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $config;
    private $options;
    private $configurator;
    private $downloader;
    private $installer;
    private $postInstallOutput = [''];
    private $operations = [];
    private $lock;
    private $displayThanksReminder = 0;
    private static $activated = true;
    private static $aliasResolveCommands = [
        'require' => true,
        'update' => false,
        'remove' => false,
        'unpack' => true,
    ];
    private $shouldUpdateComposerLock = false;
    private $filter;

    public function activate(Composer $composer, IOInterface $io)
    {
        if (!\extension_loaded('openssl')) {
            self::$activated = false;
            $io->writeError('<warning>Symfony Flex has been disabled. You must enable the openssl extension in your "php.ini" file.</>');

            return;
        }

        // to avoid issues when Flex is upgraded, we load all PHP classes now
        // that way, we are sure to use all classes from the same version
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('.php' === substr($file, -4)) {
                class_exists(__NAMESPACE__.str_replace('/', '\\', substr($file, \strlen(__DIR__), -4)));
            }
        }

        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->options = $this->initOptions();

        $rfs = Factory::createHttpDownloader($this->io, $this->config);

        $this->downloader = $downloader = new Downloader($composer, $io, $rfs);
        $this->downloader->setFlexId($this->getFlexId());

        $symfonyRequire = getenv('SYMFONY_REQUIRE') ?: ($composer->getPackage()->getExtra()['symfony']['require'] ?? null);
        if ($symfonyRequire) {
            $this->filter = new PackageFilter($io, $symfonyRequire, $this->downloader);
        }

        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->lock = new Lock(getenv('SYMFONY_LOCKFILE') ?: str_replace('composer.json', 'symfony.lock', Factory::getComposerFile()));

        $disable = true;
        foreach (array_merge($composer->getPackage()->getRequires() ?? [], $composer->getPackage()->getDevRequires() ?? []) as $link) {
            // recipes apply only when symfony/flex is found in "require" or "require-dev" in the root package
            if ('symfony/flex' === $link->getTarget()) {
                $disable = false;
                break;
            }
        }
        if ($disable) {
            $downloader->disable();
        }

        $backtrace = $this->configureInstaller();

        foreach ($backtrace as $trace) {
            if (!isset($trace['object']) || !isset($trace['args'][0])) {
                continue;
            }

            if (!$trace['object'] instanceof Application || !$trace['args'][0] instanceof ArgvInput) {
                continue;
            }

            // In Composer 1.0.*, $input knows about option and argument definitions
            // Since Composer >=1.1, $input contains only raw values
            $input = $trace['args'][0];
            $app = $trace['object'];

            $resolver = new PackageResolver($this->downloader);

            try {
                $command = $input->getFirstArgument();
                $command = $command ? $app->find($command)->getName() : null;
            } catch (\InvalidArgumentException $e) {
            }

            if ('create-project' === $command) {
                // detect Composer >=1.7 (using the Composer::VERSION constant doesn't work with snapshot builds)
                if (class_exists(Comparer::class)) {
                    if ($input->hasOption('remove-vcs')) {
                        $input->setOption('remove-vcs', true);
                    }
                } else {
                    $input->setInteractive(false);
                }
            } elseif ('update' === $command) {
                $this->displayThanksReminder = 1;
            } elseif ('outdated' === $command) {
                $symfonyRequire = null;
            }

            if (isset(self::$aliasResolveCommands[$command])) {
                // early resolve for BC with Composer 1.0
                if ($input->hasArgument('packages')) {
                    $input->setArgument('packages', $resolver->resolve($input->getArgument('packages'), self::$aliasResolveCommands[$command]));
                }

                if ($input->hasOption('no-suggest')) {
                    $input->setOption('no-suggest', true);
                }
            }

            if ($input->hasParameterOption('--prefer-lowest', true)) {
                // When prefer-lowest is set and no stable version has been released,
                // we consider "dev" more stable than "alpha", "beta" or "RC". This
                // allows testing lowest versions with potential fixes applied.
                BasePackage::$stabilities['dev'] = 1 + BasePackage::STABILITY_STABLE;
            }

            $app->add(new Command\RequireCommand($resolver));
            $app->add(new Command\UpdateCommand($resolver));
            $app->add(new Command\RemoveCommand($resolver));
            $app->add(new Command\UnpackCommand($resolver));
            $app->add(new Command\RecipesCommand($this, $this->lock, $rfs));
            $app->add(new Command\InstallRecipesCommand($this, $this->options->get('root-dir')));
            $app->add(new Command\GenerateIdCommand($this));
            $app->add(new Command\DumpEnvCommand($this->config, $this->options));

            break;
        }
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        self::$activated = false;
    }

    public function configureInstaller()
    {
        $backtrace = debug_backtrace();
        foreach ($backtrace as $trace) {
            if (isset($trace['object']) && $trace['object'] instanceof Installer) {
                $this->installer = \Closure::bind(function () { return $this->update ? $this : null; }, $trace['object'], $trace['object'])();
                $trace['object']->setSuggestedPackagesReporter(new SuggestedPackagesReporter(new NullIO()));
            }

            if (isset($trace['object']) && $trace['object'] instanceof GlobalCommand) {
                $this->downloader->disable();
            }
        }

        return $backtrace;
    }

    public function lockPlatform()
    {
        if (!$this->downloader->isEnabled()) {
            return; // "symfony/flex" not found in the root composer.json - don't create the symfony.lock file
        }

        $this->lock->set('php', [
            'version' => $this->config->get('platform')['php'] ?? (PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION),
        ]);
    }

    public function configureProject(Event $event)
    {
        if (!$this->downloader->isEnabled()) {
            $this->io->writeError('<warning>Project configuration is disabled: "symfony/flex" not found in the root composer.json</>');

            return;
        }

        $json = new JsonFile(Factory::getComposerFile());
        $contents = file_get_contents($json->getPath());
        $manipulator = new JsonManipulator($contents);

        // new projects are most of the time proprietary
        $manipulator->addMainKey('license', 'proprietary');

        // replace unbounded constraints for symfony/* packages by extra.symfony.require
        $config = json_decode($contents, true);
        if ($symfonyVersion = $config['extra']['symfony']['require'] ?? null) {
            $versions = $this->downloader->getVersions();
            foreach (['require', 'require-dev'] as $type) {
                foreach ($config[$type] ?? [] as $package => $version) {
                    if ('*' === $version && isset($versions['splits'][$package])) {
                        $manipulator->addLink($type, $package, $symfonyVersion);
                    }
                }
            }
        }

        // 'name' and 'description' are only required for public packages
        // don't use $manipulator->removeProperty() for BC with Composer 1.0
        $contents = preg_replace(['{^\s*+"name":.*,$\n}m', '{^\s*+"description":.*,$\n}m'], '', $manipulator->getContents(), 1);
        file_put_contents($json->getPath(), $contents);

        $this->updateComposerLock();
    }

    public function record(PackageEvent $event)
    {
        if ($this->shouldRecordOperation($event)) {
            $this->operations[] = $event->getOperation();
        }
    }

    public function checkForUpdate(PackageEvent $event)
    {
        if (null === $this->installer || 'symfony/flex' !== $event->getOperation()->getPackage()->getName()) {
            return;
        }

        $this->update();
        $this->composer->getInstallationManager()->addInstaller(new NoopInstaller());

        \Closure::bind(function () {
            $this->io = new NullIO();
            $this->writeLock = false;
            $this->executeOperations = false;
            $this->dumpAutoloader = false;
            $this->runScripts = false;
        }, $this->installer, $this->installer)();
    }

    public function update(Event $event = null, $operations = [])
    {
        if ($operations) {
            $this->operations = $operations;
        }

        $this->install($event);

        $jsonPath = Factory::getComposerFile();
        $json = file_get_contents($jsonPath);
        $manipulator = new JsonManipulator($json);
        $json = json_decode($json, true);

        if (null === $event) {
            // called from checkForUpdate()
        } elseif (null === $this->installer || (!isset($json['flex-require']) && !isset($json['flex-require-dev']))) {
            return;
        } else {
            $event->stopPropagation();
        }

        $sortPackages = $this->composer->getConfig()->get('sort-packages');

        foreach (['require', 'require-dev'] as $type) {
            if (isset($json['flex-'.$type])) {
                foreach ($json['flex-'.$type] as $package => $constraint) {
                    $manipulator->addLink($type, $package, $constraint, $sortPackages);
                }

                $manipulator->removeMainKey('flex-'.$type);
            }
        }

        file_put_contents($jsonPath, $manipulator->getContents());

        $rm = $this->composer->getRepositoryManager();
        $package = Factory::create($this->io)->getPackage();
        $this->composer->setPackage($package);
        \Closure::bind(function () use ($package, $rm) {
            $this->package = $package;
            $this->repositoryManager = $rm;
        }, $this->installer, $this->installer)();
        $this->composer->getEventDispatcher()->__construct($this->composer, $this->io);

        $status = $this->installer->run();
        if (0 !== $status) {
            exit($status);
        }
    }

    public function install(Event $event = null)
    {
        $rootDir = $this->options->get('root-dir');

        if (!file_exists("$rootDir/.env") && !file_exists("$rootDir/.env.local") && file_exists("$rootDir/.env.dist") && false === strpos(file_get_contents("$rootDir/.env.dist"), '.env.local')) {
            copy($rootDir.'/.env.dist', $rootDir.'/.env');
        }

        $recipes = $this->fetchRecipes($this->operations);
        $this->operations = [];     // Reset the operation after getting recipes

        if (2 === $this->displayThanksReminder) {
            $love = '\\' === \DIRECTORY_SEPARATOR ? 'love' : '💖 ';
            $star = '\\' === \DIRECTORY_SEPARATOR ? 'star' : '★ ';

            $this->io->writeError('');
            $this->io->writeError('What about running <comment>composer global require symfony/thanks && composer thanks</> now?');
            $this->io->writeError(sprintf('This will spread some %s by sending a %s to the GitHub repositories of your fellow package maintainers.', $love, $star));
            $this->io->writeError('');
        }

        if (!$recipes) {
            $this->lock->write();

            return;
        }

        $this->io->writeError(sprintf('<info>Symfony operations: %d recipe%s (%s)</>', \count($recipes), \count($recipes) > 1 ? 's' : '', $this->downloader->getSessionId()));
        $installContribs = $this->composer->getPackage()->getExtra()['symfony']['allow-contrib'] ?? false;
        $manifest = null;
        foreach ($recipes as $recipe) {
            if ('install' === $recipe->getJob() && !$installContribs && $recipe->isContrib()) {
                $warning = $this->io->isInteractive() ? 'WARNING' : 'IGNORING';
                $this->io->writeError(sprintf('  - <warning> %s </> %s', $warning, $this->formatOrigin($recipe->getOrigin())));
                $question = sprintf('    The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Review the recipe at %s

    Do you want to execute this recipe?
    [<comment>y</>] Yes
    [<comment>n</>] No
    [<comment>a</>] Yes for all packages, only for the current installation session
    [<comment>p</>] Yes permanently, never ask again for this project
    (defaults to <comment>n</>): ', $recipe->getURL());
                $answer = $this->io->askAndValidate(
                    $question,
                    function ($value) {
                        if (null === $value) {
                            return 'n';
                        }
                        $value = strtolower($value[0]);
                        if (!\in_array($value, ['y', 'n', 'a', 'p'])) {
                            throw new \InvalidArgumentException('Invalid choice.');
                        }

                        return $value;
                    },
                    null,
                    'n'
                );
                if ('n' === $answer) {
                    continue;
                }
                if ('a' === $answer) {
                    $installContribs = true;
                }
                if ('p' === $answer) {
                    $installContribs = true;
                    $json = new JsonFile(Factory::getComposerFile());
                    $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
                    $manipulator->addSubNode('extra', 'symfony.allow-contrib', true);
                    file_put_contents($json->getPath(), $manipulator->getContents());
                    $this->shouldUpdateComposerLock = true;
                }
            }

            switch ($recipe->getJob()) {
                case 'install':
                    $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->install($recipe, $this->lock, [
                        'force' => $event instanceof UpdateEvent && $event->force(),
                    ]);
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
                    $this->configurator->unconfigure($recipe, $this->lock);
                    break;
            }
        }

        if (null !== $manifest) {
            array_unshift(
                $this->postInstallOutput,
                '',
                '<info>Some files may have been created or updated to configure your new packages.</>',
                'Please <comment>review</>, <comment>edit</> and <comment>commit</> them: these files are <comment>yours</>.'
            );
        }

        $this->lock->write();

        if ($this->shouldUpdateComposerLock) {
            $this->updateComposerLock();
        }
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $this->lock->delete();
    }

    public function enableThanksReminder()
    {
        if (1 === $this->displayThanksReminder) {
            $this->displayThanksReminder = !class_exists(Thanks::class, false) ? 2 : 0;
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

    public function generateFlexId()
    {
        if ($this->getFlexId()) {
            return;
        }

        if (!$this->downloader->isEnabled()) {
            throw new \LogicException('Cannot generate project id when "symfony/flex" is not found in the root composer.json.');
        }

        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('extra', 'symfony.id', $this->downloader->get('/ulid')->getBody()['ulid']);
        file_put_contents($json->getPath(), $manipulator->getContents());

        $this->updateComposerLock();
    }

    public function updateAutoloadFile()
    {
        if (!$platform = $this->lock->get('php')['version'] ?? null) {
            return;
        }

        $autoloadFile = $this->config->get('vendor-dir').'/autoload.php';

        if (!file_exists($autoloadFile)) {
            return;
        }

        $code = file_get_contents($autoloadFile);
        $code = substr($code, \strlen("<?php\n"));

        if (false !== strpos($code, 'PHP_VERSION_ID')) {
            return;
        }

        $platform = preg_replace('/[^-+.~_\w]/', '', $platform);
        $version = sprintf('%d%02d00', ...explode('.', $platform.'.0'));

        file_put_contents($autoloadFile, <<<EOPHP
<?php

if (\PHP_VERSION_ID < $version) {
    echo sprintf("Fatal Error: composer.lock was created for PHP version $platform or higher but the current PHP version is %d.%d.%d.\\n", PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION);
    exit(1);
}
$code
EOPHP
        );
    }

    public function fetchRecipes(array $operations): array
    {
        if (!$this->downloader->isEnabled()) {
            $this->io->writeError('<warning>Symfony recipes are disabled: "symfony/flex" not found in the root composer.json</>');

            return [];
        }
        $devPackages = null;
        $data = $this->downloader->getRecipes($operations);
        $manifests = $data['manifests'] ?? [];
        $locks = $data['locks'] ?? [];
        // symfony/flex and symfony/framework-bundle recipes should always be applied first
        $recipes = [
            'symfony/flex' => null,
            'symfony/framework-bundle' => null,
        ];
        foreach ($operations as $i => $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            // FIXME: Multi name with getNames()
            $name = $package->getName();
            $job = $operation->getOperationType();

            if (!empty($manifests[$name]['manifest']['conflict']) && !$operation instanceof UninstallOperation) {
                $lockedRepository = $this->composer->getLocker()->getLockedRepository();

                foreach ($manifests[$name]['manifest']['conflict'] as $conflictingPackage => $constraint) {
                    if ($lockedRepository->findPackage($conflictingPackage, $constraint)) {
                        $this->io->writeError(sprintf('  - Skipping recipe for %s: it conflicts with %s %s.', $name, $conflictingPackage, $constraint), true, IOInterface::VERBOSE);

                        continue 2;
                    }
                }
            }

            if ($operation instanceof InstallOperation && isset($locks[$name])) {
                $ref = $this->lock->get($name)['recipe']['ref'] ?? null;
                if ($ref && ($locks[$name]['recipe']['ref'] ?? null) === $ref) {
                    continue;
                }
                $this->lock->set($name, $locks[$name]);
            } elseif ($operation instanceof UninstallOperation) {
                if (!$this->lock->has($name)) {
                    continue;
                }
                $this->lock->remove($name);
            }

            if (isset($manifests[$name])) {
                $recipes[$name] = new Recipe($package, $name, $job, $manifests[$name], $locks[$name] ?? []);
            }

            $noRecipe = !isset($manifests[$name]) || (isset($manifests[$name]['not_installable']) && $manifests[$name]['not_installable']);
            if ($noRecipe) {
                $bundles = [];

                if (null === $devPackages) {
                    $devPackages = array_column($this->composer->getLocker()->getLockData()['packages-dev'], 'name');
                }
                $envs = \in_array($name, $devPackages) ? ['dev', 'test'] : ['all'];
                $bundle = new SymfonyBundle($this->composer, $package, $job);
                foreach ($bundle->getClassNames() as $bundleClass) {
                    $bundles[$bundleClass] = $envs;
                }

                if ($bundles) {
                    $manifest = [
                        'origin' => sprintf('%s:%s@auto-generated recipe', $name, $package->getPrettyVersion()),
                        'manifest' => ['bundles' => $bundles],
                    ];
                    $recipes[$name] = new Recipe($package, $name, $job, $manifest);
                }
            }
        }

        return array_filter($recipes);
    }

    public function truncatePackages(PrePoolCreateEvent $event)
    {
        if (!$this->filter) {
            return;
        }
        $event->setPackages($this->filter->removeLegacyPackages($event->getPackages()));
    }

    private function initOptions(): Options
    {
        $extra = $this->composer->getPackage()->getExtra();

        $options = array_merge([
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'config-dir' => 'config',
            'src-dir' => 'src',
            'var-dir' => 'var',
            'public-dir' => 'public',
            'root-dir' => $extra['symfony']['root-dir'] ?? '.',
        ], $extra);

        return new Options($options, $this->io);
    }

    private function getFlexId()
    {
        $extra = $this->composer->getPackage()->getExtra();

        return $extra['symfony']['id'] ?? null;
    }

    private function formatOrigin(string $origin): string
    {
        // symfony/translation:3.3@github.com/symfony/recipes:master
        if (!preg_match('/^([^\:]+?)\:([^\@]+)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>'.$matches[3].'</>' : $matches[3]);
    }

    private function shouldRecordOperation(PackageEvent $event): bool
    {
        $operation = $event->getOperation();
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            $package = $operation->getPackage();
        }

        // when Composer runs with --no-dev, ignore uninstall operations on packages from require-dev
        if (!$event->isDevMode() && $operation instanceof UninstallOperation) {
            foreach ($event->getComposer()->getLocker()->getLockData()['packages-dev'] as $p) {
                if ($package->getName() === $p['name']) {
                    return false;
                }
            }
        }

        // FIXME: Multi name with getNames()
        $name = $package->getName();
        if ($operation instanceof InstallOperation) {
            if (!$this->lock->has($name)) {
                return true;
            }
        } elseif ($operation instanceof UninstallOperation) {
            return true;
        }

        return false;
    }

    private function updateComposerLock()
    {
        $lock = substr(Factory::getComposerFile(), 0, -4).'lock';
        $composerJson = file_get_contents(Factory::getComposerFile());
        $lockFile = new JsonFile($lock, null, $this->io);
        $locker = new Locker($this->io, $lockFile, $this->composer->getInstallationManager(), $composerJson);
        $lockData = $locker->getLockData();
        $lockData['content-hash'] = Locker::getContentHash($composerJson);
        $lockFile->write($lockData);
    }

    public static function getSubscribedEvents(): array
    {
        if (!self::$activated) {
            return [];
        }

        return [
            PackageEvents::POST_PACKAGE_INSTALL => __CLASS__ === self::class ? [['record'], ['checkForUpdate']] : 'record',
            PackageEvents::POST_PACKAGE_UPDATE => [['record'], ['enableThanksReminder']],
            PackageEvents::POST_PACKAGE_UNINSTALL => 'record',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::PRE_UPDATE_CMD => 'configureInstaller',
            ScriptEvents::POST_UPDATE_CMD => 'update',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'updateAutoloadFile',
            PluginEvents::PRE_POOL_CREATE => 'truncatePackages',
            'auto-scripts' => 'executeAutoScripts',
        ];
    }
}
