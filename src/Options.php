<?php

namespace Symfony\Start;

class Options
{
    private $options;

    public function __construct(array $options = array())
    {
        $this->options = $options;
    }

    public function get($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    public function expandTargetDir($target)
    {
        $options = $this->options;

        return preg_replace_callback('{%(.+?)%}', function ($matches) use ($options) {
// FIXME: we should have a validator checking recipes when they are merged into the repo
// so that exceptions here are just not possible
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($options[$option])) {
                throw new \InvalidArgumentException(sprintf('Placeholder "%s" does not exist.', $matches[1]));
            }

            return $options[$option];
        }, $target);
    }
}
