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
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class RecipeUpdateTest extends TestCase
{
    private $originalRecipe;
    private $newRecipe;
    private $lock;
    private $rootDir;
    private $update;

    protected function setUp(): void
    {
        $this->originalRecipe = $this->createMock(Recipe::class);
        $this->newRecipe = $this->createMock(Recipe::class);
        $this->lock = new Lock('lock_file');
        $this->rootDir = '/path/to/here';
        $this->update = new RecipeUpdate($this->originalRecipe, $this->newRecipe, $this->lock, $this->rootDir);
    }

    public function testGetters()
    {
        $this->assertSame($this->originalRecipe, $this->update->getOriginalRecipe());
        $this->assertSame($this->newRecipe, $this->update->getNewRecipe());
        $this->assertSame($this->lock, $this->update->getLock());
        $this->assertSame($this->rootDir, $this->update->getRootDir());
    }

    public function testOriginalFiles()
    {
        $this->update->setOriginalFile('file1', 'file1_contents');
        $this->update->addOriginalFiles([
            'file2' => 'file2_contents',
            'file3' => 'file3_contents',
        ]);

        $this->assertSame(
            ['file1' => 'file1_contents', 'file2' => 'file2_contents', 'file3' => 'file3_contents'],
            $this->update->getOriginalFiles()
        );
    }

    public function testNewFiles()
    {
        $this->update->setNewFile('file1', 'file1_contents');
        $this->update->addNewFiles([
            'file2' => 'file2_contents',
            'file3' => 'file3_contents',
        ]);

        $this->assertSame(
            ['file1' => 'file1_contents', 'file2' => 'file2_contents', 'file3' => 'file3_contents'],
            $this->update->getNewFiles()
        );
    }
}
