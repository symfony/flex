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

use Composer\Semver\VersionParser as BaseVersionParser;

class VersionParser extends BaseVersionParser
{
    public function normalize($version, $fullVersion = null)
    {
        $normalizedVersion = trim($version);

        // strip off aliasing
        if (preg_match('{^([^,\s]++) ++as ++([^,\s]++)$}', $normalizedVersion, $match)) {
            // verify that the alias is a version without constraint
            $this->normalize($match[2]);

            $normalizedVersion = $match[1];
        }

        // match master-like branches
        if (preg_match('{^(?:dev-)?(master|trunk|default)$}i', $normalizedVersion, $match)) {
            return 'dev-' . $match[1];
        }

        return parent::normalize($version, $fullVersion);
    }
}
