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

use Composer\Composer;
use Composer\Console\Application;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Flex\Command\UpdateRecipesCommand;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
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

    public function testCommandUpdatesRecipe()
    {
        $this->prepareRecipeUpdateFixture();

        $command = $this->createCommandUpdateRecipes();
        $command->execute(['package' => 'symfony/console']);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('Recipe updated', $this->io->getOutput());
        // assert bin/console has changed
        $this->assertStringNotContainsString('vendor/autoload.php', file_get_contents(FLEX_TEST_DIR.'/bin/console'));
        // assert the recipe was updated
        $this->assertStringNotContainsString('c6d02bdfba9da13c22157520e32a602dbee8a75c', file_get_contents(FLEX_TEST_DIR.'/symfony.lock'));
    }

    public function testCommandFailsWhenFilesAreNotTracked()
    {
        $this->prepareRecipeUpdateFixture();
        (new Process(['git', 'rm', '--cached', 'bin/console'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'commit', '-m', 'untrack bin/console'], FLEX_TEST_DIR))->mustRun();

        $command = $this->createCommandUpdateRecipes();
        $command->execute(['package' => 'symfony/console']);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('the following files are not tracked by git: "bin/console"', $this->io->getOutput());
        $this->assertStringNotContainsString('recipes:install', $this->io->getOutput());
        $this->assertStringContainsString('vendor/autoload.php', file_get_contents(FLEX_TEST_DIR.'/bin/console'));
    }

    public function testCommandUpdatesRecipeWithNoChangelog()
    {
        $this->prepareRecipeUpdateFixture();

        $command = $this->createCommandUpdateRecipes();
        $command->execute([
            'package' => 'symfony/console',
            '--no-changelog' => true,
        ]);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('Recipe updated', $this->io->getOutput());
        $this->assertStringNotContainsString('Calculating CHANGELOG', $this->io->getOutput());
        $this->assertStringNotContainsString('No CHANGELOG could be calculated.', $this->io->getOutput());
        // assert bin/console has changed
        $this->assertStringNotContainsString('vendor/autoload.php', file_get_contents(FLEX_TEST_DIR.'/bin/console'));
        // assert the recipe was updated
        $this->assertStringNotContainsString('c6d02bdfba9da13c22157520e32a602dbee8a75c', file_get_contents(FLEX_TEST_DIR.'/symfony.lock'));
    }

    public function testCommandDoesNotFailWhenTheChangelogCannotBeCalculated()
    {
        @mkdir(FLEX_TEST_DIR.'/config', 0777, true);
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], FLEX_TEST_DIR))->mustRun();
        file_put_contents(FLEX_TEST_DIR.'/config/acme.yaml', "ref: original\n");
        (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'commit', '-m', 'Committing original files'], FLEX_TEST_DIR))->mustRun();

        $package = new Package('acme/foo', '1.0.0.0', '1.0.0');
        $lock = new Lock(FLEX_TEST_DIR.'/symfony.lock');
        $lock->set('acme/foo', ['version' => '1.0', 'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => 'original']]);

        $flex = $this->createStub(Flex::class);
        $flex->method('getLock')->willReturn($lock);
        $flex->method('getComposerJsonHash')->willReturn('');

        $downloader = $this->createStub(Downloader::class);
        $downloader->method('getRecipes')->willReturnCallback(static function (array $operations) {
            $ref = $operations[0]->getRecipeRef() ?? 'new';

            return [
                'manifests' => ['acme/foo' => [
                    'manifest' => ['copy-from-recipe' => ['config/' => '%CONFIG_DIR%/']],
                    'files' => ['config/acme.yaml' => ['contents' => "ref: $ref\n", 'executable' => false]],
                ]],
                'locks' => ['acme/foo' => ['version' => '1.0', 'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => $ref]]],
            ];
        });

        $httpDownloader = $this->createStub(HttpDownloader::class);
        $httpDownloader->method('get')->willThrowException(new TransportException('Could not authenticate against github.com', 401));

        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository([$package]));
        $composer = $this->createStub(Composer::class);
        $composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $composer->method('getEventDispatcher')->willReturn($this->createStub(EventDispatcher::class));

        $io = new BufferIO();
        $command = new UpdateRecipesCommand(
            $flex,
            $downloader,
            $httpDownloader,
            new Configurator($composer, $io, new Options(['root-dir' => FLEX_TEST_DIR, 'config-dir' => 'config'])),
            FLEX_TEST_DIR
        );
        $command->setIO($io);
        $command->setComposer($composer);

        $application = new Application();
        $application->{'add'.(method_exists($application, 'addCommand') ? 'Command' : '')}($command);
        $tester = new CommandTester($application->find('recipes:update'));
        $tester->execute(['package' => 'acme/foo']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Recipe updated', $io->getOutput());
        $this->assertStringContainsString('The CHANGELOG could not be calculated: Could not authenticate against github.com', $io->getOutput());
        $this->assertStringContainsString('--no-changelog', $io->getOutput());
        $this->assertSame("ref: new\n", file_get_contents(FLEX_TEST_DIR.'/config/acme.yaml'));
    }

    private function prepareRecipeUpdateFixture(): void
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
        $options = new Options(['root-dir' => FLEX_TEST_DIR, 'bin-dir' => 'bin/']);
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
        $application->{'add'.(method_exists($application, 'addCommand') ? 'Command' : '')}($command);
        $command = $application->find('recipes:update');

        return new CommandTester($command);
    }
}
