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
    private $targetFile;
    private $targetFileRelativePath;
    private $io;
    private $recipe;

    public function testConfigure()
    {
        $this->io->expects($this->exactly(2))->method('writeError')->with(
            $this->logicalOr(
                ['    Setting configuration and copying files'],
                ['    Created file <fg=green>"./config/file"</>']
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
                ['    Removed file <fg=green>"./config/file"</>']
            )
        );

        if (!file_exists(sys_get_temp_dir().'/config')) {
            mkdir(sys_get_temp_dir().'/config');
        }
        file_put_contents($this->targetFile, '');
        $this->assertFileExists($this->targetFile);
        $this->createConfigurator()->unconfigure($this->recipe, [$this->targetFileRelativePath]);
        $this->assertFileNotExists($this->targetFile);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->sourceFile = 'file';
        $this->targetFileRelativePath = 'config/file';
        $this->targetFile = sys_get_temp_dir(). '/'.$this->targetFileRelativePath;

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();
        $this->recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $this->recipe->expects($this->any())->method('getFiles')->willReturn([
            $this->sourceFile => [
                'contents' => 'somecontent',
                'executable' => false
            ]
        ]);

        @unlink($this->targetFile);
    }

    protected function tearDown()
    {
        parent::tearDown();

        @unlink($this->targetFile);
    }

    private function createConfigurator(): CopyFromRecipeConfigurator
    {
        return new CopyFromRecipeConfigurator(
            $this->getMockBuilder(Composer::class)->getMock(),
            $this->io,
            new Options()
        );
    }
}
