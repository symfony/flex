<?php

namespace Harmony\Flex\Platform\Handler;

use Composer\Composer;
use Composer\IO\IOInterface;
use Harmony\Flex\Options;
use Harmony\Flex\ScriptExecutor;
use Harmony\Sdk;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class AbstractHandler
 *
 * @package Harmony\Flex\Platform\Handler
 */
abstract class AbstractHandler
{

    /** @var Sdk\Client $client */
    protected $client;

    /** @var IOInterface|SymfonyStyle $io */
    protected $io;

    /** @var Composer $composer */
    protected $composer;

    /**
     * Abstract constructor.
     *
     * @param IOInterface $io
     * @param Sdk\Client  $client
     * @param Composer    $composer
     */
    public function __construct(IOInterface $io, Sdk\Client $client, Composer $composer)
    {
        $this->client   = $client;
        $this->io       = $io;
        $this->composer = $composer;
    }

    /**
     * Execute a command using ScriptExecutor class
     *
     * @param string $cmd
     */
    public function executeCommand(string $cmd)
    {
        $executor = new ScriptExecutor($this->composer, $this->io, $this->_initOptions());
        $executor->execute('symfony-cmd', $cmd);
    }

    /**
     * @return Options
     */
    private function _initOptions(): Options
    {
        $options = array_merge([
            'bin-dir'    => 'bin',
            'conf-dir'   => 'conf',
            'config-dir' => 'config',
            'src-dir'    => 'src',
            'var-dir'    => 'var',
            'public-dir' => 'public',
        ], $this->composer->getPackage()->getExtra());

        return new Options($options);
    }
}