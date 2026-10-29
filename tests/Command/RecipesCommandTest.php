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
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BufferIO;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Flex\Command\RecipesCommand;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class RecipesCommandTest extends TestCase
{
    public function testPackageInformationShowsThePostInstallOutput()
    {
        $output = $this->executeCommand('abc123', 'abc123');

        $this->assertStringContainsString("instructions     : \n\n  * Instructions of recipe abc123 in etc/packages\n", $output);
    }

    public function testPackageInformationShowsThePostInstallOutputOfTheInstalledRecipe()
    {
        $output = $this->executeCommand('abc123', 'def456');

        $this->assertStringContainsString('status           : update available', $output);
        $this->assertStringContainsString('Instructions of recipe abc123', $output);
        $this->assertStringNotContainsString('Instructions of recipe def456', $output);
    }

    public function testPackageInformationDoesNotShowThePostInstallOutputOfARecipeThatIsNotInstalled()
    {
        $output = $this->executeCommand(null, 'def456');

        $this->assertStringContainsString('status           : recipe not installed', $output);
        $this->assertStringNotContainsString('instructions', $output);
    }

    public function testPackageInformationOfARecipeThatIsNotInstalled()
    {
        $output = $this->executeCommand(null, 'def456');

        $this->assertStringNotContainsString('installed recipe', $output);
        $this->assertStringNotContainsString('recipe history', $output);
        $this->assertStringContainsString("Install this recipe by running:\ncomposer recipes:install acme/demo-bundle --force -v\n", $output);
    }

    public function testPackageInformationOfARecipeInstalledWithoutItsRef()
    {
        $output = $this->executeCommand(null, 'def456', ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0']);

        $this->assertStringContainsString('status           : update available', $output);
        $this->assertStringContainsString("Update this recipe by re-installing the latest version with:\ncomposer recipes:install acme/demo-bundle --force -v\n", $output);
    }

    public function testListOfRecipesInstalledWithoutTheirRef()
    {
        $installed = new Package('acme/installed-bundle', '1.0.0.0', '1.0.0');
        $notInstalled = new Package('acme/not-installed-bundle', '1.0.0.0', '1.0.0');

        $flex = $this->createStub(Flex::class);
        $flex->method('fetchRecipes')->willReturnCallback(static function (array $operations) {
            $recipes = [];
            foreach ($operations as $operation) {
                $package = $operation->getPackage();
                $recipes[$package->getName()] = new Recipe($package, $package->getName(), 'information', [
                    'origin' => $package->getName().':1.0@github.com/acme/recipes:main',
                ], [
                    'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => 'def456'],
                ]);
            }

            return $recipes;
        });

        $lock = $this->createStub(Lock::class);
        $lock->method('all')->willReturn([]);
        $lock->method('get')->willReturnMap([
            ['acme/installed-bundle', ['version' => '1.0.0', 'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0']]],
            ['acme/not-installed-bundle', ['version' => '1.0.0']],
        ]);

        $locker = $this->createStub(Locker::class);
        $locker->method('getLockData')->willReturn(['packages' => [['name' => 'acme/installed-bundle'], ['name' => 'acme/not-installed-bundle']], 'packages-dev' => []]);
        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository([$installed, $notInstalled]));
        $composer = $this->createStub(Composer::class);
        $composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $composer->method('getLocker')->willReturn($locker);
        $composer->method('getEventDispatcher')->willReturn($this->createStub(EventDispatcher::class));

        $io = new BufferIO();
        $command = new RecipesCommand($flex, $lock, $this->createStub(HttpDownloader::class));
        $command->setIO($io);
        $command->setComposer($composer);

        $application = new Application();
        $application->{'add'.(method_exists($application, 'addCommand') ? 'Command' : '')}($command);

        $tester = new CommandTester($application->find('recipes'));
        $tester->execute([]);

        $this->assertStringContainsString(" * acme/installed-bundle (update available)\n * acme/not-installed-bundle (recipe not installed)\n", $io->getOutput());
    }

    private function executeCommand(?string $lockRef, string $latestRef, ?array $lockRecipe = null): string
    {
        $lockRecipe ??= null === $lockRef ? null : ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => $lockRef];
        $package = new Package('acme/demo-bundle', '1.0.0.0', '1.0.0');

        $flex = $this->createStub(Flex::class);
        $flex->method('fetchRecipes')->willReturnCallback(static function (array $operations) use ($package, $latestRef) {
            $ref = $operations[0]->getRecipeRef() ?? $latestRef;

            return ['acme/demo-bundle' => new Recipe($package, 'acme/demo-bundle', 'information', [
                'origin' => 'acme/demo-bundle:1.0@github.com/acme/recipes:main',
                'manifest' => ['post-install-output' => [\sprintf('  * Instructions of recipe <comment>%s</> in %%CONFIG_DIR%%/packages', $ref)]],
            ], [
                'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => $ref],
            ])];
        });

        $lock = $this->createStub(Lock::class);
        $lock->method('get')->willReturn(['version' => '1.0.0'] + (null === $lockRecipe ? [] : ['recipe' => $lockRecipe]));

        $downloader = $this->createStub(HttpDownloader::class);
        $downloader->method('get')->willReturnCallback(static function (string $url) {
            return new Response(['url' => $url], 200, [], '[]');
        });

        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository([$package]));
        $composer = $this->createStub(Composer::class);
        $composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $composer->method('getEventDispatcher')->willReturn($this->createStub(EventDispatcher::class));

        $io = new BufferIO();
        $command = new RecipesCommand($flex, $lock, $downloader, new Options(['config-dir' => 'etc']));
        $command->setIO($io);
        $command->setComposer($composer);

        $application = new Application();
        $application->{'add'.(method_exists($application, 'addCommand') ? 'Command' : '')}($command);

        $tester = new CommandTester($application->find('recipes'));
        $tester->execute(['package' => 'acme/demo-bundle']);

        $this->assertSame(0, $tester->getStatusCode());

        return $io->getOutput();
    }
}
