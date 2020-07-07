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

use Composer\Semver\Constraint\MultiConstraint;

class MultiConstraintHelper extends MultiConstraint
{
    public static function accessConjunctive(parent $constraint)
    {
        return $constraint->conjunctive;
    }

    public static function accessConstraints(parent $constraint)
    {
        return $constraint->constraints;
    }
}
