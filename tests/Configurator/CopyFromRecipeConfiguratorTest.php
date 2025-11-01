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
    private $recipe;

    public function testNoFilesCopied()
    {
        if (!file_exists($this->targetDirectory)) {
            @mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError')->with(['    Copying files from recipe']);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigureLocksFiles()
    {
        $this->recipe->method('getName')->willReturn('test-recipe');
        $lock = new Lock($this->targetDirectory.'/symfony.lock');

        $io = $this->createStub(IOInterface::class);
        $this->createConfigurator($io)->configure(
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
            @mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '-');
        $lock = $this->createStub(Lock::class);

        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });
        $io->method('askConfirmation')->with('File "build/config/file" has uncommitted changes, overwrite? [y/N] ')->willReturn(true);

        $this->assertFileExists($this->targetFile);
        $this->createConfigurator($io)->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock,
            ['force' => true]
        );
        $this->assertFileExists($this->targetFile);
        $this->assertSame('somecontent', file_get_contents($this->targetFile));

        $expected = [
            ['    Copying files from recipe'],
            ['      Created <fg=green>"./config/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testConfigure()
    {
        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });

        $this->assertFileDoesNotExist($this->targetFile);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock
        );
        $this->assertFileExists($this->targetFile);

        $expected = [
            ['    Copying files from recipe'],
            ['      Created <fg=green>"./config/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testUnconfigureKeepsLockedFiles()
    {
        if (!file_exists($this->sourceDirectory)) {
            @mkdir($this->sourceDirectory, 0777, true);
        }
        if (!file_exists($this->targetDirectory)) {
            @mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        file_put_contents($this->sourceFile, '-');

        $lock = new Lock(FLEX_TEST_DIR.'/test.lock');
        $lock->set('other-recipe', ['files' => [$this->targetFileRelativePath]]);

        $this->recipe->method('getName')->willReturn('test-recipe');
        $io = $this->createStub(IOInterface::class);
        $this->createConfigurator($io)->unconfigure($this->recipe, [$this->targetFileRelativePath], $lock);

        $this->assertFileExists($this->sourceFile);
        $this->assertFileExists($this->targetFile);
    }

    public function testUnconfigure()
    {
        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });

        if (!file_exists($this->targetDirectory)) {
            @mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $lock = $this->createStub(Lock::class);
        $this->recipe->method('getName')->willReturn('test-recipe');
        $this->createConfigurator($io)->unconfigure($this->recipe, [$this->targetFileRelativePath], $lock);
        $this->assertFileDoesNotExist($this->targetFile);

        $expected = [
            ['    Removing files from recipe'],
            ['      Removed <fg=green>"./config/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testNoFilesRemoved()
    {
        $this->assertFileDoesNotExist($this->targetFile);
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError')->with(['    Removing files from recipe']);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->unconfigure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testUpdate()
    {
        $io = $this->createStub(IOInterface::class);
        $configurator = $this->createConfigurator($io);

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

        $originalRecipe = $this->createStub(Recipe::class);
        $originalRecipe->method('getName')
            ->willReturn('test-package');
        $originalRecipe->method('getFiles')
            ->willReturn($originalRecipeFileData);

        $newRecipe = $this->createStub(Recipe::class);
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

    public function testUpdateResolveDirectories()
    {
        $io = $this->createStub(IOInterface::class);
        $configurator = $this->createConfigurator($io);

        $lock = $this->createMock(Lock::class);
        $lock->expects($this->once())
            ->method('add')
            ->with(
                'test-package',
                [
                    'files' => [
                        'config/packages/framework.yaml',
                        'test.yaml',
                    ],
                ]
            );

        $originalRecipeFiles = [
            'symfony8config/packages/framework.yaml' => 'before',
            'root/test.yaml' => 'before',
        ];
        $newRecipeFiles = [
            'symfony8config/packages/framework.yaml' => 'after',
            'root/test.yaml' => 'after',
        ];

        $originalRecipeFileData = [];
        foreach ($originalRecipeFiles as $file => $contents) {
            $originalRecipeFileData[$file] = ['contents' => $contents, 'executable' => false];
        }

        $newRecipeFileData = [];
        foreach ($newRecipeFiles as $file => $contents) {
            $newRecipeFileData[$file] = ['contents' => $contents, 'executable' => false];
        }

        $originalRecipe = $this->createStub(Recipe::class);
        $originalRecipe->method('getName')
            ->willReturn('test-package');
        $originalRecipe->method('getFiles')
            ->willReturn($originalRecipeFileData);

        $newRecipe = $this->createStub(Recipe::class);
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
            [
                'root/' => '',
                'symfony8config/' => '%CONFIG_DIR%/',
            ],
            [
                'root/' => '',
                'symfony8config/' => '%CONFIG_DIR%/',
            ]
        );

        // Due to root/ => '', we expect that root/ has been stripped
        $this->assertArrayHasKey('test.yaml', $recipeUpdate->getOriginalFiles());
        $this->assertArrayHasKey('test.yaml', $recipeUpdate->getNewFiles());

        $this->assertSame('after', $recipeUpdate->getNewFiles()['test.yaml']);

        // %CONFIG-DIR%, got resolved to config/packages back
        $this->assertArrayHasKey('config/packages/framework.yaml', $recipeUpdate->getOriginalFiles());
        $this->assertArrayHasKey('config/packages/framework.yaml', $recipeUpdate->getNewFiles());

        $this->assertSame('after', $recipeUpdate->getNewFiles()['config/packages/framework.yaml']);
    }

    protected function setUp(): void
    {
        $this->sourceDirectory = FLEX_TEST_DIR.'/source';
        $this->sourceFileRelativePath = 'source/file';
        $this->sourceFile = $this->sourceDirectory.'/file';

        $this->targetDirectory = FLEX_TEST_DIR.'/config';
        $this->targetFileRelativePath = 'config/file';
        $this->targetFile = $this->targetDirectory.'/file';

        $this->recipe = $this->createStub(Recipe::class);
        $this->recipe->method('getFiles')->willReturn([
            $this->sourceFileRelativePath => [
                'contents' => 'somecontent',
                'executable' => false,
            ],
        ]);

        $this->cleanUpTargetFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTargetFiles();
    }

    private function createConfigurator(IOInterface $io): CopyFromRecipeConfigurator
    {
        $lock = new Lock(FLEX_TEST_DIR.'/test.lock');
        $lock->set('test-recipe', ['files' => [$this->targetFileRelativePath]]);
        $options = new Options(['root-dir' => FLEX_TEST_DIR, 'config-dir' => 'config'], $io, $lock);

        return new CopyFromRecipeConfigurator($this->createStub(Composer::class), $io, $options);
    }

    private function cleanUpTargetFiles()
    {
        @unlink($this->targetFile);
        @rmdir($this->targetDirectory);
    }
}
