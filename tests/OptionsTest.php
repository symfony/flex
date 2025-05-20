<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests;

use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Flex\Options;

class OptionsTest extends TestCase
{
    public function testShouldWrite()
    {
        @mkdir(FLEX_TEST_DIR);
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], FLEX_TEST_DIR))->mustRun();

        $filePath = FLEX_TEST_DIR.'/a.txt';
        file_put_contents($filePath, 'a');
        (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'commit', '-m', 'setup of original files'], FLEX_TEST_DIR))->mustRun();

        file_put_contents($filePath, 'b');

        $this->assertTrue((new Options([], null))->shouldWriteFile('non-existing-file.txt', false, false));
        $this->assertFalse((new Options([], null))->shouldWriteFile($filePath, false, false));

        // We don't have an IO, so we don't write the file
        $this->assertFalse((new Options([], null))->shouldWriteFile($filePath, true, false));

        // We have an IO, and it allowed to write the file
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('askConfirmation')->willReturn(true);
        $this->assertTrue((new Options([], $io))->shouldWriteFile($filePath, true, false));

        // We skip all questions, so we're able to write
        $this->assertTrue((new Options([], null))->shouldWriteFile($filePath, true, true));
    }
}
