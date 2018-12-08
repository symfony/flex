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

use Composer\Json\JsonFile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Lock
{
    private $json;
    private $lock = [];

    public function __construct($lockFile)
    {
        $this->json = new JsonFile($lockFile);
        if ($this->json->exists()) {
            $this->lock = $this->json->read();
        }
    }

    public function has($name): bool
    {
        return array_key_exists($name, $this->lock);
    }

    public function add($name, $data)
    {
        $current = $this->lock[$name] ?? [];
        $this->lock[$name] = array_merge($current, $data);
    }

    public function get($name)
    {
        return $this->lock[$name] ?? null;
    }

    public function set($name, $data)
    {
        $this->lock[$name] = $data;
    }

    public function remove($name)
    {
        unset($this->lock[$name]);
    }

    public function write()
    {
        if ($this->lock) {
            ksort($this->lock);
            $this->json->write($this->lock);
        } elseif ($this->json->exists()) {
            @unlink($this->json->getPath());
        }
    }

    public function all(): array
    {
        return $this->lock;
    }
}
