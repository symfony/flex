<?php

namespace Harmony\Flex\Platform\Handler;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Harmony\Flex\Configurator;
use Harmony\Flex\Platform\Project as PlatformProject;
use Harmony\Flex\Platform\Settings;
use Harmony\Flex\Serializer\Normalizer\ProjectNormalizer;
use Harmony\Sdk;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

/**
 * Class Project
 *
 * @package Harmony\Flex\Platform
 */
class Project extends AbstractHandler
{

    /** @var Filesystem $fs */
    protected $fs;

    /** @var string $harmonyCacheFile */
    protected $harmonyCacheFile;

    /** @var PlatformProject $projectData */
    protected $projectData;

    /** @var Settings $settings */
    protected $settings;

    /** @var Stack $stack */
    protected $stack;

    /** @var InstallationManager $installationManager */
    protected $installationManager;

    /** @var Serializer $serializer */
    protected $serializer;

    /** @var Configurator $configurator */
    protected $configurator;

    /**
     * @var bool $activated
     */
    protected static $activated = true;

    /**
     * Project constructor.
     *
     * @param IOInterface  $io
     * @param Sdk\Client   $client
     * @param Composer     $composer
     * @param Configurator $configurator
     * @param Config       $config
     *
     * @throws \Http\Client\Exception
     */
    public function __construct(IOInterface $io, Sdk\Client $client, Composer $composer, Configurator $configurator,
                                Config $config)
    {
        parent::__construct($io, $client, $composer);
        $this->fs                  = new Filesystem();
        $this->harmonyCacheFile    = $config->get('data-dir') . '/harmony.json';
        $this->settings            = new Settings();
        $this->stack               = new Stack($io, $client, $composer);
        $this->installationManager = $composer->getInstallationManager();
        $this->serializer          = new Serializer([new ProjectNormalizer()], [new JsonEncoder()]);
        $this->configurator        = $configurator;

        $this->getOrAskForId();
    }

    /**
     * Removing useless/unused files or directories by HarmonyCMS, like `templates` folder
     *
     * @return void
     */
    public function clear(): void
    {
        $rootDir = dirname($this->composer->getConfig()->get('vendor-dir'));
        $this->fs->remove($rootDir . '/templates');
    }

    /**
     * Configure `DATABASE_URL` env variable from configured project information.
     *
     * @throws \Exception
     * @return void
     */
    public function configDatabases(): void
    {
        /**
         * TODO:
         * 1. Manage multiple databases.
         * 2. Manage other schemes (MONDODB, COUCHDB)
         */
        if ($this->projectData->hasDatabases()) {
            $this->configurator->get('env-project')->configure($this->projectData, []);

            //            // Comment old `DATABASE_URL` variable
            //            $comment = null;
            //            if (true === is_array($oldDatabaseUrl = $this->envWriter->get('DATABASE_URL'))) {
            //                $comment = $oldDatabaseUrl['key'] . '=' . $oldDatabaseUrl['value'];
            //            }
            //            // Update `DATABASE_URL` variable
            //            $databaseUrl = $this->settings->buildDatabaseUrl($this->projectData['databases'][0]);
            //            $this->envWriter->set('DATABASE_URL', $databaseUrl, $comment);
            //            // Save data
            //            // TODO: (Fix) Copy to `.env` even if database not present, otherwise it throw an exception
            //            $this->envWriter->save()->save('.env');
        }
    }

    /**
     * Ask user to initialize database.
     * 1. Create database
     * 2. Create schema
     *
     * @return void
     */
    public function initDatabase(): void
    {
        if ($this->projectData->hasDatabases()) {
            // Execute init commands for database
            if ($this->io->confirm('Initialize database?', false)) {
                $this->executeCommand('doctrine:database:create --if-not-exists');
                $this->executeCommand('doctrine:schema:update --force');
            }
        }
    }

