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
        $this->lock[$name] = $data;
    }

    public function remove($name)
    {
        unset($this->lock[$name]);
    }

    public function write()
    {
        ksort($this->lock);
        $this->json->write($this->lock);
    }
}
