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
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\CopyFromPackageConfigurator;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class CopyFromPackageConfiguratorTest extends TestCase
{
    private $sourceFile;
    private $targetFile;
    private $targetFileRelativePath;
    private $io;
    private $recipe;

    public function testConfigure()
    {
        $this->io->expects($this->exactly(2))->method('writeError')->with(
            $this->logicalOr(
                ['    Setting configuration and copying files'],
                ['    Created file <fg=green>"./public/file"</>']
            )
        );

        $this->assertFileNotExists($this->targetFile);
        $this->createConfigurator()->configure(
            $this->recipe,
            [$this->sourceFile => $this->targetFileRelativePath]
        );
        $this->assertFileExists($this->targetFile);
    }

    public function testUnconfigure()
    {
        $this->io->expects($this->exactly(2))->method('writeError')->with(
            $this->logicalOr(
                ['    Removing configuration and files'],
                ['    Removed file <fg=green>"./public/file"</>']
            )
        );

        if (!file_exists(sys_get_temp_dir().'/public')) {
            mkdir(sys_get_temp_dir().'/public');
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $this->createConfigurator()->unconfigure(
            $this->recipe,
            [$this->sourceFile => $this->targetFileRelativePath]
        );
        $this->assertFileNotExists($this->targetFile);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->sourceFile = 'package/file';
        $this->targetFileRelativePath = 'public/file';
        $this->targetFile = sys_get_temp_dir(). '/'.$this->targetFileRelativePath;

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

        $tmpSourceDirectory = sys_get_temp_dir().'/package';
        if (!is_dir($tmpSourceDirectory)) {
            mkdir($tmpSourceDirectory);
        }
        $tmpSourceFile = sys_get_temp_dir().'/'.$this->sourceFile;
        if (!file_exists($tmpSourceFile)) {
            file_put_contents($tmpSourceFile, '');
        }
        @unlink($this->targetFile);
    }

    protected function tearDown()
    {
        parent::tearDown();

        @unlink($this->targetFile);
        @unlink(sys_get_temp_dir().'/'.$this->sourceFile);
        @rmdir(sys_get_temp_dir().'/package');
    }

    private function createConfigurator(): CopyFromPackageConfigurator
    {
        return new CopyFromPackageConfigurator($this->composer, $this->io, new Options());
    }
}
