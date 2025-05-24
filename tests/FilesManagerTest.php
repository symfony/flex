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
use Symfony\Flex\FilesManager;
use Symfony\Flex\Lock;

class FilesManagerTest extends TestCase
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

        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $io->method('askConfirmation')->willReturn(true);

        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $lock->method('all')->willReturn([
            'symfony/my-package' => [
                'files' => [
                    $filePath,
                ],
            ],
        ]);

        $filesManager = new FilesManager($io, $lock, FLEX_TEST_DIR);

        // We need to set the writtenFiles property to reset the state
        $reflection = new \ReflectionProperty(FilesManager::class, 'writtenFiles');
        $reflection->setAccessible(true);

        $this->assertTrue($filesManager->shouldWriteFile('non-existing-file.txt', false, false));
        $this->assertFalse($filesManager->shouldWriteFile($filePath, false, false));

        // It allowed to write the file
        $reflection->setValue($filesManager, []);
        $this->assertTrue($filesManager->shouldWriteFile($filePath, true, false));

        // We skip all questions, so we're able to write
        $reflection->setValue($filesManager, []);
        $this->assertTrue($filesManager->shouldWriteFile($filePath, true, true));
    }
}
