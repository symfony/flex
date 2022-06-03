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
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\IO\BufferIO;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\MatchAllConstraint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Response;

class FlexTest extends TestCase
{
    public function testPostInstall()
    {
        $data = [
            'manifests' => [
                'dummy/dummy' => [
                    'manifest' => [
                        'post-install-output' => ['line 1 %CONFIG_DIR%', 'line 2 %VAR_DIR%'],
                        'bundles' => [
                            'Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle' => ['all'],
                        ],
                    ],
                    'origin' => 'dummy/dummy:1.0@github.com/symfony/recipes:main',
                ],
            ],
            'locks' => [
                'dummy/dummy' => [
                    'recipe' => [],
                    'version' => '',
                ],
            ],
        ];

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        $package = new Package('dummy/dummy', '1.0.0', '1.0.0');
        $recipe = new Recipe($package, 'dummy/dummy', 'install', $data['manifests']['dummy/dummy'], $data['locks']['dummy/dummy']);

        $rootPackage = $this->mockRootPackage(['symfony' => ['allow-contrib' => true]]);
        $flex = $this->mockFlex($io, $rootPackage, $recipe, $data);
        $flex->record($this->mockPackageEvent($package));
        $flex->install($this->mockFlexEvent());

        $expected = [
            '<bg=blue;fg=white>              </>',
            '<bg=blue;fg=white> What\'s next? </>',
            '<bg=blue;fg=white>              </>',
            '',
            '<info>Some files have been created and/or updated to configure your new packages.</>',
            'Please <comment>review</>, <comment>edit</> and <comment>commit</> them: these files are <comment>yours</>.',
            '',
            '<bg=yellow;fg=white> dummy/dummy </> instructions:',
            '',
            'line 1 config',
            'line 2 var',
            '',
        ];
        $postInstallOutput = \Closure::bind(function () {
            return $this->postInstallOutput;
        }, $flex, Flex::class)->__invoke();
        $this->assertSame($expected, $postInstallOutput);

        $this->assertSame(
            <<<EOF

Symfony operations: 1 recipe ()
  - Configuring dummy/dummy (>=1.0): From github.com/symfony/recipes:main

EOF
            ,
            str_replace("\r\n", "\n", $io->getOutput())
        );
    }

    public function testActivateLoadsClasses()
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        $package = $this->mockRootPackage(['symfony' => ['allow-contrib' => true]]);
        $package->method('getRequires')->willReturn([new Link('dummy', 'symfony/flex', class_exists(MatchAllConstraint::class) ? new MatchAllConstraint() : null)]);

        $composer = $this->mockComposer($this->mockLocker(), $package, Factory::createConfig($io));
        if (version_compare('2.0.0', PluginInterface::PLUGIN_API_VERSION, '>')) {
            $composer->setRepositoryManager($this->mockManager());
        }

        $flex = new Flex();
        $flex->activate($composer, $io);

