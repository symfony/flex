<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Harmony\Flex;

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\FileDownloader;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer as ComposerInstaller;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
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
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository as BaseComposerRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\RemoteFilesystem;
use Harmony\Flex\Event\UpdateEvent;
use Harmony\Flex\IO\ConsoleIO;
use Harmony\Flex\Platform\Handler;
use Harmony\Flex\Repository\HarmonyRepository;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Thanks\Thanks;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 * @author David Sanchez <david38sanchez@gmail.com>
 */
class Flex implements PluginInterface, EventSubscriberInterface
{

    /** @var Composer $composer */
    private $composer;

    /** @var ConsoleIO $io */
    private $io;

    /** @var Config $config */
    private $config;

    /** @var Options $options */
    private $options;

    /** @var Configurator $configurator */
    private $configurator;

    /** @var Downloader $downloader */
    private $downloader;

    /** @var array $postInstallOutput */
    private $postInstallOutput = [''];

    /** @var array $operations */
    private $operations = [];

    /** @var Lock $lock */
    private $lock;

    /** @var Lock $harmonyLock */
    private $harmonyLock;

    /** @var bool $cacheDirPopulated */
    private $cacheDirPopulated = false;

    /** @var int $displayThanksReminder */
    private $displayThanksReminder = 0;

    /** @var RemoteFilesystem $rfs */
    private $rfs;

    /** @var bool $progress */
    private $progress = true;

    /** @var bool $dryRun */
    private $dryRun = false;

    /** @var bool $activated */
    private static $activated = true;

    /** @var array $repoReadingCommands */
    private static $repoReadingCommands
        = [
            'create-project' => true,
            'outdated'       => true,
            'require'        => true,
            'update'         => true,
            'install'        => true,
        ];

    /** @var array $aliasResolveCommands */
    private static $aliasResolveCommands
        = [
            'require' => true,
            'update'  => false,
            'remove'  => false,
            'unpack'  => true,
        ];

    /** @var bool $shouldUpdateComposerLock */
    private $shouldUpdateComposerLock = false;

    /** @var Platform $platform */
    private $platform;

    /** @var string|null $command */
    private $command;

    /** @var Handler\Project $project */
    private $project;

    /** @var ScriptExecutor $executor */
    private $executor;

