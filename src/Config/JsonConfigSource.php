<?php

namespace Harmony\Flex\Config;

use Composer\Config\JsonConfigSource as BaseJsonConfigSource;
use Composer\Json\JsonFile;

/**
 * Class JsonConfigSource
 *
 * @package Harmony\Flex\Config
 */
class JsonConfigSource extends BaseJsonConfigSource
{

    /**
     * @var JsonFile
     */
    protected $file;

    /**
     * JsonConfigSource constructor.
     *
     * @param JsonFile $file
     * @param bool     $authConfig
     */
    public function __construct(JsonFile $file, bool $authConfig = false)
    {
        $this->file = $file;
        parent::__construct($file, $authConfig);
    }

    /**
     * @param string $name
     *
     * @return null|string
     */
    public function getConfigSetting(string $name): ?string
    {
        list($mainNode, $name) = explode('.', $name, 2);

        $decoded = $this->file->read();

        if (isset($decoded[$mainNode]) && isset($decoded[$mainNode][$name])) {
            return $decoded[$mainNode][$name];
        }

        return null;
    }
}