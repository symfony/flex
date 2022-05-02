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
use LogicException;
use Symfony\Flex\Configurator\CopyFromPackageConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class CopyFromPackageConfiguratorTest extends ConfiguratorTest
{
    private $sourceFile;
    private $sourceDirectory;
    private $sourceFileRelativePath;
    private $targetFile;
    private $targetFileRelativePath;
    private $targetDirectory;
    private $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = FLEX_TEST_DIR.'/package';
        $this->sourceFileRelativePath = 'package/file';
        $this->sourceFile = $this->sourceDirectory.'/file';

        $this->targetDirectory = FLEX_TEST_DIR.'/public';
        $this->targetFileRelativePath = 'public/file';
        $this->targetFile = $this->targetDirectory.'/file';

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

    public function testNoFilesCopied()
    {
        $this->mockInstallationManager();

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Copying files from package']);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigureAndOverwriteFiles()
    {
        $this->mockInstallationManager();

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0777, true);
        }
        if (!file_exists($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0777, true);
        }
        file_put_contents($this->sourceFile, 'somecontent');
        file_put_contents($this->targetFile, '-');
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $this->io->expects($this->at(0))->method('writeError')->with(['    Copying files from package']);
        $this->io->expects($this->at(2))->method('writeError')->with(['      Created <fg=green>"./public/file"</>']);
        $this->io->method('askConfirmation')->with('File "build/public/file" has uncommitted changes, overwrite? [y/N] ')->willReturn(true);

        $this->assertFileExists($this->targetFile);
        $this->configurator->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock,
            ['force' => true]
        );
        $this->assertFileExists($this->targetFile);
        $this->assertFileEquals($this->sourceFile, $this->targetFile);
    }

    public function testSourceFileNotExist()
    {
        $this->mockInstallationManager();

        $this->io->expects($this->once())->method('writeError')->with(['    Copying files from package']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('File "%s" does not exist!', $this->sourceFile));
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigure()
    {
        $this->mockInstallationManager();

        if (!is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0777, true);
        }
        if (!file_exists($this->sourceFile)) {
            file_put_contents($this->sourceFile, '');
        }

        $this->io->expects($this->at(0))->method('writeError')->with(['    Copying files from package']);
        $this->io->expects($this->at(1))->method('writeError')->with(['      Created <fg=green>"./public/"</>']);
        $this->io->expects($this->at(2))->method('writeError')->with(['      Created <fg=green>"./public/file"</>']);

        $this->assertFileDoesNotExist($this->targetFile);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
        $this->assertFileExists($this->targetFile);
    }

    public function testUnconfigure()
    {
        $this->mockInstallationManager();

        $this->io->expects($this->at(0))->method('writeError')->with(['    Removing files from package']);
        $this->io->expects($this->at(1))->method('writeError')->with(['      Removed <fg=green>"./public/file"</>']);

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0777, true);
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->unconfigure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath, 'missingdir/' => ''],
            $lock
        );
        $this->assertFileDoesNotExist($this->targetFile);
    }

    public function testNoFilesRemoved()
    {
        $this->mockInstallationManager();

        $this->assertFileDoesNotExist($this->targetFile);
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Removing files from package']);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->unconfigure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->sourceFile);
        $this->cleanUpTargetFiles();
    }

    private function cleanUpTargetFiles()
    {
        @unlink($this->targetFile);
        @rmdir(FLEX_TEST_DIR.'/package');
        @rmdir(FLEX_TEST_DIR.'/public');
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
