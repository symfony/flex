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

use Composer\Config;
use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Flex\Command\DumpEnvCommand;
use Symfony\Flex\Options;

class DumpEnvCommandTest extends TestCase
{
    public function testFileIsCreated()
    {
        @mkdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local.php';
        @unlink($env);
        @unlink($envLocal);

        $envContent = <<<EOF
APP_ENV=dev
APP_SECRET=abcdefgh123456789
EOF;
        file_put_contents($env, $envContent);

        $command = $this->createCommandDumpEnv();
        $command->execute([
            'env' => 'prod',
        ]);

        $this->assertFileExists($envLocal);

        $vars = require $envLocal;
        $this->assertSame([
            'APP_ENV' => 'prod',
            'APP_SECRET' => 'abcdefgh123456789',
        ], $vars);

        unlink($env);
        unlink($envLocal);
    }

    public function testEmptyOptionMustIgnoreContent()
    {
        @mkdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local.php';
        @unlink($env);
        @unlink($envLocal);

        $envContent = <<<EOF
APP_ENV=dev
APP_SECRET=abcdefgh123456789
EOF;
        file_put_contents($env, $envContent);

        $command = $this->createCommandDumpEnv();
        $command->execute([
            'env' => 'prod',
            '--empty' => true,
        ]);

        $this->assertFileExists($envLocal);

        $vars = require $envLocal;
        $this->assertSame([
            'APP_ENV' => 'prod',
        ], $vars);

        unlink($env);
        unlink($envLocal);
    }

    /**
     * @backupGlobals enabled
     */
    public function testEnvCanBeReferenced()
    {
        @mkdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local.php';
        @unlink($env);
        @unlink($envLocal);

        $envContent = <<<'EOF'
BAR=$FOO
FOO=123
EOF;
        file_put_contents($env, $envContent);

        $_SERVER['FOO'] = 'Foo';
        $_SERVER['BAR'] = 'Bar';

        $command = $this->createCommandDumpEnv();
        $command->execute([
            'env' => 'prod',
        ]);

        $this->assertFileExists($envLocal);

        $vars = require $envLocal;
        $this->assertSame([
            'APP_ENV' => 'prod',
            'BAR' => 'Foo',
            'FOO' => '123',
        ], $vars);

        unlink($env);
        unlink($envLocal);
    }

    public function testRequiresToSpecifyEnvArgumentWhenLocalFileDoesNotSpecifyAppEnv()
    {
        @mkdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local';

        file_put_contents($env, 'APP_ENV=dev');
        file_put_contents($envLocal, '');

        $command = $this->createCommandDumpEnv();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Please provide the name of the environment either by passing it as command line argument or by defining the "APP_ENV" variable in the ".env.local" file.');

        try {
            $command->execute([]);
        } finally {
            unlink($env);
            unlink($envLocal);
        }
    }

    public function testDoesNotRequireToSpecifyEnvArgumentWhenLocalFileIsPresent()
    {
        @mkdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local';
        $envLocalPhp = FLEX_TEST_DIR.'/.env.local.php';
        @unlink($envLocalPhp);

        file_put_contents($env, 'APP_ENV=dev');
        file_put_contents($envLocal, 'APP_ENV=staging');

        $command = $this->createCommandDumpEnv();
        $command->execute([]);

        $this->assertFileExists($envLocalPhp);

        $this->assertSame(['APP_ENV' => 'staging'], require $envLocalPhp);

        unlink($env);
        unlink($envLocal);
        unlink($envLocalPhp);
    }

    /**
     * @runInSeparateProcess
     */
    public function testLoadLocalEnvWhenTestEnvIsNotEqual()
    {
        @mkdir(FLEX_TEST_DIR);
        chdir(FLEX_TEST_DIR);
        $env = FLEX_TEST_DIR.'/.env';
        $envLocal = FLEX_TEST_DIR.'/.env.local';
        $envLocalPhp = FLEX_TEST_DIR.'/.env.local.php';

        @unlink($envLocalPhp);

        file_put_contents($env, 'APP_ENV=dev');
        file_put_contents($envLocal, <<<EOF
APP_ENV=test
APP_SECRET=abcdefgh123456789
EOF
        );

        $command = $this->createCommandDumpEnv(['runtime' => ['test_envs' => []]]);
        $command->execute([
            'env' => 'test',
        ]);

        $this->assertFileExists($envLocalPhp);

        $vars = require $envLocalPhp;
        $this->assertSame([
            'APP_ENV' => 'test',
            'APP_SECRET' => 'abcdefgh123456789',
        ], $vars);

        unlink($env);
        unlink($envLocal);
        unlink($envLocalPhp);
    }

    private function createCommandDumpEnv(array $options = [])
    {
        $command = new DumpEnvCommand(
            new Config(false, __DIR__.'/../..'),
            new Options($options + ['root-dir' => FLEX_TEST_DIR])
        );

        $application = new Application();
        $application->add($command);
        $command = $application->find('dump-env');

        return new CommandTester($command);
    }
}
