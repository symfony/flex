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
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\BasePackage;
use Composer\Package\Locker;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Event\UpdateEvent;
use Symfony\Flex\Unpack\Operation;
use Symfony\Thanks\Thanks;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Flex implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    private $config;
    private $options;
    private $configurator;
    private $downloader;

    /**
     * @var Installer
     */
    private $installer;
    private $postInstallOutput = [''];
    private $operations = [];
    private $lock;
    private $displayThanksReminder = 0;
    private $dryRun = false;
    private static $activated = true;
    private static $aliasResolveCommands = [
        'require' => true,
        'update' => false,
        'remove' => false,
        'unpack' => true,
    ];
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

        $symfonyRequire = preg_replace('/\.x$/', '.x-dev', getenv('SYMFONY_REQUIRE') ?: ($composer->getPackage()->getExtra()['symfony']['require'] ?? ''));

        $rfs = Factory::createHttpDownloader($this->io, $this->config);

        $this->downloader = $downloader = new Downloader($composer, $io, $rfs);

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
                if ($input->hasOption('remove-vcs')) {
                    $input->setOption('remove-vcs', true);
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
            }

            if ($input->hasParameterOption('--prefer-lowest', true)) {
                // When prefer-lowest is set and no stable version has been released,
                // we consider "dev" more stable than "alpha", "beta" or "RC". This
                // allows testing lowest versions with potential fixes applied.
                BasePackage::$stabilities['dev'] = 1 + BasePackage::STABILITY_STABLE;
            }

            $app->add(new Command\RequireCommand($resolver, \Closure::fromCallable([$this, 'updateComposerLock'])));
            $app->add(new Command\UpdateCommand($resolver));
            $app->add(new Command\RemoveCommand($resolver));
            $app->add(new Command\RecipesCommand($this, $this->lock, $rfs));
            $app->add(new Command\InstallRecipesCommand($this, $this->options->get('root-dir')));
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
                $this->installer = $trace['object']->setSuggestedPackagesReporter(new SuggestedPackagesReporter(new NullIO()));
            }

            if (isset($trace['object']) && $trace['object'] instanceof GlobalCommand) {
                $this->downloader->disable();
            }
        }

        return $backtrace;
    }

    public function configureProject(Event $event)
    {
        if (!$this->downloader->isEnabled()) {
            $this->io->writeError('<warning>Project configuration is disabled: "symfony/flex" not found in the root composer.json</>');

            return;
        }

        // Remove LICENSE (which do not apply to the user project)
        @unlink('LICENSE');

        // Update composer.json (project is proprietary by default)
        $file = Factory::getComposerFile();
        $contents = file_get_contents($file);
        $manipulator = new JsonManipulator($contents);
        $json = JsonFile::parseJson($contents);

        // new projects are most of the time proprietary
        $manipulator->addMainKey('license', 'proprietary');

        // extra.branch-alias doesn't apply to the project
        $manipulator->removeSubNode('extra', 'branch-alias');

        // 'name' and 'description' are only required for public packages
        // don't use $manipulator->removeProperty() for BC with Composer 1.0
        $contents = preg_replace(['{^\s*+"name":.*,$\n}m', '{^\s*+"description":.*,$\n}m'], '', $manipulator->getContents(), 1);
        file_put_contents($file, $contents);

        $this->updateComposerLock();
    }

    public function record(PackageEvent $event)
    {
        if ($this->shouldRecordOperation($event)) {
            $this->operations[] = $event->getOperation();
        }
    }

    public function update(Event $event, $operations = [])
    {
        if ($operations) {
            $this->operations = $operations;
        }

        $this->install($event);

        $file = Factory::getComposerFile();
        $contents = file_get_contents($file);
        $json = JsonFile::parseJson($contents);

        if (!isset($json['flex-require']) && !isset($json['flex-require-dev'])) {
            $this->unpack($event);

            return;
        }

        // merge "flex-require" with "require"
        $manipulator = new JsonManipulator($contents);
        $sortPackages = $this->composer->getConfig()->get('sort-packages');
        $symfonyVersion = $json['extra']['symfony']['require'] ?? null;
        $versions = $symfonyVersion ? $this->downloader->getVersions() : null;
        foreach (['require', 'require-dev'] as $type) {
            if (isset($json['flex-'.$type])) {
                foreach ($json['flex-'.$type] as $package => $constraint) {
                    if ($symfonyVersion && '*' === $constraint && isset($versions['splits'][$package])) {
                        // replace unbounded constraints for symfony/* packages by extra.symfony.require
                        $constraint = $symfonyVersion;
                    }
                    $manipulator->addLink($type, $package, $constraint, $sortPackages);
                }

                $manipulator->removeMainKey('flex-'.$type);
            }
        }

        file_put_contents($file, $manipulator->getContents());

        $this->reinstall($event, true);
    }

    public function install(Event $event)
    {
        $rootDir = $this->options->get('root-dir');

        if (!file_exists("$rootDir/.env") && !file_exists("$rootDir/.env.local") && file_exists("$rootDir/.env.dist") && false === strpos(file_get_contents("$rootDir/.env.dist"), '.env.local')) {
            copy($rootDir.'/.env.dist', $rootDir.'/.env');
        }

        // Execute missing recipes
        $recipes = ScriptEvents::POST_UPDATE_CMD === $event->getName() ? $this->fetchRecipes($this->operations, $event instanceof UpdateEvent && $event->reset()) : [];
        $this->operations = [];     // Reset the operation after getting recipes

        if (2 === $this->displayThanksReminder) {
            $love = '\\' === \DIRECTORY_SEPARATOR ? 'love' : '💖 ';
            $star = '\\' === \DIRECTORY_SEPARATOR ? 'star' : '★ ';

            $this->io->writeError('');
            $this->io->writeError('What about running <comment>composer global require symfony/thanks && composer thanks</> now?');
            $this->io->writeError(sprintf('This will spread some %s by sending a %s to the GitHub repositories of your fellow package maintainers.', $love, $star));
        }

        $this->io->writeError('');

        if (!$recipes) {
            if (ScriptEvents::POST_UPDATE_CMD === $event->getName()) {
                $this->synchronizePackageJson($rootDir);
                $this->lock->write();
            }

            if ($this->downloader->isEnabled()) {
                $this->io->writeError('Run <comment>composer recipes</> at any time to see the status of your Symfony recipes.');
                $this->io->writeError('');
            }

            return;
        }

        $this->io->writeError(sprintf('<info>Symfony operations: %d recipe%s (%s)</>', \count($recipes), \count($recipes) > 1 ? 's' : '', $this->downloader->getSessionId()));
        $installContribs = $this->composer->getPackage()->getExtra()['symfony']['allow-contrib'] ?? false;
        $manifest = null;
        $originalComposerJsonHash = $this->getComposerJsonHash();
        foreach ($recipes as $recipe) {
            if ('install' === $recipe->getJob() && !$installContribs && $recipe->isContrib()) {
                $warning = $this->io->isInteractive() ? 'WARNING' : 'IGNORING';
                $this->io->writeError(sprintf('  - <warning> %s </> %s', $warning, $recipe->getFormattedOrigin()));
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
                }
            }

            switch ($recipe->getJob()) {
                case 'install':
                    $this->io->writeError(sprintf('  - Configuring %s', $recipe->getFormattedOrigin()));
                    $this->configurator->install($recipe, $this->lock, [
                        'force' => $event instanceof UpdateEvent && $event->force(),
                    ]);
                    $manifest = $recipe->getManifest();
                    if (isset($manifest['post-install-output'])) {
                        $this->postInstallOutput[] = sprintf('<bg=yellow;fg=white> %s </> instructions:', $recipe->getName());
                        $this->postInstallOutput[] = '';
                        foreach ($manifest['post-install-output'] as $line) {
                            $this->postInstallOutput[] = $this->options->expandTargetDir($line);
                        }
                        $this->postInstallOutput[] = '';
                    }
                    break;
                case 'update':
                    break;
                case 'uninstall':
                    $this->io->writeError(sprintf('  - Unconfiguring %s', $recipe->getFormattedOrigin()));
                    $this->configurator->unconfigure($recipe, $this->lock);
                    break;
            }
        }

        if (null !== $manifest) {
            array_unshift(
                $this->postInstallOutput,
                '<bg=blue;fg=white>              </>',
                '<bg=blue;fg=white> What\'s next? </>',
                '<bg=blue;fg=white>              </>',
                '',
                '<info>Some files have been created and/or updated to configure your new packages.</>',
                'Please <comment>review</>, <comment>edit</> and <comment>commit</> them: these files are <comment>yours</>.'
            );
        }

        $this->synchronizePackageJson($rootDir);
        $this->lock->write();

        if ($this->getComposerJsonHash() !== $originalComposerJsonHash) {
            $this->updateComposerLock();
        }
    }

    private function synchronizePackageJson(string $rootDir)
    {
        $rootDir = realpath($rootDir);
        $vendorDir = trim((new Filesystem())->makePathRelative($this->config->get('vendor-dir'), $rootDir), '/');

        $synchronizer = new PackageJsonSynchronizer($rootDir, $vendorDir);

        if ($synchronizer->shouldSynchronize()) {
            $lockData = $this->composer->getLocker()->getLockData();

            $r = new \ReflectionParameter([$synchronizer, 'addPackageJsonLink'], 'phpPackage');
            if ('string' === $r->getType()->getName()) {
                // support for smooth upgrades from older flex versions
                $lockData['packages'] = array_column($lockData['packages'] ?? [], 'name');
                $lockData['packages-dev'] = array_column($lockData['packages-dev'] ?? [], 'name');
            }

            if ($synchronizer->synchronize(array_merge($lockData['packages'] ?? [], $lockData['packages-dev'] ?? []))) {
                $this->io->writeError('<info>Synchronizing package.json with PHP packages</>');
                $this->io->writeError('<warning>Don\'t forget to run npm install --force or yarn install --force to refresh your JavaScript dependencies!</>');
                $this->io->writeError('');
            }
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
        $this->postInstallOutput = [];
    }

    /**
     * @return Recipe[]
     */
    public function fetchRecipes(array $operations, bool $reset): array
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
        $metaRecipes = [];

        foreach ($operations as $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            // FIXME: Multi name with getNames()
            $name = $package->getName();
            $job = method_exists($operation, 'getOperationType') ? $operation->getOperationType() : $operation->getJobType();

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
                if (!$reset && $ref && ($locks[$name]['recipe']['ref'] ?? null) === $ref) {
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
                if ('metapackage' === $package->getType()) {
                    $metaRecipes[$name] = new Recipe($package, $name, $job, $manifests[$name], $locks[$name] ?? []);
                } else {
                    $recipes[$name] = new Recipe($package, $name, $job, $manifests[$name], $locks[$name] ?? []);
                }
            }

            if (!isset($manifests[$name])) {
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

        return array_merge($metaRecipes, array_filter($recipes));
    }

    public function truncatePackages(PrePoolCreateEvent $event)
    {
        if (!$this->filter) {
            return;
        }

        $rootPackage = $this->composer->getPackage();
        $lockedPackages = $event->getRequest()->getFixedOrLockedPackages();

        $event->setPackages($this->filter->removeLegacyPackages($event->getPackages(), $rootPackage, $lockedPackages));
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

    private function unpack(Event $event)
    {
        $jsonPath = Factory::getComposerFile();
        $json = JsonFile::parseJson(file_get_contents($jsonPath));
        $sortPackages = $this->composer->getConfig()->get('sort-packages');
        $unpackOp = new Operation(true, $sortPackages);

        foreach (['require', 'require-dev'] as $type) {
            foreach ($json[$type] ?? [] as $package => $constraint) {
                $unpackOp->addPackage($package, $constraint, 'require-dev' === $type);
            }
        }

        $unpacker = new Unpacker($this->composer, new PackageResolver($this->downloader), $this->dryRun);
        $result = $unpacker->unpack($unpackOp);

        if (!$result->getUnpacked()) {
            return;
        }

        $this->io->writeError('<info>Unpacking Symfony packs</>');
        foreach ($result->getUnpacked() as $pkg) {
            $this->io->writeError(sprintf('  - Unpacked <info>%s</>', $pkg->getName()));
        }

        $unpacker->updateLock($result, $this->io);

        $this->reinstall($event, false);
    }

    private function reinstall(Event $event, bool $update)
    {
        $event->stopPropagation();
        $composer = Factory::create($this->io);

        $installer = clone $this->installer;
        $installer->__construct(
            $this->io,
            $composer->getConfig(),
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $composer->getEventDispatcher(),
            $composer->getAutoloadGenerator()
        );

        if (!$update) {
            $installer->setUpdateAllowList(['php']);
        }

        $installer->run();

        $this->io->write($this->postInstallOutput);
        $this->postInstallOutput = [];
    }

    public static function getSubscribedEvents(): array
    {
        if (!self::$activated) {
            return [];
        }

        $events = [
            PackageEvents::POST_PACKAGE_INSTALL => 'record',
            PackageEvents::POST_PACKAGE_UPDATE => [['record'], ['enableThanksReminder']],
            PackageEvents::POST_PACKAGE_UNINSTALL => 'record',
            PluginEvents::PRE_POOL_CREATE => 'truncatePackages',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'configureProject',
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::PRE_UPDATE_CMD => 'configureInstaller',
            ScriptEvents::POST_UPDATE_CMD => 'update',
            'auto-scripts' => 'executeAutoScripts',
        ];

        return $events;
    }

    private function getComposerJsonHash(): string
    {
        return md5_file(Factory::getComposerFile());
    }
}
