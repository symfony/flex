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

use Composer\Package\Package;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Recipe;

class RecipeTest extends TestCase
{
    /**
     * @var Recipe
     */
    private $recipe;

    /**
     * @var Package|PHPUnit_Framework_MockObject_MockObject
     */
    private $package;

    protected function setUp()
    {
        $this->package = $this->getMockBuilder(Package::class)->disableOriginalConstructor()->getMock();
        $this->recipe = new Recipe(
            $this->package,
            'name',
            [
                'files' => ['file1'],
                'manifest' => ['manifest1']
            ]
        );
    }

    public function testItCanReturnThePackage()
    {
        $this->assertSame($this->package, $this->recipe->getPackage());
    }

    public function testItCanReturnTheName()
    {
        $this->assertSame('name', $this->recipe->getName());
    }

    public function testItCanReturnTheFiles()
    {
        $this->assertSame(['file1'], $this->recipe->getFiles());
    }

    public function testItCanReturnTheManifest()
    {
        $this->assertSame(['manifest1'], $this->recipe->getManifest());
    }
}
