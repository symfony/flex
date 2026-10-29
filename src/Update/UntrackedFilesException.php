<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Update;

/**
 * Thrown when a recipe update creates or changes files that exist in the app but are not tracked by git.
 */
class UntrackedFilesException extends \LogicException
{
}
