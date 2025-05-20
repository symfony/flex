<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Flex\Command\InstallRecipesCommand;
use Symfony\Flex\Event\UpdateEvent;
use Symfony\Flex\Flex;

class InstallRecipesCommandTest extends TestCase
{
    public function testCommandFlagsPassedDown()
    {
        $flex = $this->createMock(Flex::class);
        $flex->method('update')->willReturnCallback(function (UpdateEvent $event) {
            $this->assertTrue($event->reset());
            $this->assertTrue($event->assumeYesForPrompts());
        });

        $command = new InstallRecipesCommand($flex, __DIR__);
        $application = new Application();
        $application->add($command);
        $command = $application->find('symfony:recipes:install');

        $tester = new CommandTester($command);
        $tester->execute([
            '--reset' => true,
            '--yes' => true,
        ]);
    }
}
