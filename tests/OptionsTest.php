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
use Symfony\Flex\Options;

class OptionsTest extends TestCase
{
    public function testItCanRetrieveOptions()
    {
        $options = new Options(['foo' => 'bar']);
        $option = $options->get('foo');
        $this->assertSame('bar', $option);
    }

    public function testItDefaultsToNull()
    {
        $options = new Options([]);
        $option = $options->get('foo');
        $this->assertNull($option);
    }

    public function testItCanIdentifyVarsInTargetDir()
    {
        $options = new Options(['foo' => 'bar/']);
        $expandedTargetDir = $options->expandTargetDir('%foo%');
        $this->assertSame('bar', $expandedTargetDir);
    }
}
