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

use Composer\Composer;
use Composer\IO\NullIO;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Flex\Options;
use Symfony\Flex\ScriptExecutor;

final class ScriptExecutorTest extends TestCase
{
    /**
     * @backupGlobals enabled
     */
    public function testMemoryLimit(): void
    {
        $command = './command.php';
        $memoryLimit = '32M';
        putenv("COMPOSER_MEMORY_LIMIT={$memoryLimit}");
        $executorMock = $this->createMock(ProcessExecutor::class);
        $scriptExecutor = new ScriptExecutor(new Composer(), new NullIO(), new Options(), $executorMock);

        $phpFinder = new PhpExecutableFinder();
        if (!$php = $phpFinder->find(false)) {
            throw new \RuntimeException('The PHP executable could not be found, add it to your PATH and try again.');
        }

        $arguments = $phpFinder->findArguments();
        $ini = php_ini_loaded_file();

        if (false !== $ini) {
            $arguments[] = "--php-ini={$ini}";
        }

        $arguments[] = "-d memory_limit={$memoryLimit}";

        $phpArgs = implode(' ', array_map([ProcessExecutor::class, 'escape'], $arguments));

        $expectedCommand = ProcessExecutor::escape($php).($phpArgs ? ' '.$phpArgs : '').' '.$command;

        $executorMock
            ->method('execute')
            ->with($expectedCommand)
            ->willReturn(0)
        ;
        $this->expectNotToPerformAssertions();

        $scriptExecutor->execute('php-script', $command);
    }
}
