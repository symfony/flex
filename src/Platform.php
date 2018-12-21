<?php

namespace Harmony\Flex;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Harmony\Flex\Config\JsonConfigSource;
use Harmony\Flex\IO\ConsoleIO;
use Harmony\Flex\Platform\Handler\Project;
use Harmony\Flex\Repository\HarmonyRepository;
use Harmony\Flex\Util\Harmony as HarmonyUtil;
use Harmony\Sdk;

/**
 * Class Platform
 *
 * @package Harmony\Flex
 */
class Platform
{

    /** @var IOInterface|ConsoleIO $io */
    protected $io;

    /** @var Composer $composer */
    protected $composer;

    /** @var Sdk\Client $client */
    protected $client;

    /** @var Configurator $configurator */
    protected $configurator;

    /**
     * Check if platform is activated.
     * False if `api.harmonycms.net/ping` fail
     *
     * @var bool $activated
     */
    protected $activated = true;

    /** @var ScriptExecutor $executor */
    protected $executor;

    /**
     * Platform constructor.
     *
     * @param Composer       $composer
     * @param IOInterface    $io
     * @param Configurator   $configurator
     * @param ScriptExecutor $executor
     */
    public function __construct(Composer $composer, IOInterface $io, Configurator $configurator,
                                ScriptExecutor $executor)
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->client       = new Sdk\Client();
        $this->configurator = $configurator;
        $this->executor     = $executor;
    }

    /**
     * Check connectivity to HarmonyAPI server.
     *
     * @return bool
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function checkConnectivity(): bool
    {
        /** @var Sdk\Receiver\Events $events */
        $events = $this->client->getReceiver(Sdk\Client::RECEIVER_EVENTS);
        $ping   = $events->ping();
        // 1. Check HarmonyCMS API connectivity
        if (true === isset($ping['ping']) && 'pong' === $ping['ping']) {
            if ($this->io->isDebug()) {
                $this->io->success('Connectivity to ' . Sdk\Client::API_URL . ' successful!');
            }

            return $this->activated = true;
        }
        $this->io->error('Error connecting to HarmonyCMS API, unreachable host: ' . Sdk\Client::API_URL . '!');

        return $this->activated = false;
    }

    /**
     * Authenticate user through HarmonyAPI Server.
     *
     * @return string
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function authenticate(): string
    {
        $harmonyUtil = new HarmonyUtil($this->io, $this->composer->getConfig());

        // load global auth file
        $tokenFile = new JsonFile($this->composer->getConfig()->get('home') . '/auth.json');
        if (true === $tokenFile->exists()) {
            $jsonConfigSource = new JsonConfigSource($tokenFile, true);
            $this->composer->getConfig()->setAuthConfigSource($jsonConfigSource);

            $token = $jsonConfigSource->getConfigSetting('harmony-oauth.' . HarmonyRepository::REPOSITORY_NAME);
            if (null !== $token) {
                /** @var Sdk\Receiver\Events $events */
                $events      = $this->client->getReceiver(Sdk\Client::RECEIVER_EVENTS);
                $tokenStatus = $events->tokenStatus($token);
                if (isset($tokenStatus['status']) && 'authenticated' === $tokenStatus['status']) {
                    $this->client->setBearerToken($token);

                    /** @var Sdk\Receiver\Users $users */
                    $users = $this->client->getReceiver(Sdk\Client::RECEIVER_USERS);
                    $this->io->success('Welcome back "' . $users->getUser()['username'] . '"!');

                    return $token;
                }
            }
        }

        return $harmonyUtil->askOAuthInteractively($this->client);
    }

    /**
     * Gets instance of Project.
     *
     * @return Project
     * @throws \Http\Client\Exception
     */
    public function getProject(): Project
    {
        return new Project($this->io, $this->client, $this->composer, $this->configurator, $this->composer->getConfig(),
            $this->executor);
    }
}