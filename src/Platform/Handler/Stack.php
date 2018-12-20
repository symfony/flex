<?php

namespace Harmony\Flex\Platform\Handler;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Harmony\Sdk;

/**
 * Class Stack
 *
 * @package Harmony\Flex\Platform\Handler
 */
class Stack extends AbstractHandler
{

    /** Constants */
    const REPOSITORY = 'https://git.harmonycms.net';
    const ENDPOINT   = 'https://raw.githubusercontent.com/harmonycms/stacks/master';

    /** @var mixed $configJson */
    protected $configJson;

    /** @var RemoteFilesystem $rfs */
    protected $rfs;

    /**
     * Stack constructor.
     *
     * @param IOInterface $io
     * @param Sdk\Client  $client
     * @param Composer    $composer
     */
    public function __construct(IOInterface $io, Sdk\Client $client, Composer $composer)
    {
        parent::__construct($io, $client, $composer);
        $this->rfs = Factory::createRemoteFilesystem($this->io, $this->composer->getConfig());

        $json             = new JsonFile(self::ENDPOINT . '/config.json', $this->rfs);
        $this->configJson = $json->read();
    }

    /**
     * Get data of config.json file.
     *
     * @return array
     */
    public function getConfigJson(): array
    {
        if (is_array($this->configJson)) {
            return $this->configJson;
        }

        return [];
    }
}