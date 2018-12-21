<?php

namespace Harmony\Flex;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Harmony\Flex\IO\ConsoleIO;
use Harmony\Flex\Platform\Handler\Authentication;
use Harmony\Flex\Platform\Handler\Connectivity;
use Harmony\Flex\Platform\Handler\Project;
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
     * Platform constructor.
     *
     * @param Composer     $composer
     * @param IOInterface  $io
     * @param Configurator $configurator
     */
    public function __construct(Composer $composer, IOInterface $io, Configurator $configurator)
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->client       = new Sdk\Client();
        $this->configurator = $configurator;
    }

    /**
     * Check connectivity to HarmonyAPI server.
     *
     * @return bool
     * @throws \Http\Client\Exception
     */
    public function checkConnectivity(): bool
    {
        return (new Connectivity($this->io, $this->client, $this->composer))->check();
    }

    /**
     * Authenticate user through HarmonyAPI Server.
     *
     * @return string
     * @throws \Http\Client\Exception
     */
    public function authenticate(): string
    {
        return (new Authentication($this->io, $this->client, $this->composer))->authenticate();
    }

    /**
     * Gets instance of Project.
     *
     * @param Config $config
     *
     * @return Project
     */
    public function getProject(Config $config): Project
    {
        return new Project($this->io, $this->client, $this->composer, $this->configurator, $config);
    }
}