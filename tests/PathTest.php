<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Flex\Path;

class PathTest extends TestCase
{
    public function testConcatenateOnWindows()
    {
        $path = new Path('');

        $this->assertEquals('c:\\my-project/src/kernel.php', $path->concatenate(['c:\\my-project', 'src/', 'kernel.php']));
    }

    /**
     * @dataProvider providePathsForConcatenation
     */
    public function testConcatenate($part1, $part2, $expectedPath)
    {
        $path = new Path('');

        $actualPath = $path->concatenate([$part1, $part2]);

        $this->assertEquals($expectedPath, $actualPath);
    }

    public function providePathsForConcatenation()
    {
        return [
            [__DIR__, 'foo/bar.txt', __DIR__.'/foo/bar.txt'],
            [__DIR__, '/foo/bar.txt', __DIR__.'/foo/bar.txt'],
            ['', 'foo/bar.txt', '/foo/bar.txt'],
            ['', '/foo/bar.txt', '/foo/bar.txt'],
            ['.', 'foo/bar.txt', './foo/bar.txt'],
        ];
    }
}
