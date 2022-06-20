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

use Composer\Installer\InstallationManager;
use Symfony\Flex\Configurator\CopyFromPackageConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class CopyDirectoryFromPackageConfiguratorTest extends ConfiguratorTest
{
    private $sourceFiles = [];
    private $sourceDirectory;
    private $sourceFileRelativePath;
    private $targetFiles = [];
    private $targetFileRelativePath;
    private $targetDirectory;
    private $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = FLEX_TEST_DIR.'/package/files';
        $this->sourceFileRelativePath = 'package/files/';
        $this->sourceFiles = [
            $this->sourceDirectory.'/file1',
            $this->sourceDirectory.'/file2',
        ];

        $this->targetDirectory = FLEX_TEST_DIR.'/public/files';
        $this->targetFileRelativePath = 'public/files/';
        $this->targetFiles = [
            $this->targetDirectory.'/file1',
            $this->targetDirectory.'/file2',
        ];

        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();

        $this->cleanUpTargetFiles();
    }

    protected function createConfigurator(): CopyFromPackageConfigurator
    {
        return new CopyFromPackageConfigurator(
            $this->composer,
            $this->io,
            new Options(['root-dir' => FLEX_TEST_DIR], $this->io)
        );
    }

    public function testConfigureDirectory()
    {
        $this->mockInstallationManager();

        if (!is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0777, true);
        }
        foreach ($this->sourceFiles as $sourceFile) {
            if (!file_exists($sourceFile)) {
                file_put_contents($sourceFile, '');
            }
        }

        foreach ($this->targetFiles as $targetFile) {
            $this->assertFileDoesNotExist($targetFile);
        }
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->configure($this->recipe, [
            $this->sourceFileRelativePath => $this->targetFileRelativePath,
        ], $lock);
        foreach ($this->targetFiles as $targetFile) {
            $this->assertFileExists($targetFile);
        }
    }

    /**
     * @dataProvider providerTestConfigureDirectoryWithExistingFiles
     */
    public function testConfigureDirectoryWithExistingFiles(bool $force, string $sourceFileContent, string $existingTargetFileContent, string $expectedFinalTargetFileContent)
    {
        $this->mockInstallationManager();

        if (!is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0777, true);
        }
        foreach ($this->sourceFiles as $sourceFile) {
            if (!file_exists($sourceFile)) {
                file_put_contents($sourceFile, $sourceFileContent);
            }
        }

        if (!is_dir($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0777, true);
        }

        foreach ($this->targetFiles as $targetFile) {
            file_put_contents($targetFile, $existingTargetFileContent);
        }

        $this->io->method('askConfirmation')->willReturn(true);

        $this->configurator->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock(),
            ['force' => $force]
        );

        foreach ($this->targetFiles as $targetFile) {
            $this->assertFileExists($targetFile);
            $content = file_get_contents($targetFile);
            $this->assertEquals($expectedFinalTargetFileContent, $content);
        }
    }

    public function providerTestConfigureDirectoryWithExistingFiles(): array
    {
        return [
            [true, 'NEW_CONTENT', 'OLD_CONTENT', 'NEW_CONTENT'],
            [false, 'NEW_CONTENT', 'OLD_CONTENT', 'OLD_CONTENT'],
        ];
    }

    public function testUpdate()
    {
        $this->mockInstallationManager();

        $recipeUpdate = new RecipeUpdate(
            $this->createMock(Recipe::class),
            $this->recipe,
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR.'/package/files1', 0777, true);
        @mkdir(FLEX_TEST_DIR.'/package/files2', 0777, true);
        @mkdir(FLEX_TEST_DIR.'/package/files3', 0777, true);

        touch(FLEX_TEST_DIR.'/package/files1/1a.txt');
        touch(FLEX_TEST_DIR.'/package/files1/1b.txt');
        touch(FLEX_TEST_DIR.'/package/files2/2a.txt');
        touch(FLEX_TEST_DIR.'/package/files2/2b.txt');
        touch(FLEX_TEST_DIR.'/package/files3/3a.txt');
        touch(FLEX_TEST_DIR.'/package/files3/3b.txt');

        $this->configurator->update(
            $recipeUpdate,
            ['package/files1/' => 'target/files1/', 'package/files2/' => 'target/files2/'],
            ['package/files1/' => 'target/files1/', 'package/files3/' => 'target/files3/']
        );

        // original files always show as empty: we don't know what they are
        // even for "package/files2", which was removed, for safety, we don't delete it
        $this->assertSame([], $recipeUpdate->getOriginalFiles());

        // only NEW copy paths are installed
        $newFiles = array_keys($recipeUpdate->getNewFiles());
        asort($newFiles);
        $this->assertSame(
            ['target/files3/3a.txt', 'target/files3/3b.txt'],
            array_values($newFiles)
        );
        $this->assertSame([FLEX_TEST_DIR.'/package/files1/' => 'target/files1/'], $recipeUpdate->getCopyFromPackagePaths());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->sourceFiles as $sourceFile) {
            @unlink($sourceFile);
        }
        $this->cleanUpTargetFiles();
    }

    private function cleanUpTargetFiles()
    {
        $this->rrmdir(FLEX_TEST_DIR.'/package');
        $this->rrmdir(FLEX_TEST_DIR.'/public');
    }

    /**
     * Courtesy of http://php.net/manual/en/function.rmdir.php#98622.
     */
    private function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ('.' !== $object && '..' !== $object) {
                    if ('dir' == filetype($dir.'/'.$object)) {
                        $this->rrmdir($dir.'/'.$object);
                    } else {
                        unlink($dir.'/'.$object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    private function mockInstallationManager(): void
    {
        $this->recipe->expects($this->once())->method('getPackage')->willReturn($this->package);

        $installationManager = $this->getMockBuilder(InstallationManager::class)->disableOriginalConstructor()->getMock();
        $installationManager->expects($this->once())
            ->method('getInstallPath')
            ->with($this->package)
            ->willReturn(FLEX_TEST_DIR)
        ;

        $this->composer->expects($this->once())
            ->method('getInstallationManager')
            ->willReturn($installationManager)
        ;
    }
}
