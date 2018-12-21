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
use Harmony\Flex\Platform\Model\Project as ProjectModel;
use Harmony\Flex\Platform\Model\ProjectDatabase;
use Harmony\Flex\ScriptExecutor;
use Harmony\Flex\Serializer\Normalizer\ProjectNormalizer;
use Harmony\Sdk;
use Symfony\Component\Console\Style\SymfonyStyle;
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
class Project
{

    /** @var Sdk\Client $client */
    protected $client;

    /** @var IOInterface|SymfonyStyle $io */
    protected $io;

    /** @var Composer $composer */
    protected $composer;

    /** @var Filesystem $fs */
    protected $fs;

    /** @var string $harmonyCacheFile */
    protected $harmonyCacheFile;

    /** @var ProjectModel $projectData */
    protected $projectData;

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
    protected $activated = true;

    /** @var ScriptExecutor $executor */
    protected $executor;

    /**
     * Project constructor.
     *
     * @param IOInterface    $io
     * @param Sdk\Client     $client
     * @param Composer       $composer
     * @param Configurator   $configurator
     * @param Config         $config
     * @param ScriptExecutor $executor
     *
     * @throws \Http\Client\Exception
     */
    public function __construct(IOInterface $io, Sdk\Client $client, Composer $composer, Configurator $configurator,
                                Config $config, ScriptExecutor $executor)
    {
        $this->client              = $client;
        $this->io                  = $io;
        $this->composer            = $composer;
        $this->fs                  = new Filesystem();
        $this->harmonyCacheFile    = $config->get('data-dir') . '/harmony.json';
        $this->stack               = new Stack($io, $client, $composer);
        $this->installationManager = $composer->getInstallationManager();
        $this->serializer          = new Serializer([new ProjectNormalizer()], [new JsonEncoder()]);
        $this->configurator        = $configurator;
        $this->executor            = $executor;

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
        if ($this->projectData->hasDatabases()) {
            $this->configurator->get('env-project')->configure($this->projectData, []);
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
                $this->executor->execute('symfony-cmd', 'doctrine:database:create --if-not-exists');
                $this->executor->execute('symfony-cmd', 'doctrine:schema:update --force');
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
            /** @var ProjectDatabase $database */
            foreach ($this->projectData->getDatabases() as $database) {
                $schemes[$database->getScheme()] = $database->getScheme();
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
        if ($this->projectData->hasThemes()) {
            foreach ($this->projectData->getThemes() as $name => $value) {
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
                $this->executor->execute('symfony-cmd', 'fos:user:create --super-admin');
            }
        }
    }

    /**
     * @return bool
     */
    public function isActivated(): bool
    {
        return $this->activated;
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
            $this->projectData = $this->serializer->deserialize(json_encode($projectData), ProjectModel::class, 'json');

            return $this->activated = true;
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
                    $this->projectData = $this->serializer->deserialize(json_encode($projectData), ProjectModel::class,
                        'json');
                    try {
                        // store value in `harmony.json` file
                        $this->fs->dumpFile($this->harmonyCacheFile, json_encode([$projectId => []]));
                        $this->io->success('HarmonyCMS Project ID verified.');

                        return $this->activated = true;
                    }
                    catch (IOException $e) {
                        $this->io->error('Error saving project ID!');

                        return $this->activated = false;
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

        return $this->activated = false;
    }
}