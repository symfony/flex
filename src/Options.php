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

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Options
{
    private $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function get(string $name)
    {
        return $this->options[$name] ?? null;
    }

    public function expandTargetDir(string $target): string
    {
        return preg_replace_callback('{%(.+?)%}', function ($matches) {
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($this->options[$option])) {
                return $matches[0];
            }

            return rtrim($this->options[$option], '/');
        }, $target);
    }
}
