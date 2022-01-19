<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Update;

use PHPUnit\Framework\TestCase;
use Symfony\Flex\Update\RecipePatch;

class RecipePatchTest extends TestCase
{
    public function testBasicFunctioning()
    {
        $thePatch = 'the patch';
        $blobs = ['blob1', 'blob2', 'beware of the blob'];
        $removedPatches = ['foo' => 'some diff'];

        $patch = new RecipePatch($thePatch, $blobs, $removedPatches);

        $this->assertSame($thePatch, $patch->getPatch());
        $this->assertSame($blobs, $patch->getBlobs());
        $this->assertSame($removedPatches, $patch->getRemovedPatches());
    }
}
