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

require_once __DIR__.'/TmpDirMock.php';

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\CopyFromPackageConfigurator;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class CopyDirectoryFromPackageConfiguratorTest extends TestCase
{
    private $sourceFiles = [];
    private $sourceDirectory;
    private $sourceFileRelativePath;
    private $targetFiles = [];
    private $targetFileRelativePath;
    private $targetDirectory;
    private $io;
    private $recipe;

    public function testConfigureDirectory()
    {
        if (!is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0777, true);
        }
        foreach ($this->sourceFiles as $sourceFile) {
            if (!file_exists($sourceFile)) {
                file_put_contents($sourceFile, '');
            }
        }

        foreach ($this->targetFiles as $targetFile) {
            $this->assertFileNotExists($targetFile);
        }
        $this->createConfigurator()->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath]);
        foreach ($this->targetFiles as $targetFile) {
            $this->assertFileExists($targetFile);
        }
    }

    protected function setUp()
    {
        parent::setUp();

        $this->sourceDirectory = sys_get_temp_dir().'/package/files';
        $this->sourceFileRelativePath = 'package/files/';
        $this->sourceFiles = [
            $this->sourceDirectory.'/file1',
            $this->sourceDirectory.'/file2',
        ];

        $this->targetDirectory = sys_get_temp_dir().'/public/files';
        $this->targetFileRelativePath = 'public/files/';
        $this->targetFiles = [
            $this->targetDirectory.'/file1',
            $this->targetDirectory.'/file2',
        ];

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();

        $package = $this->getMockBuilder(PackageInterface::class)->getMock();
        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipe->expects($this->exactly(1))->method('getPackage')->willReturn($package);

        $installationManager = $this->getMockBuilder(InstallationManager::class)->getMock();
        $installationManager->expects($this->exactly(1))
            ->method('getInstallPath')
            ->with($package)
            ->willReturn(sys_get_temp_dir())
        ;
        $this->composer = $this->getMockBuilder(Composer::class)->getMock();
        $this->composer->expects($this->exactly(1))
            ->method('getInstallationManager')
            ->willReturn($installationManager)
        ;

        $this->cleanUpTargetFiles();
    }

    protected function tearDown()
    {
        parent::tearDown();

        foreach ($this->sourceFiles as $sourceFile) {
            @unlink($sourceFile);
        }
        $this->cleanUpTargetFiles();
    }

    private function createConfigurator(): CopyFromPackageConfigurator
    {
        return new CopyFromPackageConfigurator($this->composer, $this->io, new Options());
    }

    private function cleanUpTargetFiles()
    {
        $this->rrmdir(sys_get_temp_dir().'/package');
        $this->rrmdir(sys_get_temp_dir().'/public');
    }

    /**
     * Courtesy of http://php.net/manual/en/function.rmdir.php#98622
     */
    private function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") {
                        $this->rrmdir($dir."/".$object);
                    } else {
                        unlink($dir."/".$object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }
}