        $this->assertTrue(class_exists(Response::class, false));
    }

    /**
     * @dataProvider getPackagesForAutoDiscovery
     */
    public function testBundlesAutoDiscovery(Package $package, array $expectedManifest)
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        $recipe = null;
        if (\count($expectedManifest)) {
            $recipe = new Recipe($package, $package->getName(), 'install', $expectedManifest);
        }

        $devPackages = $package->isDev() ? [['name' => $package->getName()]] : [];

        $rootPackage = $this->mockRootPackage($package->getExtra());
        $flex = $this->mockFlex($io, $rootPackage, $recipe, [], ['packages-dev' => $devPackages]);
        $flex->record($this->mockPackageEvent($package));
        $flex->install($this->mockFlexEvent());
    }

    public function getPackagesForAutoDiscovery(): array
    {
        $return = [];

        $versions = ['1.0', '2.0-dev'];
        $packages = self::getTestPackages();

        foreach ($packages as $name => $info) {
            foreach ($versions as $version) {
                $package = new Package($name, $version, $version);
                $package->setAutoload($info['autoload']);
                if (isset($info['type'])) {
                    $package->setType($info['type']);
                }

                if (!$info['bundles']) {
                    $return[] = [$package, []];

                    continue;
                }

                $expectedManifest = [
                    'origin' => sprintf('%s:%s@auto-generated recipe', $package->getName(),
                        $package->getPrettyVersion()),
                    'manifest' => ['bundles' => []],
                ];

                $envs = $package->isDev() ? ['dev', 'test'] : ['all'];
                foreach ($info['bundles'] as $bundle) {
                    $expectedManifest['manifest']['bundles'][$bundle] = $envs;
                }

                $return[] = [$package, $expectedManifest];
            }
        }

        return $return;
    }

    public function testFetchRecipesOrder()
    {
        $packages = [
            ['name' => 'symfony/console', 'type' => 'library'],
            ['name' => 'symfony/flex', 'type' => 'composer-plugin'],
            ['name' => 'symfony/framework-bundle', 'type' => 'library'],
            ['name' => 'symfony/webapp-meta', 'type' => 'metapackage'],
            ['name' => 'symfony/webapp-pack', 'type' => 'symfony-pack'],
        ];

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        $rootPackage = $this->mockRootPackage(['symfony' => ['allow-contrib' => true]]);

        $flex = $this->mockFlex($io, $rootPackage, null, [
            'manifests' => array_reduce($packages, static function (array $manifests, array $packageInfo) {
                $manifests[$packageInfo['name']] = ['manifest' => []];

                return $manifests;
            }, []),
        ]);

        $recipes = $flex->fetchRecipes(array_map(static function (array $packageInfo) {
            $package = new Package($packageInfo['name'], '1.0.0', '1.0.0');
            $package->setType($packageInfo['type']);

            return new InstallOperation($package);
        }, $packages), true);

        $this->assertSame([
            'symfony/flex',
            'symfony/webapp-pack',
            'symfony/webapp-meta',
            'symfony/framework-bundle',
            'symfony/console',
        ], array_keys($recipes));
    }

    public function testFetchRecipesWithConflicts()
    {
        $originalRecipes = [
            'locks' => [
                'doctrine/annotations' => [
                    'version' => '1.13',
                    'recipe' => [
                        'version' => '1.0',
                    ],
                ],
                'doctrine/doctrine-bundle' => [
                    'version' => '2.5',
                    'recipe' => [
                        'version' => '2.4',
                    ],
                ],
            ],
            'manifests' => [
                'doctrine/annotations' => [
                    'version' => '1.0',
                    'manifest' => [],
                ],
                'doctrine/doctrine-bundle' => [
                    'version' => '2.4',
                    'manifest' => [
                        'conflict' => [
                            'symfony/framework-bundle' => '<5.3',
                        ],
                    ],
                ],
            ],
        ];
        $oldRecipes = [
            'locks' => [
                'doctrine/doctrine-bundle' => [
                    // 2.5 is still being installed, but 2.3 recipe used
                    'version' => '2.5',
                    'recipe' => [
                        'version' => '2.3',
                    ],
                ],
            ],
            'manifests' => [
                'doctrine/doctrine-bundle' => [
                    'version' => '2.3',
                    'manifest' => [],
                ],
            ],
        ];

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        $rootPackage = $this->mockRootPackage(['symfony' => ['allow-contrib' => true]]);

        $downloader = $this->getMockBuilder(Downloader::class)->disableOriginalConstructor()->getMock();
        $downloader->expects($this->exactly(2))
            ->method('getRecipes')
            ->willReturnOnConsecutiveCalls($originalRecipes, $oldRecipes);
        $downloader->expects($this->any())->method('isEnabled')->willReturn(true);
        $downloader->expects($this->once())->method('removeRecipeFromIndex')->with('doctrine/doctrine-bundle', '2.4');

        $locker = $this->getMockBuilder(Locker::class)->disableOriginalConstructor()->getMock();
        $lockedRepository = $this->getMockBuilder(
            // LockArrayRepository does not exist on composer 1.0.2 fallback to RepositoryInterface
            class_exists(LockArrayRepository::class) ? LockArrayRepository::class : RepositoryInterface::class
        )->disableOriginalConstructor()->getMock();
        // make the conflicted package show up
        $locker->expects($this->any())
            ->method('getLockedRepository')
            ->willReturn($lockedRepository);
        $lockedRepository->expects($this->once())
            ->method('findPackage')
            ->with('symfony/framework-bundle', '<5.3')
            ->willReturn(new Package('symfony/framework-bundle', '5.2.0', '5.2.0'));
        $composer = $this->mockComposer($locker, $rootPackage);
        $configurator = $this->mockConfigurator();
        $lock = $this->mockLock();
        $flex = $this->mockFlexCustom($io, $composer, $configurator, $downloader, $lock);

        $operations = [];
        foreach ($originalRecipes['manifests'] as $name => $recipeData) {
            $package = new Package($name, $recipeData['version'], $recipeData['version']);

            $operations[] = new InstallOperation($package);
        }
        $recipes = $flex->fetchRecipes($operations, true);

        $this->assertSame([
            'doctrine/annotations',
            'doctrine/doctrine-bundle',
        ], array_keys($recipes));
        $this->assertSame('2.3', $recipes['doctrine/doctrine-bundle']->getVersion());
    }

    public static function getTestPackages(): array
    {
        return [
            'symfony/debug-bundle' => [
                'autoload' => ['psr-4' => ['Symfony\\Bundle\\DebugBundle\\' => '']],
                'bundles' => ['Symfony\\Bundle\\DebugBundle\\DebugBundle'],
            ],
            'symfony/dummy' => [
                'autoload' => ['psr-4' => ['Symfony\\Bundle\\FirstDummyBundle\\' => 'FirstDummyBundle/', 'Symfony\\Bundle\\SecondDummyBundle\\' => 'SecondDummyBundle/']],
                'bundles' => ['Symfony\\Bundle\\FirstDummyBundle\\FirstDummyBundle', 'Symfony\\Bundle\\SecondDummyBundle\\SecondDummyBundle'],
            ],
            'doctrine/doctrine-cache-bundle' => [
                'autoload' => ['psr-4' => ['Doctrine\\Bundle\\DoctrineCacheBundle\\' => '']],
                'bundles' => ['Doctrine\\Bundle\\DoctrineCacheBundle\\DoctrineCacheBundle'],
            ],
            'eightpoints/guzzle-bundle' => [
                'autoload' => ['psr-0' => ['EightPoints\\Bundle\\GuzzleBundle' => '']],
                'bundles' => ['EightPoints\\Bundle\\GuzzleBundle\\GuzzleBundle'],
            ],
            'easycorp/easy-security-bundle' => [
                'autoload' => ['psr-4' => ['EasyCorp\\Bundle\\EasySecurityBundle\\' => '']],
                'bundles' => ['EasyCorp\\Bundle\\EasySecurityBundle\\EasySecurityBundle'],
            ],
            'symfony-cmf/routing-bundle' => [
                'autoload' => ['psr-4' => ['Symfony\\Cmf\\Bundle\\RoutingBundle\\' => '']],
                'bundles' => ['Symfony\\Cmf\\Bundle\\RoutingBundle\\CmfRoutingBundle'],
            ],
            'easycorp/easy-deploy-bundle' => [
                'autoload' => ['psr-4' => ['EasyCorp\\Bundle\\EasyDeployBundle\\' => ['src', 'tests']]],
                'bundles' => ['EasyCorp\\Bundle\\EasyDeployBundle\\EasyDeployBundle'],
            ],
            'web-token/jwt-bundle' => [
                'autoload' => ['psr-4' => ['Jose\\Bundle\\JoseFramework\\' => ['']]],
                'bundles' => ['Jose\\Bundle\\JoseFramework\\JoseFrameworkBundle'],
            ],
            'sylius/shop-api-plugin' => [
                'autoload' => ['psr-4' => ['Sylius\\ShopApiPlugin\\' => 'src/']],
                'bundles' => ['Sylius\\ShopApiPlugin\\ShopApiPlugin'],
                'type' => 'sylius-plugin',
            ],
            'dunglas/sylius-acme-plugin' => [
                'autoload' => ['psr-4' => ['Dunglas\\SyliusAcmePlugin\\' => 'src/']],
                'bundles' => ['Dunglas\\SyliusAcmePlugin\\DunglasSyliusAcmePlugin'],
                'type' => 'sylius-plugin',
            ],
            'composer/ca-bundle' => [
                'autoload' => ['psr-4' => ['Composer\\CaBundle\\' => ['src/']]],
                'bundles' => [],
            ],
            'symfony/nouse-bundle' => [
                'autoload' => ['psr-4' => ['Symfony\\Bundle\\NouseBundle\\' => '']],
                'bundles' => ['Symfony\\Bundle\\NouseBundle\\NouseBundle'],
            ],
        ];
    }

    private function mockPackageEvent(Package $package): PackageEvent
    {
        $event = $this->getMockBuilder(PackageEvent::class, ['getOperation'])->disableOriginalConstructor()->getMock();
        $event->expects($this->any())->method('getOperation')->willReturn(new InstallOperation($package));
        $event->expects($this->any())->method('isDevMode')->willReturn(true);

        return $event;
    }

    private function mockConfigurator(Recipe $recipe = null): Configurator
    {
        $configurator = $this->getMockBuilder(Configurator::class)->disableOriginalConstructor()->getMock();

        if ($recipe) {
            $configurator->expects($this->once())->method('install')->with($this->equalTo($recipe));
        }

        return $configurator;
    }

    private function mockDownloader(array $recipes = []): Downloader
    {
        $downloader = $this->getMockBuilder(Downloader::class)->disableOriginalConstructor()->getMock();

        $downloader->expects($this->once())->method('getRecipes')->willReturn($recipes);
        $downloader->expects($this->any())->method('isEnabled')->willReturn(true);

        return $downloader;
    }

    private function mockLocker(array $lockData = []): Locker
    {
        $locker = $this->getMockBuilder(Locker::class)->disableOriginalConstructor()->getMock();

        $lockData = array_merge(['content-hash' => 'random', 'packages-dev' => []], $lockData);
        $locker->expects($this->any())->method('getLockData')->willReturn($lockData);

        return $locker;
    }

    private function mockComposer(Locker $locker, RootPackageInterface $package, Config $config = null): Composer
    {
        if (null === $config) {
            $config = $this->getMockBuilder(Config::class)->getMock();
            $config->expects($this->any())->method('get')->willReturn(__DIR__.'/Fixtures/vendor');
        }

        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setLocker($locker);
        $composer->setPackage($package);

        return $composer;
    }

    private function mockRootPackage(array $extraData = []): RootPackageInterface
    {
        $package = $this->getMockBuilder(RootPackageInterface::class)->disableOriginalConstructor()->getMock();

        $package->expects($this->any())->method('getExtra')->willReturn($extraData);

        return $package;
    }

    private function mockLock(): Lock
    {
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $lock->expects($this->any())->method('has')->willReturn(false);

        return $lock;
    }

    private function mockFlexEvent(): Event
    {
        $event = $this->getMockBuilder(Event::class)->disableOriginalConstructor()->getMock();
        $event->expects($this->any())->method('getName')->willReturn(ScriptEvents::POST_UPDATE_CMD);

        return $event;
    }

    private function mockManager(): RepositoryManager
    {
        $manager = $this->getMockBuilder(RepositoryManager::class)->disableOriginalConstructor()->getMock();

        $localRepo = $this->getMockBuilder(WritableRepositoryInterface::class)->disableOriginalConstructor()->getMock();
        $manager->expects($this->once())->method('getLocalRepository')->willReturn($localRepo);

        return $manager;
    }

    private function mockFlexCustom(BufferIO $io, Composer $composer, Configurator $configurator, Downloader $downloader, Lock $lock): Flex
    {
        return \Closure::bind(function () use ($composer, $io, $configurator, $downloader, $lock) {
            $flex = new Flex();
            $flex->composer = $composer;
            $flex->config = $composer->getConfig();
            $flex->io = $io;
            $flex->configurator = $configurator;
            $flex->downloader = $downloader;
            $flex->runningCommand = function () {
            };
            $flex->options = new Options(['config-dir' => 'config', 'var-dir' => 'var', 'root-dir' => '.']);
            $flex->lock = $lock;

            return $flex;
        }, null, Flex::class)->__invoke();
    }

    private function mockFlex(BufferIO $io, RootPackageInterface $package, Recipe $recipe = null, array $recipes = [], array $lockerData = []): Flex
    {
        $composer = $this->mockComposer($this->mockLocker($lockerData), $package);

        $configurator = $this->mockConfigurator($recipe);
        $downloader = $this->mockDownloader($recipes);
        $lock = $this->mockLock();

        return $this->mockFlexCustom($io, $composer, $configurator, $downloader, $lock);
    }
}
