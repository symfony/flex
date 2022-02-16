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
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Plugin\PluginInterface;
use Composer\Util\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Flex\Command\UpdateRecipesCommand;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Options;
use Symfony\Flex\ParallelDownloader;

class UpdateRecipesCommandTest extends TestCase
{
    private $io;

    protected function setUp(): void
    {
        if (method_exists(Platform::class, 'putEnv')) {
            Platform::putEnv('COMPOSER', FLEX_TEST_DIR.'/composer.json');
        } else {
            putenv('COMPOSER='.FLEX_TEST_DIR.'/composer.json');
        }
    }

    protected function tearDown(): void
    {
        if (method_exists(Platform::class, 'clearEnv')) {
            Platform::clearEnv('COMPOSER');
        } else {
            putenv('COMPOSER');
        }

        $filesystem = new Filesystem();
        $filesystem->remove(FLEX_TEST_DIR);
    }

    /**
     * Skip 7.1, simply because there isn't a newer recipe version available
     * that we can easily use to assert.
     *
     * @requires PHP >= 7.2
     */
    public function testCommandUpdatesRecipe()
    {
        @mkdir(FLEX_TEST_DIR);
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], FLEX_TEST_DIR))->mustRun();

        @mkdir(FLEX_TEST_DIR.'/bin');

        // copy in outdated bin/console and symfony.lock set at the recipe it came from
        file_put_contents(FLEX_TEST_DIR.'/bin/console', file_get_contents(__DIR__.'/../Fixtures/update_recipes/console'));
        file_put_contents(FLEX_TEST_DIR.'/symfony.lock', file_get_contents(__DIR__.'/../Fixtures/update_recipes/symfony.lock'));
        file_put_contents(FLEX_TEST_DIR.'/composer.json', file_get_contents(__DIR__.'/../Fixtures/update_recipes/composer.json'));

        (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'commit', '-m', 'setup of original console files'], FLEX_TEST_DIR))->mustRun();

        (new Process([__DIR__.'/../../vendor/bin/composer', 'install'], FLEX_TEST_DIR))->mustRun();

        $command = $this->createCommandUpdateRecipes();
        $command->execute(['package' => 'symfony/console']);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('Recipe updated', $this->io->getOutput());
        // assert bin/console has changed
        $this->assertStringNotContainsString('vendor/autoload.php', file_get_contents(FLEX_TEST_DIR.'/bin/console'));
        // assert the recipe was updated
        $this->assertStringNotContainsString('c6d02bdfba9da13c22157520e32a602dbee8a75c', file_get_contents(FLEX_TEST_DIR.'/symfony.lock'));
    }

    private function createCommandUpdateRecipes(): CommandTester
    {
        $this->io = new BufferIO();
        $composer = (new Factory())->createComposer($this->io, null, false, FLEX_TEST_DIR);
        $flex = new Flex();
        $flex->activate($composer, $this->io);
        if (version_compare('2.0.0', PluginInterface::PLUGIN_API_VERSION, '<=')) {
            $rfs = Factory::createHttpDownloader($this->io, $composer->getConfig());
        } else {
            $rfs = Factory::createRemoteFilesystem($this->io, $composer->getConfig());
            $rfs = new ParallelDownloader($this->io, $composer->getConfig(), $rfs->getOptions(), $rfs->isTlsDisabled());
        }
        $options = new Options(['root-dir' => FLEX_TEST_DIR]);
        $command = new UpdateRecipesCommand(
            $flex,
            new Downloader($composer, $this->io, $rfs),
            $rfs,
            new Configurator($composer, $this->io, $options),
            FLEX_TEST_DIR
        );
        $command->setIO($this->io);
        $command->setComposer($composer);

        $application = new Application();
        $application->add($command);
        $command = $application->find('recipes:update');

        return new CommandTester($command);
    }
}