    /**
     * Install stacks.
     * 1. Database stack (ORM, MongoDB, CouchDB)
     *
     * @return void
     */
    public function installStacks(): void
    {
        if ($this->projectData->hasDatabases()) {
            $config  = $this->stack->getConfigJson();
            $schemes = [];
            foreach ($this->projectData['databases'] as $database) {
                $schemes[$database['scheme']] = $database['scheme'];
            }
            foreach ($schemes as $scheme) {
                if (isset($config['doctrine']['scheme'][$scheme])) {
                    $package = $this->composer->getRepositoryManager()
                        ->findPackage($config['doctrine']['scheme'][$scheme], 'master');
                    if (null !== $package) {
                        $operation = new InstallOperation($package);
                        $this->installationManager->install(new InstalledFilesystemRepository(new JsonFile('php://memory')),
                            $operation);
                        $this->installationManager->notifyInstalls($this->io);
                    }
                }
            }
        }
    }

    /**
     * Install themes
     *
     * @return void
     */
    public function installThemes(): void
    {
        if (isset($this->projectData['themes'])) {
            foreach ($this->projectData['themes'] as $name => $value) {
                $package = $this->composer->getRepositoryManager()->findPackage($name, $value['version']);
                if (null !== $package) {
                    $operation = new InstallOperation($package);
                    $this->installationManager->install(new InstalledFilesystemRepository(new JsonFile('php://memory')),
                        $operation);
                    $this->installationManager->notifyInstalls($this->io);

                    // Dispatch event
                    $this->composer->getEventDispatcher()
                        ->dispatchPackageEvent(PackageEvents::POST_PACKAGE_INSTALL, false,
                            new DefaultPolicy(false, false), new Pool(), new CompositeRepository([]), new Request(),
                            [$operation], $operation);
                }
            }
        }
    }

    /**
     * Execute `fos:user:create` command to create a new user.
     *
     * @return void
     */
    public function createUser(): void
    {
        if ($this->projectData->hasDatabases()) {
            if ($this->io->confirm('Create super-admin user?', false)) {
                $this->executeCommand('fos:user:create --super-admin');
            }
        }
    }

    /**
     * Get or ask for ProjectID.
     *
     * @return bool
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    protected function getOrAskForId(): bool
    {
        if (true === $this->fs->exists($this->harmonyCacheFile)) {
            $file      = new SplFileInfo($this->harmonyCacheFile, '', '');
            $data      = (new JsonDecode(true))->decode($file->getContents(), JsonEncoder::FORMAT);
            $projectId = key((array)$data);

            /** @var Sdk\Receiver\Projects $projects */
            $projects    = $this->client->getReceiver(Sdk\Client::RECEIVER_PROJECTS);
            $projectData = $projects->getProject($projectId);

            if (null === $projectId || false === is_array($projectData) ||
                isset($projectData['code']) && 400 === $projectData['code']) {

                goto askForId;
            }
            $this->projectData = $this->serializer->deserialize(json_encode($projectData), PlatformProject::class,
                'json');

            return self::$activated = true;
        }

        askForId:

        $projectId
            = $this->io->ask("Please provide an HarmonyCMS Project ID or press any key to complete installation: ",
            null, function ($value) {
                return $value;
            });
        if (null !== $projectId) {
            $retries = 3;
            $step    = 1;
            while ($retries --) {
                /** @var Sdk\Receiver\Projects $projects */
                $projects    = $this->client->getReceiver(Sdk\Client::RECEIVER_PROJECTS);
                $projectData = $projects->getProject($projectId);
                if (is_array($projectData) || isset($projectData['code']) && 400 !== $projectData['code']) {
                    $this->projectData = $this->serializer->deserialize(json_encode($projectData),
                        PlatformProject::class, 'json');
                    try {
                        // store value in `harmony.json` file
                        $this->fs->dumpFile($this->harmonyCacheFile, json_encode([$projectId => []]));
                        $this->io->success('HarmonyCMS Project ID verified.');

                        return self::$activated = true;
                    }
                    catch (IOException $e) {
                        $this->io->error('Error saving project ID!');

                        return self::$activated = false;
                    }
                } else {
                    $this->io->error(sprintf('[%d/3] Invalid HarmonyCMS Project ID provided, please try again', $step));
                    ++ $step;
                    if ($retries) {
                        usleep(100000);
                        continue;
                    }
                }
            }
        }

        return self::$activated = false;
    }
}