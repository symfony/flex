<?php

/**
 * (c) FSi sp. z o.o. <info@fsi.pl>
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
}
