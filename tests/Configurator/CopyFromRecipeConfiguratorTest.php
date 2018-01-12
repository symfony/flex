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
use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\CopyFromRecipeConfigurator;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

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
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '');
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Setting configuration and copying files']);
        $this->createConfigurator()->configure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath]);
    }

    public function testConfigure()
    {
        $this->io->expects($this->at(0))->method('writeError')->with(['    Setting configuration and copying files']);
        $this->io->expects($this->at(1))->method('writeError')->with(['    Created <fg=green>"./config/file"</>']);

        $this->assertFileNotExists($this->targetFile);
        $this->createConfigurator()->configure(
            $this->recipe,
            [$this->sourceFileRelativePath => $this->targetFileRelativePath]
        );
        $this->assertFileExists($this->targetFile);
    }

    public function testUnconfigure()
    {
        $this->io->expects($this->at(0))->method('writeError')->with(['    Removing configuration and files']);
        $this->io->expects($this->at(1))->method('writeError')->with(['    Removed <fg=green>"./config/file"</>']);

        if (!file_exists($this->targetDirectory)) {
            mkdir($this->targetDirectory);
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $this->createConfigurator()->unconfigure($this->recipe, [$this->targetFileRelativePath]);
        $this->assertFileNotExists($this->targetFile);
    }

    public function testNoFilesRemoved()
    {
        $this->assertFileNotExists($this->targetFile);
        $this->io->expects($this->exactly(1))->method('writeError')->with(['    Removing configuration and files']);
        $this->createConfigurator()->unconfigure($this->recipe, [$this->sourceFileRelativePath => $this->targetFileRelativePath]);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->sourceDirectory = sys_get_temp_dir().'/source';
        $this->sourceFileRelativePath = 'source/file';
        $this->sourceFile = $this->targetDirectory.'/file';

        $this->targetDirectory = sys_get_temp_dir().'/config';
        $this->targetFileRelativePath = 'config/file';
        $this->targetFile = $this->targetDirectory. '/file';

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();
        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipe->expects($this->any())->method('getFiles')->willReturn([
            $this->sourceFileRelativePath => [
                'contents' => 'somecontent',
                'executable' => false
            ]
        ]);

        $this->cleanUpTargetFiles();
    }

    protected function tearDown()
    {
        parent::tearDown();

        $this->cleanUpTargetFiles();
    }

    private function createConfigurator(): CopyFromRecipeConfigurator
    {
        return new CopyFromRecipeConfigurator($this->getMockBuilder(Composer::class)->getMock(), $this->io, new Options());
    }

    private function cleanUpTargetFiles()
    {
        @unlink($this->targetFile);
        @rmdir($this->targetDirectory);
    }
}
