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
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\CopyFromPackageConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class CopyFromPackageConfiguratorTest extends TestCase
{
    private $sourceFile;
    private $sourceDirectory;
    private $sourceFileRelativePath;
    private $targetFile;
    private $targetFileRelativePath;
    private $targetDirectory;
    private $recipe;
    private $composer;

    public function testNoFilesCopied()
    {
        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '');
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError')->with(['    Copying files from package']);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigureAndOverwriteFiles()
    {
        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        if (!file_exists($this->sourceDirectory)) {
            mkdir($this->sourceDirectory);
        }
        file_put_contents($this->sourceFile, 'somecontent');
        file_put_contents($this->targetFile, '-');
        $lock = $this->createStub(Lock::class);

        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });
        $io->method('askConfirmation')->with('File "build/public/file" has uncommitted changes, overwrite? [y/N] ')->willReturn(true);

        $this->assertFileExists($this->targetFile);
        $this->createConfigurator($io)->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath],
            $lock,
            ['force' => true]
        );
        $this->assertFileExists($this->targetFile);
        $this->assertFileEquals($this->sourceFile, $this->targetFile);

        $expected = [
            ['    Copying files from package'],
            ['      Created <fg=green>"./public/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testSourceFileNotExist()
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError')->with(['    Copying files from package']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('File "%s" does not exist!', $this->sourceFile));
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    public function testConfigure()
    {
        if (!is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory);
        }
        if (!file_exists($this->sourceFile)) {
            file_put_contents($this->sourceFile, '');
        }

        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });

        $this->assertFileDoesNotExist($this->targetFile);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
        $this->assertFileExists($this->targetFile);

        $expected = [
            ['    Copying files from package'],
            ['      Created <fg=green>"./public/"</>'],
            ['      Created <fg=green>"./public/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testUnconfigure()
    {
        $ioCalls = [];
        $io = $this->createStub(IOInterface::class);
        $io->method('writeError')->willReturnCallback(static function (array $lines) use (&$ioCalls) { $ioCalls[] = $lines; });

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->unconfigure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath, 'missingdir/' => ''],
            $lock
        );
        $this->assertFileDoesNotExist($this->targetFile);

        $expected = [
            ['    Removing files from package'],
            ['      Removed <fg=green>"./public/file"</>'],
        ];
        $this->assertSame($expected, $ioCalls);
    }

    public function testNoFilesRemoved()
    {
        $this->assertFileDoesNotExist($this->targetFile);
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError')->with(['    Removing files from package']);
        $lock = $this->createStub(Lock::class);
        $this->createConfigurator($io)->unconfigure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath], $lock);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDirectory = FLEX_TEST_DIR.'/package';
        $this->sourceFileRelativePath = 'package/file';
        $this->sourceFile = $this->sourceDirectory.'/file';

        $this->targetDirectory = FLEX_TEST_DIR.'/public';
        $this->targetFileRelativePath = 'public/file';
        $this->targetFile = $this->targetDirectory.'/file';

        $package = $this->createStub(PackageInterface::class);
        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipe->expects($this->once())->method('getPackage')->willReturn($package);

        $installationManager = $this->getMockBuilder(InstallationManager::class)->disableOriginalConstructor()->getMock();
        $installationManager->expects($this->once())
            ->method('getInstallPath')
            ->with($package)
            ->willReturn(FLEX_TEST_DIR)
        ;
        $this->composer = $this->getMockBuilder(Composer::class)->getMock();
        $this->composer->expects($this->once())
            ->method('getInstallationManager')
            ->willReturn($installationManager)
        ;

        $this->cleanUpTargetFiles();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->sourceFile);
        $this->cleanUpTargetFiles();
    }

    private function createConfigurator(IOInterface $io): CopyFromPackageConfigurator
    {
        return new CopyFromPackageConfigurator($this->composer, $io, new Options(['root-dir' => FLEX_TEST_DIR], $io));
    }

    private function cleanUpTargetFiles()
    {
        @unlink($this->targetFile);
        @rmdir(FLEX_TEST_DIR.'/package');
        @rmdir(FLEX_TEST_DIR.'/public');
    }
}
