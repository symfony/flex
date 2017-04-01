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

    public function get($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    public function expandTargetDir($target)
    {
        return preg_replace_callback('{%(.+?)%}', function ($matches) {
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($this->options[$option])) {
                throw new \InvalidArgumentException(sprintf('Placeholder "%s" does not exist.', $matches[1]));
            }

            return rtrim($this->options[$option], '/');
        }, $target);
    }
}
