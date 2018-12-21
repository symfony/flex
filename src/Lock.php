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

use Composer\Json\JsonFile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Lock
{

    /** @var JsonFile $json */
    private $json;

    /** @var array|mixed $ */
    private $lock = [];

    /**
     * Lock constructor.
     *
     * @param $lockFile
     */
    public function __construct($lockFile)
    {
        $this->json = new JsonFile($lockFile);
        if ($this->exists()) {
            $this->lock = $this->json->read();
        }
    }

    /**
     * Check if lock file exists
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->json->exists();
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->lock);
    }

    /**
     * @param string $name
     *
     * @return array|null
     */
    public function get(string $name): ?array
    {
        return $this->lock[$name] ?? null;
    }

    /**
     * @param string $name
     * @param mixed  $data
     */
    public function add(string $name, $data)
    {
        $this->lock[$name] = $data;
    }

    /**
     * @param string $name
     */
    public function remove(string $name)
    {
        unset($this->lock[$name]);
    }

    /**
     * @throws \Exception
     */
    public function write()
    {
        if ($this->lock) {
            ksort($this->lock);
            $this->json->write($this->lock);
        } elseif ($this->exists()) {
            @unlink($this->json->getPath());
        }
    }
}
