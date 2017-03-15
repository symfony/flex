<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Package;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Package
{
    private $name;
    private $constraint;
    private $isDev;

    public function __construct($name, $constraint = null, $isDev = false)
    {
        $this->name = $name;
        $this->constraint = $constraint;
        $this->isDev = (bool) $isDev;
    }

    public function __toString()
    {
        return $this->name.(null !== $this->constraint ? ':'.$this->constraint : '');
    }

    public function getName()
    {
        return $this->name;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function isDev()
    {
        return $this->isDev;
    }
}