    /**
     * @param Composer    $composer
     * @param IOInterface $io
     *
     * @throws \Exception
     * @throws \Http\Client\Exception
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        if (!\extension_loaded('openssl')) {
            self::$activated = false;
            $io->writeError('<warning>HarmonyFlex has been disabled. You must enable the openssl extension in your "php.ini" file.</warning>');

            return;
        }

        // to avoid issues when Flex is upgraded, we load all PHP classes now
        // that way, we are sure to use all files from the same version
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__,
            \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('.php' === substr($file, - 4)) {
                /** @noinspection PhpIncludeInspection */
                require_once $file;
            }
        }

        $this->composer = $composer;
        $this->io       = new ConsoleIO($io);
        $this->config   = $composer->getConfig();
        $this->options  = $this->initOptions();
        $this->executor = new ScriptExecutor($this->composer, $this->io, $this->options);

        // Plugin initialized successfully
        if ($this->io->isDebug()) {
            $this->io->success('HarmonyFlex plugin initialized successfully');
        }

        $rfs       = Factory::createRemoteFilesystem($this->io, $this->config);
        $this->rfs = new ParallelDownloader($this->io, $this->config, $rfs->getOptions(), $rfs->isTlsDisabled());

        $symfonyRequire = getenv('SYMFONY_REQUIRE') ?:
            ($composer->getPackage()->getExtra()['symfony']['require'] ?? null);

        $manager         = RepositoryFactory::manager($this->io, $this->config, $composer->getEventDispatcher(),
            $this->rfs);
        $setRepositories = \Closure::bind(function (RepositoryManager $manager) use (&$symfonyRequire) {
            $manager->repositoryClasses = $this->repositoryClasses;
            $manager->setRepositoryClass('composer', HarmonyRepository::class);
            $manager->repositories = $this->repositories;
            $i                     = 0;
            foreach (RepositoryFactory::defaultRepos(null, $this->config, $manager) as $repo) {
                $manager->repositories[$i ++] = $repo;
                if ($repo instanceof HarmonyRepository && $symfonyRequire) {
                    $repo->setSymfonyRequire($symfonyRequire, $this->io);
                }
            }
            $manager->setLocalRepository($this->getLocalRepository());
        }, $composer->getRepositoryManager(), RepositoryManager::class);

        $setRepositories($manager);
        $composer->setRepositoryManager($manager);

        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->downloader   = new Downloader($composer, $io, $this->rfs);
        $this->downloader->setFlexId($this->getFlexId());
        $this->lock        = new Lock(getenv('SYMFONY_LOCKFILE') ?:
            str_replace('composer.json', 'symfony.lock', Factory::getComposerFile()));
        $this->harmonyLock = new Lock(str_replace('composer.json', 'harmony.lock', Factory::getComposerFile()));

        $populateRepoCacheDir = __CLASS__ === self::class;
        if ($composer->getPluginManager()) {
            foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
                if (0 === strpos(\get_class($plugin), 'Hirak\Prestissimo\Plugin')) {
                    if (method_exists($rfs, 'getRemoteContents')) {
                        $plugin->disable();
                    } else {
                        $this->cacheDirPopulated = true;
                    }
                    $populateRepoCacheDir = false;
                    break;
                }
            }
        }

        // Add installer for custom types
        $composer->getInstallationManager()->addInstaller(new Installer($this->io, $composer));

        // Platform
        $this->platform = new Platform($this->composer, $this->io, $this->configurator, $this->executor);
        $this->platform->checkConnectivity();

        $backtrace = debug_backtrace();
        foreach ($backtrace as $trace) {
            if (isset($trace['object']) && $trace['object'] instanceof ComposerInstaller) {
                $trace['object']->setSuggestedPackagesReporter(new SuggestedPackagesReporter(new NullIO()));
                break;
            }
        }

        foreach ($backtrace as $trace) {
            if (!isset($trace['object']) || !isset($trace['args'][0])) {
                continue;
            }

            if (!$trace['object'] instanceof Application || !$trace['args'][0] instanceof ArgvInput) {
                continue;
            }

            // In Composer 1.0.*, $input knows about option and argument definitions
            // Since Composer >=1.1, $input contains only raw values
            /** @var InputInterface $input */
            $input = $trace['args'][0];
            $app   = $trace['object'];

            $resolver = new PackageResolver($this->downloader);

            if (version_compare('1.1.0', PluginInterface::PLUGIN_API_VERSION, '>')) {
                $note = $app->has('self-update') ? sprintf('`php %s self-update`', $_SERVER['argv'][0]) :
                    'https://getcomposer.org/';
                $io->writeError('<warning>Some Symfony Flex features may not work as expected: your version of Composer is too old</warning>');
                $io->writeError(sprintf('<warning>Please upgrade using %s</warning>', $note));
            }

            try {
                $command       = $input->getFirstArgument();
                $this->command = $command ? $app->find($command)->getName() : null;
            }
            catch (\InvalidArgumentException $e) {
            }

            if ('create-project' === $this->command) {
                // detect Composer >=1.7 (using the Composer::VERSION constant doesn't work with snapshot builds)
                if (class_exists(Comparer::class)) {
                    $input->setOption('remove-vcs', true);
                } else {
                    $input->setInteractive(false);
                }
            } elseif ('update' === $this->command) {
                $this->displayThanksReminder = 1;
            } elseif ('outdated' === $this->command) {
                $symfonyRequire = null;
                $setRepositories($manager);
            }

            if (isset(self::$aliasResolveCommands[$this->command])) {
                // early resolve for BC with Composer 1.0
                if ($input->hasArgument('packages')) {
                    $input->setArgument('packages', $resolver->resolve($input->getArgument('packages'),
                        self::$aliasResolveCommands[$this->command]));
                }

                if ($input->hasOption('no-suggest')) {
                    $input->setOption('no-suggest', true);
                }
            }

            if ($input->hasParameterOption('--no-progress', true)) {
                $this->progress = false;
            }

            if ($input->hasParameterOption('--dry-run', true)) {
                $this->dryRun = true;
            }

            if ($input->hasParameterOption('--prefer-lowest', true)) {
                // When prefer-lowest is set and no stable version has been released,
                // we consider "dev" more stable than "alpha", "beta" or "RC". This
                // allows testing lowest versions with potential fixes applied.
                BasePackage::$stabilities['dev'] = 1 + BasePackage::STABILITY_STABLE;
            }

            $composerFile = Factory::getComposerFile();
            if ($populateRepoCacheDir && isset(self::$repoReadingCommands[$this->command]) &&
                ('install' !== $this->command ||
                    (file_exists($composerFile) && !file_exists(substr($composerFile, 0, - 4) . 'lock')))) {
                $this->populateRepoCacheDir();
            }

            $app->add(new Command\RequireCommand($resolver));
            $app->add(new Command\UpdateCommand($resolver));
            $app->add(new Command\RemoveCommand($resolver));
            $app->add(new Command\UnpackCommand($resolver));
            $app->add(new Command\SyncRecipesCommand($this, $this->options->get('root-dir')));
            $app->add(new Command\GenerateIdCommand($this));

            break;
        }
    }

    /**
     * @param Event $event
     *
     * @throws \Http\Client\Exception
     * @throws \Throwable
     */
    public function createProject(Event $event)
    {
        if (null === $this->downloader->getEndpoint()) {
            $this->io->writeError('<warning>Project configuration is disabled: "harmony/flex" not found in the root composer.json</warning>');

            return;
        }

        $json        = new JsonFile(Factory::getComposerFile());
        $contents    = file_get_contents($json->getPath());
        $manipulator = new JsonManipulator($contents);

        // new projects are most of the time proprietary
        $manipulator->addMainKey('license', 'proprietary');

        // replace unbounded constraints for symfony/* packages by extra.symfony.require
        $config = json_decode($contents, true);
        if ($symfonyVersion = $config['extra']['symfony']['require'] ?? null) {
            $response = $this->downloader->get('/versions.json');
            $versions = $response->getBody();
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
        $contents = preg_replace(['{^\s*+"name":.*,$\n}m', '{^\s*+"description":.*,$\n}m'], '',
            $manipulator->getContents(), 1);
        file_put_contents($json->getPath(), $contents);

        $this->updateComposerLock();
    }

    /**
     * @param PackageEvent $event
     */
    public function record(PackageEvent $event)
    {
        if ($this->shouldRecordOperation($event)) {
            $this->operations[] = $event->getOperation();
        }
    }

    /**
     * Install packages with custom types starting by `harmony-`
     *
     * @param PackageEvent $event
     */
    public function installCustomTypes(PackageEvent $event): void
    {
        /** @var OperationInterface|InstallOperation|UpdateOperation $operation */
        $operation = $event->getOperation();
        $package   = $operation->getPackage();
        $type      = $package->getType();

        if (0 === strpos($type, Installer::PREFIX)) {
            try {
                /** @var Installer $installer */
                $installer = $this->composer->getInstallationManager()->getInstaller($type);
                $installer->postInstall($package);
            }
            catch (\InvalidArgumentException $e) {
            }
        }
    }

    /**
     * @param Event $event
     *
     * @throws \Exception
     */
    public function install(Event $event)
    {
        $this->update($event);
    }

    /**
     * @param Event $event
     * @param array $operations
     *
     * @throws \Exception
     */
    public function update(Event $event, $operations = [])
    {
        if ($operations) {
            $this->operations = $operations;
        }
        $rootDir = $this->options->get('root-dir');

        if (!file_exists("$rootDir/.env") && !file_exists("$rootDir/.env.local") && file_exists("$rootDir/.env.dist") &&
            false === strpos(file_get_contents("$rootDir/.env.dist"), '.env.local')) {
            copy($rootDir . '/.env.dist', $rootDir . '/.env');
        }

        $recipes = $this->fetchRecipes();

        if (2 === $this->displayThanksReminder) {
            $love = '\\' === \DIRECTORY_SEPARATOR ? 'love' : '💖 ';
            $star = '\\' === \DIRECTORY_SEPARATOR ? 'star' : '★ ';

            $this->io->writeError('');
            $this->io->writeError('What about running <comment>composer global require symfony/thanks && composer thanks</comment> now?');
            $this->io->writeError(sprintf('This will spread some %s by sending a %s to the GitHub repositories of your fellow package maintainers.',
                $love, $star));
            $this->io->writeError('');
        }

        if (!$recipes) {
            $this->lock->write();

            return;
        }

        $this->io->writeError(sprintf('<info>Symfony operations: %d recipe%s (%s)</info>', \count($recipes),
            \count($recipes) > 1 ? 's' : '', $this->downloader->getSessionId()));
        $installContribs = $this->composer->getPackage()->getExtra()['symfony']['allow-contrib'] ?? false;
        $manifest        = null;
        foreach ($recipes as $recipe) {
            if ('install' === $recipe->getJob() && !$installContribs && $recipe->isContrib()) {
                $warning = $this->io->isInteractive() ? 'WARNING' : 'IGNORING';
                $this->io->writeError(sprintf('  - <warning> %s </warning> %s', $warning,
                    $this->formatOrigin($recipe->getOrigin())));
                $question = sprintf('    The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Review the recipe at %s

    Do you want to execute this recipe?
    [<comment>y</comment>] Yes
    [<comment>n</comment>] No
    [<comment>a</comment>] Yes for all packages, only for the current installation session
    [<comment>p</comment>] Yes permanently, never ask again for this project
    (defaults to <comment>n</comment>): ', $recipe->getURL());
                $answer   = $this->io->askAndValidate($question, function ($value) {
                    if (null === $value) {
                        return 'n';
                    }
                    $value = strtolower($value[0]);
                    if (!\in_array($value, ['y', 'n', 'a', 'p'])) {
                        throw new \InvalidArgumentException('Invalid choice');
                    }

                    return $value;
                }, null, 'n');
                if ('n' === $answer) {
                    continue;
                }
                if ('a' === $answer) {
                    $installContribs = true;
                }
                if ('p' === $answer) {
                    $installContribs = true;
                    $json            = new JsonFile(Factory::getComposerFile());
                    $manipulator     = new JsonManipulator(file_get_contents($json->getPath()));
                    $manipulator->addSubNode('extra', 'symfony.allow-contrib', true);
                    file_put_contents($json->getPath(), $manipulator->getContents());
                    $this->shouldUpdateComposerLock = true;
                }
            }

            switch ($recipe->getJob()) {
                case 'install':
                    $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe->getOrigin())));
                    $this->configurator->install($recipe, [
                        'force' => $event instanceof UpdateEvent && $event->force(),
                    ]);
                    $manifest = $recipe->getManifest();
                    if (isset($manifest['post-install-output']) && is_array($manifest['post-install-output'])) {
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

        if (null !== $manifest) {
            array_unshift($this->postInstallOutput, '',
                '<info>Some files may have been created or updated to configure your new packages.</info>',
                'Please <comment>review</comment>, <comment>edit</comment> and <comment>commit</comment> them: these files are <comment>yours</comment>.');
        }

        $this->lock->write();

        if ($this->shouldUpdateComposerLock) {
            $this->updateComposerLock();
        }
    }

    /**
     * @param Event $event
     *
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function harmonyProjectInitialize(Event $event)
    {
        if ('create-project' === $this->command) {
            $this->platform->authenticate();
            $this->project = $this->platform->getProject();
            if (true === $this->project->isActivated()) {
                // Install stacks.
                $this->project->installStacks();

                // Install themes
                $this->project->installThemes();
            }
        }
    }

    /**
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function postHarmonyProjectInstall()
    {
        $this->platform->authenticate();

        // Configure `DATABASE_URL` env variable from configured project information.
        $this->project->configDatabases();

        // Ask user to initialize database.
        $this->project->initDatabase();

        // Ask create new admin user
        $this->project->createUser();

        // Clear useless/unused files/folders
        $this->project->clear();

        // Create lock file
        $this->lock->write();

        // Process successfully completed
        $this->io->success('HarmonyCMS installation successful');
    }

    public function enableThanksReminder()
    {
        if (1 === $this->displayThanksReminder) {
            $this->displayThanksReminder = !class_exists(Thanks::class, false) &&
            version_compare('1.1.0', PluginInterface::PLUGIN_API_VERSION, '<=') ? 2 : 0;
        }
    }

    /**
     * @param Event $event
     */
    public function executeAutoScripts(Event $event)
    {
        $event->stopPropagation();

        // force reloading scripts as we might have added and removed during this run
        $json         = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
            $this->executor->execute($type, $cmd);
        }

        $this->io->write($this->postInstallOutput);
    }

    /**
     * @param InstallerEvent $event
     */
    public function populateProvidersCacheDir(InstallerEvent $event)
    {
        $listed   = [];
        $packages = [];
        $pool     = $event->getPool();
        $pool     = \Closure::bind(function () {
            foreach ($this->providerRepos as $k => $repo) {
                $this->providerRepos[$k] = new class($repo) extends BaseComposerRepository
                {

                    private $repo;

                    public function __construct($repo)
                    {
                        $this->repo = $repo;
                    }

                    public function whatProvides(Pool $pool, $name, $bypassFilters = false)
                    {
                        $packages = [];
                        foreach ($this->repo->whatProvides($pool, $name, $bypassFilters) as $k => $p) {
                            $packages[$k] = clone $p;
                        }

                        return $packages;
                    }
                };
            }

            return $this;
        }, clone $pool, $pool)();

        foreach ($event->getRequest()->getJobs() as $job) {
            if ('install' !== $job['cmd'] || false === strpos($job['packageName'], '/')) {
                continue;
            }

            $listed[$job['packageName']] = true;
            $packages[]                  = [$job['packageName'], $job['constraint']];
        }

        $this->rfs->download($packages, function ($packageName, $constraint) use (&$listed, &$packages, $pool) {
            foreach ($pool->whatProvides($packageName, $constraint, true) as $package) {
                foreach (array_merge($package->getRequires(), $package->getConflicts(),
                    $package->getReplaces()) as $link) {
                    if (isset($listed[$link->getTarget()]) || false === strpos($link->getTarget(), '/')) {
                        continue;
                    }
                    $listed[$link->getTarget()] = true;
                    $packages[]                 = [$link->getTarget(), $link->getConstraint()];
                }
            }
        });
    }

    /**
     * @param InstallerEvent $event
     */
    public function populateFilesCacheDir(InstallerEvent $event)
    {
        if ($this->cacheDirPopulated || $this->dryRun) {
            return;
        }
        $this->cacheDirPopulated = true;

        $downloads   = [];
        $cacheDir    = rtrim($this->config->get('cache-files-dir'), '\/') . \DIRECTORY_SEPARATOR;
        $getCacheKey = function (PackageInterface $package, $processedUrl) {
            return $this->getCacheKey($package, $processedUrl);
        };
        $getCacheKey = \Closure::bind($getCacheKey, new FileDownloader($this->io, $this->config),
            FileDownloader::class);

        foreach ($event->getOperations() as $op) {
            if ('install' === $op->getJobType()) {
                $package = $op->getPackage();
            } elseif ('update' === $op->getJobType()) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            if (!$fileUrl = $package->getDistUrl()) {
                continue;
            }

            if ($package->getDistMirrors()) {
                $fileUrl = current($package->getDistUrls());
            }

            if (!preg_match('/^https?:/', $fileUrl) || !$originUrl = parse_url($fileUrl, PHP_URL_HOST)) {
                continue;
            }

            if (file_exists($file = $cacheDir . $getCacheKey($package, $fileUrl))) {
                continue;
            }

            @mkdir(\dirname($file), 0775, true);

            if (!is_dir(\dirname($file))) {
                continue;
            }

            if (preg_match('#^https://github\.com/#', $package->getSourceUrl()) &&
                preg_match('#^https://api\.github\.com/repos(/[^/]++/[^/]++/)zipball(.++)$#', $fileUrl, $m)) {
                $fileUrl = sprintf('https://codeload.github.com%slegacy.zip%s', $m[1], $m[2]);
            }

            $downloads[] = [$originUrl, $fileUrl, [], $file, false];
        }

        if (1 < \count($downloads)) {
            $this->rfs->download($downloads, [$this->rfs, 'get'], false, $this->progress);
        }
    }

    /**
     * @param PreFileDownloadEvent $event
     */
    public function onFileDownload(PreFileDownloadEvent $event)
    {
        if ($event->getRemoteFilesystem() !== $this->rfs) {
            $event->setRemoteFilesystem($this->rfs->setNextOptions($event->getRemoteFilesystem()->getOptions()));
        }
    }

    /**
     * @throws \Http\Client\Exception
     * @throws \Throwable
     */
    public function generateFlexId()
    {
        if ($this->getFlexId()) {
            return;
        }

        if (null === $this->downloader->getEndpoint()) {
            throw new \LogicException('Cannot generate project id when "harmony/flex" is not found in the root composer.json.');
        }

        $json        = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('extra', 'symfony.id', $this->downloader->get('/ulid')->getBody()['ulid']);
        file_put_contents($json->getPath(), $manipulator->getContents());

        $this->updateComposerLock();
    }

    /**
     * @return array
     */
    private function fetchRecipes(): array
    {
        if (null === $this->downloader->getEndpoint()) {
            $this->io->writeError('<warning>Harmony recipes are disabled: "harmony/flex" not found in the root composer.json</warning>');

            return [];
        }
        $devPackages = null;
        $data        = $this->downloader->getRecipes($this->operations);
        $manifests   = $data['manifests'] ?? [];
        $locks       = $data['locks'] ?? [];
        // harmony/flex and symfony/framework-bundle recipes should always be applied first
        $recipes = [
            'harmony/flex'             => null,
            'symfony/framework-bundle' => null,
        ];
        foreach ($this->operations as $i => $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            // FIXME: getNames() can return n names
            $name = $package->getNames()[0];
            $job  = $operation->getJobType();

            if ($operation instanceof InstallOperation && isset($locks[$name])) {
                $ref = $this->lock->get($name)['recipe']['ref'] ?? null;
                if ($ref && ($locks[$name]['recipe']['ref'] ?? null) === $ref) {
                    continue;
                }
                $this->lock->add($name, $locks[$name]);
            } elseif ($operation instanceof UninstallOperation) {
                if (!$this->lock->has($name)) {
                    continue;
                }
                $this->lock->remove($name);
            }

            if (isset($manifests[$name])) {
                $recipes[$name] = new Recipe($package, $name, $job, $manifests[$name]);
            }

            $noRecipe = !isset($manifests[$name]) ||
                (isset($manifests[$name]['not_installable']) && $manifests[$name]['not_installable']);
            if ($noRecipe && 'symfony-bundle' === $package->getType()) {
                $manifest = [];
                $bundle   = new SymfonyBundle($this->composer, $package, $job);
                if (null === $devPackages) {
                    $devPackages = array_column($this->composer->getLocker()->getLockData()['packages-dev'], 'name');
                }
                $envs = \in_array($name, $devPackages) ? ['dev', 'test'] : ['all'];
                foreach ($bundle->getClassNames() as $class) {
                    $manifest['manifest']['bundles'][$class] = $envs;
                }
                if ($manifest) {
                    $manifest['origin'] = sprintf('%s:%s@auto-generated recipe', $name, $package->getPrettyVersion());
                    $recipes[$name]     = new Recipe($package, $name, $job, $manifest);
                }
            }
        }
        $this->operations = [];

        return array_filter($recipes);
    }

    /**
     * @return Options
     */
    private function initOptions(): Options
    {
        $extra   = $this->composer->getPackage()->getExtra();
        $options = array_merge([
            'bin-dir'    => 'bin',
            'conf-dir'   => 'conf',
            'config-dir' => 'config',
            'src-dir'    => 'src',
            'var-dir'    => 'var',
            'public-dir' => 'public',
            'root-dir'   => $extra['symfony']['root-dir'] ?? '.',
        ], $extra);

        return new Options($options, $this->io);
    }

    /**
     * @return string|null
     */
    private function getFlexId()
    {
        $extra = $this->composer->getPackage()->getExtra();

        return $extra['symfony']['id'] ?? null;
    }

    /**
     * @param string $origin
     *
     * @return string
     */
    private function formatOrigin(string $origin): string
    {
        // symfony/translation:3.3@github.com/symfony/recipes:master
        if (!preg_match('/^([^\:]+?)\:([^\@]+)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</info> (<comment>>=%s</comment>): From %s', $matches[1], $matches[2],
            'auto-generated recipe' === $matches[3] ? '<comment>' . $matches[3] . '</>' : $matches[3]);
    }

    /**
     * @param PackageEvent $event
     *
     * @return bool
     */
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

        // FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        if ($operation instanceof InstallOperation) {
            if (!$this->lock->has($name)) {
                return true;
            }
        } elseif ($operation instanceof UninstallOperation) {
            return true;
        }

        return false;
    }

    private function populateRepoCacheDir()
    {
        $repos = [];

        foreach ($this->composer->getPackage()->getRepositories() as $name => $repo) {
            if (!isset($repo['type']) || 'composer' !== $repo['type'] || !empty($repo['force-lazy-providers'])) {
                continue;
            }

            if (!preg_match('#^http(s\??)?://#', $repo['url'])) {
                continue;
            }

            $repo = new HarmonyRepository($repo, $this->io, $this->config, null, $this->rfs);

            $repos[] = [$repo];
        }

        $this->rfs->download($repos, function ($repo) {
            ParallelDownloader::$cacheNext = true;
            $repo->getProviderNames();
        });
    }

    /**
     * @throws \Exception
     */
    private function updateComposerLock()
    {
        $lock                     = substr(Factory::getComposerFile(), 0, - 4) . 'lock';
        $composerJson             = file_get_contents(Factory::getComposerFile());
        $lockFile                 = new JsonFile($lock, null, $this->io);
        $locker                   = new Locker($this->io, $lockFile, $this->composer->getRepositoryManager(),
            $this->composer->getInstallationManager(), $composerJson);
        $lockData                 = $locker->getLockData();
        $lockData['content-hash'] = Locker::getContentHash($composerJson);
        $lockFile->write($lockData);
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     * The array keys are event names and the value can be:
     * * The method name to call (priority defaults to 0)
     * * An array composed of the method name to call and the priority
     * * An array of arrays composed of the method names to call and respective
     *   priorities, or 0 if unset
     * For instance:
     * * array('eventName' => 'methodName')
     * * array('eventName' => array('methodName', $priority))
     * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents(): array
    {
        if (!self::$activated) {
            return [];
        }

        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING  => [['populateProvidersCacheDir', PHP_INT_MAX]],
            InstallerEvents::POST_DEPENDENCIES_SOLVING => [['populateFilesCacheDir', PHP_INT_MAX]],
            PackageEvents::PRE_PACKAGE_INSTALL         => [['populateFilesCacheDir', ~PHP_INT_MAX]],
            PackageEvents::PRE_PACKAGE_UPDATE          => [['populateFilesCacheDir', ~PHP_INT_MAX]],
            PackageEvents::POST_PACKAGE_INSTALL        => [['record'], ['installCustomTypes']],
            PackageEvents::POST_PACKAGE_UPDATE         => [['record'], ['enableThanksReminder']],
            PackageEvents::POST_PACKAGE_UNINSTALL      => 'record',
            ScriptEvents::POST_CREATE_PROJECT_CMD      => [['createProject'], ['postHarmonyProjectInstall']],
            ScriptEvents::POST_INSTALL_CMD             => 'install',
            ScriptEvents::POST_UPDATE_CMD              => [['harmonyProjectInitialize'], ['update']],
            PluginEvents::PRE_FILE_DOWNLOAD            => 'onFileDownload',
            'auto-scripts'                             => 'executeAutoScripts',
        ];
    }
}
