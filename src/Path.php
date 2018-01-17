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
 * @internal
 */
class Path
{
    private $workingDirectory;

    public function __construct(string $workingDirectory)
    {
        $this->workingDirectory = $workingDirectory;
    }

    public function relativize(string $absolutePath): string
    {
        $relativePath = str_replace($this->workingDirectory, '.', $absolutePath);

        return is_dir($absolutePath) ? rtrim($relativePath, '/'.DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : $relativePath;
    }

    public function concatenate(array $parts): string
    {
        return array_reduce($parts, function (string $initial, string $next): string {
            if ('' === $initial) {
                return rtrim($next, '/'.DIRECTORY_SEPARATOR);
            }

            return rtrim($initial, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($next, DIRECTORY_SEPARATOR);
        }, '');
    }
}
