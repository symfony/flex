<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Semver;

use Composer\Semver\Constraint\Bound;
use Composer\Semver\Constraint\ConstraintInterface;

class FullConstraint implements ConstraintInterface
{
    protected $prettyString;

    public function matches(ConstraintInterface $provider)
    {
        return false;
    }

    public function compile($operator)
    {
        return 'false';
    }

    public function setPrettyString($prettyString)
    {
        $this->prettyString = $prettyString;
    }

    public function getPrettyString()
    {
        if ($this->prettyString) {
            return $this->prettyString;
        }

        return (string) $this;
    }

    public function __toString()
    {
        return '[]';
    }

    public function getUpperBound()
    {
        return new Bound('0.0.0.0-dev', false);
    }

    public function getLowerBound()
    {
        return new Bound('0.0.0.0-dev', false);
    }
}
