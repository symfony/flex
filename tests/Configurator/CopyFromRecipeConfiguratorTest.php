<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\CopyFromRecipeConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class CopyFromRecipeConfiguratorTest extends TestCase
{
    private $sourceFile;
    private $sourceFileRelativePath;
    private $sourceDirectory;
    private $targetFile;
    private $targetFileRelativePath;
    private $targetDirectory;
    private $io;
    private $recipe;

    public function testNoFilesCopied()
    {
        if (!file_exists($this->targetDirectory)) {
            @mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Copying files from recipe']);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->createConfigurator()->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigureLocksFiles()
    {
        $this->recipe->method('getName')->willReturn('test-recipe');
        $lock = new Lock($this->targetDirectory.'/symfony.lock');

        $this->createConfigurator()->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock
        );
        $lockedRecipe = $lock->get('test-recipe');

        $this->assertArrayHasKey('files', $lockedRecipe);
        $this->assertSame($this->targetFileRelativePath, $lockedRecipe['files'][0]);
    }

    public function testConfigureAndOverwriteFiles()
    {
        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '-');
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $this->io->expects($this->at(0))->method('writeError')->with(['    Copying files from recipe']);
        $this->io->expects($this->at(2))->method('writeError')->with(['      Created <fg=green>"./config/file"</>']);
        $this->io->method('askConfirmation')->with('File "build/config/file" has uncommitted changes, overwrite? [y/N] ')->willReturn(true);

        $this->assertFileExists($this->targetFile);
        $this->createConfigurator()->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock,
            ['force' => true]
        );
        $this->assertFileExists($this->targetFile);
        $this->assertSame('somecontent', file_get_contents($this->targetFile));
    }

    public function testConfigure()
    {
        $this->io->expects($this->at(0))->method('writeError')->with(['    Copying files from recipe']);
        $this->io->expects($this->at(1))->method('writeError')->with(['      Created <fg=green>"./config/file"</>']);

        $this->assertFileDoesNotExist($this->targetFile);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->createConfigurator()->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock
        );
        $this->assertFileExists($this->targetFile);
    }

    public function testUnconfigureKeepsLockedFiles()
    {
        if (!file_exists($this->sourceDirectory)) {
            mkdir($this->sourceDirectory);
        }
        file_put_contents($this->sourceFile, '-');
        $this->assertFileExists($this->sourceFile);

        $lock = new Lock(FLEX_TEST_DIR.'/test.lock');
        $lock->set('other-recipe', ['files' => ['./'.$this->targetFileRelativePath]]);

        $this->createConfigurator()->unconfigure($this->recipe, [$this->targetFileRelativePath], $lock);

        $this->assertFileExists($this->sourceFile);
    }

    public function testUnconfigure()
    {
        $this->io->expects($this->at(0))->method('writeError')->with(['    Removing files from recipe']);
        $this->io->expects($this->at(1))->method('writeError')->with(['      Removed <fg=green>"./config/file"</>']);

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->createConfigurator()->unconfigure($this->recipe, [$this->targetFileRelativePath], $lock);
        $this->assertFileDoesNotExist($this->targetFile);
    }

    public function testNoFilesRemoved()
    {
        $this->assertFileDoesNotExist($this->targetFile);
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Removing files from recipe']);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->createConfigurator()->unconfigure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testUpdate()
    {
        $configurator = $this->createConfigurator();

        $lock = $this->createMock(Lock::class);
        $lock->expects($this->once())
            ->method('add')
            ->with('test-package', ['files' => ['config/packages/webpack_encore.yaml', 'config/packages/new.yaml']]);

        $originalRecipeFiles = [
            'config/packages/webpack_encore.yaml' => '... encore',
            'config/packages/other.yaml' => '... other',
        ];
        $newRecipeFiles = [
            'config/packages/webpack_encore.yaml' => '... encore_updated',
            'config/packages/new.yaml' => '... new',
        ];

        $originalRecipeFileData = [];
        foreach ($originalRecipeFiles as $file => $contents) {
            $originalRecipeFileData[$file] = ['contents' => $contents, 'executable' => false];
        }

        $newRecipeFileData = [];
        foreach ($newRecipeFiles as $file => $contents) {
            $newRecipeFileData[$file] = ['contents' => $contents, 'executable' => false];
        }

        $originalRecipe = $this->createMock(Recipe::class);
        $originalRecipe->method('getName')
            ->willReturn('test-package');
        $originalRecipe->method('getFiles')
            ->willReturn($originalRecipeFileData);

        $newRecipe = $this->createMock(Recipe::class);
        $newRecipe->method('getFiles')
            ->willReturn($newRecipeFileData);

        $recipeUpdate = new RecipeUpdate(
            $originalRecipe,
            $newRecipe,
            $lock,
            FLEX_TEST_DIR
        );

        $configurator->update(
            $recipeUpdate,
            [],
            []
        );

        $this->assertSame($originalRecipeFiles, $recipeUpdate->getOriginalFiles());
        $this->assertSame($newRecipeFiles, $recipeUpdate->getNewFiles());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = FLEX_TEST_DIR.'/source';
        $this->sourceFileRelativePath = 'source/file';
        $this->sourceFile = $this->sourceDirectory.'/file';

        $this->targetDirectory = FLEX_TEST_DIR.'/config';
        $this->targetFileRelativePath = 'config/file';
        $this->targetFile = $this->targetDirectory.'/file';

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();
        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipe->expects($this->any())->method('getFiles')->willReturn([
            $this->sourceFileRelativePath => [
                'contents' => 'somecontent',
                'executable' => false,
            ],
        ]);

        $this->cleanUpTargetFiles();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->cleanUpTargetFiles();
    }

    private function createConfigurator(): CopyFromRecipeConfigurator
    {
        return new CopyFromRecipeConfigurator($this->getMockBuilder(Composer::class)->getMock(), $this->io, new Options(['root-dir' => FLEX_TEST_DIR], $this->io));
    }

    private function cleanUpTargetFiles()
    {
        @unlink($this->targetFile);
        @rmdir($this->targetDirectory);
    }
}
