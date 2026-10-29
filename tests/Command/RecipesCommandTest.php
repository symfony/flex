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

    private function executeCommand(?string $lockRef, string $latestRef): string
    {
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
        $lock->method('get')->willReturn(['version' => '1.0.0'] + (null === $lockRef ? [] : [
            'recipe' => ['repo' => 'github.com/acme/recipes', 'branch' => 'main', 'version' => '1.0', 'ref' => $lockRef],
        ]));

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
