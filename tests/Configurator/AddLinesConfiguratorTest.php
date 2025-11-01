<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Configurator\AddLinesConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class AddLinesConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove(FLEX_TEST_DIR);
    }

    public function testFileDoesNotExistSkipped()
    {
        $this->runConfigure([
            ['file' => 'non-existent.php', 'content' => ''],
        ]);
        $this->assertFileDoesNotExist(FLEX_TEST_DIR.'/non-existent.php');
    }

    public function testLinesAddedToTopOfFile()
    {
        $this->saveFile('assets/app.js', <<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS
        );

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<JS
            import './bootstrap';
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS,
            $actualContents
        );
    }

    public function testExpandTargetDirWhenConfiguring()
    {
        $this->saveFile('config/file.txt', 'FirstLine');

        $this->runConfigure([
            [
                'file' => '%CONFIG_DIR%/file.txt',
                'position' => 'top',
                'content' => 'NewFirstLine',
            ],
        ]);
        $actualContents = $this->readFile('config/file.txt');
        $this->assertSame(<<<EOF
            NewFirstLine
            FirstLine
            EOF,
            $actualContents
        );
    }

    public function testLinesAddedToBottomOfFile()
    {
        $this->saveFile('assets/app.js', <<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS
        );

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'bottom',
                'content' => "import './bootstrap';",
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            import './bootstrap';
            JS,
            $actualContents
        );
    }

    public function testLinesAddedAfterTarget()
    {
        $this->saveFile('webpack.config.js', <<<JS
            const Encore = require('@symfony/webpack-encore');

            Encore
                .setOutputPath('public/build/')
                .setPublicPath('/build')

                .addEntry('app', './assets/app.js')

                // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
                .splitEntryChunks()
            ;

            module.exports = Encore.getWebpackConfig();
            JS
        );

        $this->runConfigure([
            [
                'file' => 'webpack.config.js',
                'position' => 'after_target',
                'target' => '.addEntry(\'app\', \'./assets/app.js\')',
                'content' => <<<JS

                    // enables the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
                    .enableStimulusBridge('./assets/controllers.json')
                JS,
            ],
        ]);

        $actualContents = $this->readFile('webpack.config.js');
        $this->assertSame(<<<JS
            const Encore = require('@symfony/webpack-encore');

            Encore
                .setOutputPath('public/build/')
                .setPublicPath('/build')

                .addEntry('app', './assets/app.js')

                // enables the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
                .enableStimulusBridge('./assets/controllers.json')

                // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
                .splitEntryChunks()
            ;

            module.exports = Encore.getWebpackConfig();
            JS,
            $actualContents
        );
    }

    public function testSkippedIfTargetCannotBeFound()
    {
        $originalContent = <<<JS
            const Encore = require('@symfony/webpack-encore');

            Encore
                .setOutputPath('public/build/')
            ;

            module.exports = Encore.getWebpackConfig();
            JS;

        $this->saveFile('webpack.config.js', $originalContent);

        $this->runConfigure([
            [
                'file' => 'webpack.config.js',
                'position' => 'after_target',
                'target' => '.addEntry(\'app\', \'./assets/app.js\')',
                'content' => <<<JS

                    // some new line
                JS,
            ],
        ]);

        $this->assertSame($originalContent, $this->readFile('webpack.config.js'));
    }

    public function testPatchIgnoredIfValueAlreadyExists()
    {
        $originalContents = <<<JS
            import * as Turbo from '@hotwired/turbo';
            import './bootstrap';

            console.log(Turbo);
            JS;

        $this->saveFile('assets/app.js', $originalContents);

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame($originalContents, $actualContents);
    }

    public function testLinesAddedToMultipleFiles()
    {
        $this->saveFile('assets/app.js', <<<JS
            import * as Turbo from '@hotwired/turbo';
            JS
        );

        $this->saveFile('assets/bootstrap.js', <<<JS
            console.log('bootstrap.js');
            JS
        );

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
            ],
            [
                'file' => 'assets/bootstrap.js',
                'position' => 'bottom',
                'content' => "console.log('on the bottom');",
            ],
        ]);

        $this->assertSame(<<<JS
            import './bootstrap';
            import * as Turbo from '@hotwired/turbo';
            JS,
            $this->readFile('assets/app.js')
        );

        $this->assertSame(<<<JS
            console.log('bootstrap.js');
            console.log('on the bottom');
            JS,
            $this->readFile('assets/bootstrap.js')
        );
    }

    public function testLineSkippedIfRequiredPackageMissing()
    {
        $this->saveFile('assets/app.js', <<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS
        );

        $composer = $this->createComposerMockWithPackagesInstalled([]);
        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
                'requires' => 'symfony/invented-package',
            ],
        ], $composer);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS,
            $actualContents
        );
    }

    public function testLineProcessedIfRequiredPackageIsPresent()
    {
        $this->saveFile('assets/app.js', <<<JS
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS
        );

        $composer = $this->createComposerMockWithPackagesInstalled([
            'symfony/installed-package',
        ]);

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
                'requires' => 'symfony/installed-package',
            ],
        ], $composer);

        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<JS
            import './bootstrap';
            import * as Turbo from '@hotwired/turbo';

            console.log(Turbo);
            JS,
            $actualContents
        );
    }

    public function testLineSkippedIfRequiredPackageVersionIsWrong()
    {
        $this->saveFile('phpunit.dist.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <extensions>
                </extensions>
            </phpunit>
            XML
        );

        $composer = $this->createComposerMockWithPackagesInstalled([
            'phpunit/phpunit:9',
        ]);

        $this->runConfigure([
            [
                'file' => 'phpunit.dist.xml',
                'position' => 'after_target',
                'target' => '<extensions>',
                'content' => '        <bootstrap class="Symfony\Component\Panther\ServerExtension" />',
                'requires' => 'phpunit/phpunit:12',
            ],
        ], $composer);
        $actualContents = $this->readFile('phpunit.dist.xml');
        $this->assertSame(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <extensions>
                </extensions>
            </phpunit>
            XML,
            $actualContents
        );
    }

    public function testLineProcessedIfRequiredPackageVersionIsRight()
    {
        $this->saveFile('phpunit.dist.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <extensions>
                </extensions>
            </phpunit>
            XML
        );

        $composer = $this->createComposerMockWithPackagesInstalled([
            'phpunit/phpunit:12',
        ]);

        $this->runConfigure([
            [
                'file' => 'phpunit.dist.xml',
                'position' => 'after_target',
                'target' => '<extensions>',
                'content' => '        <bootstrap class="Symfony\Component\Panther\ServerExtension" />',
                'requires' => 'phpunit/phpunit:12',
            ],
        ], $composer);

        $actualContents = $this->readFile('phpunit.dist.xml');
        $this->assertSame(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <extensions>
                    <bootstrap class="Symfony\Component\Panther\ServerExtension" />
                </extensions>
            </phpunit>
            XML,
            $actualContents
        );
    }

    #[DataProvider('getUnconfigureTests')]
    public function testUnconfigure(string $originalContents, string $value, string $expectedContents)
    {
        $this->saveFile('assets/app.js', $originalContents);

        $this->runUnconfigure([
            [
                'file' => 'assets/app.js',
                'content' => $value,
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame($expectedContents, $actualContents);
    }

    public function testExpandTargetDirWhenUnconfiguring()
    {
        $this->saveFile('config/file.txt', <<<EOF
            Line1
            Line2
            EOF
        );

        $this->runUnconfigure([
            [
                'file' => '%CONFIG_DIR%/file.txt',
                'content' => 'Line1',
            ],
        ]);
        $actualContents = $this->readFile('config/file.txt');
        $this->assertSame(<<<EOF
            Line2
            EOF,
            $actualContents
        );
    }

    public static function getUnconfigureTests()
    {
        yield 'found_middle' => [
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
            "import './bootstrap';",
            <<<JS
                import * as Turbo from '@hotwired/turbo';

                console.log(Turbo);
                JS,
        ];

        yield 'found_top' => [
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
            "import * as Turbo from '@hotwired/turbo';",
            <<<JS
                import './bootstrap';

                console.log(Turbo);
                JS,
        ];

        yield 'found_bottom' => [
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
            'console.log(Turbo);',
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                JS,
        ];

        yield 'not_found' => [
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
            "console.log('not found');",
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
        ];

        yield 'found_twice_in_file' => [
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                console.log(Turbo);
                JS,
            'console.log(Turbo);',
            <<<JS
                import * as Turbo from '@hotwired/turbo';
                import './bootstrap';

                console.log(Turbo);
                JS,
        ];
    }

    #[DataProvider('getUpdateTests')]
    public function testUpdate(array $originalFiles, array $originalConfig, array $newConfig, array $expectedFiles)
    {
        foreach ($originalFiles as $filename => $originalContents) {
            $this->saveFile($filename, $originalContents);
        }

        $composer = $this->createComposerMockWithPackagesInstalled([
            'symfony/installed-package',
        ]);
        $configurator = $this->createConfigurator($composer);
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);

        $recipeUpdate = new RecipeUpdate($recipe, $recipe, $lock, FLEX_TEST_DIR);
        $configurator->update($recipeUpdate, $originalConfig, $newConfig);

        $this->assertCount(\count($expectedFiles), $recipeUpdate->getNewFiles());
        foreach ($expectedFiles as $filename => $expectedContents) {
            $this->assertSame($this->readFile($filename), $recipeUpdate->getOriginalFiles()[$filename]);
            $this->assertSame($expectedContents, $recipeUpdate->getNewFiles()[$filename]);
        }
    }

    public static function getUpdateTests()
    {
        $appJsOriginal = <<<JS
            import * as Turbo from '@hotwired/turbo';
            import './bootstrap';

            console.log(Turbo);
            JS;

        $bootstrapJsOriginal = <<<JS
            console.log('bootstrap.js');

            console.log('on the bottom');
            JS;

        yield 'recipe_changes_patch_contents' => [
            ['assets/app.js' => $appJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './bootstrap';"],
            ],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './stimulus_bootstrap';"],
            ],
            ['assets/app.js' => <<<JS
                import './stimulus_bootstrap';
                import * as Turbo from '@hotwired/turbo';

                console.log(Turbo);
                JS
            ],
        ];

        yield 'recipe_file_and_value_same_before_and_after' => [
            ['assets/app.js' => $appJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import * as Turbo from '@hotwired/turbo';"],
            ],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import * as Turbo from '@hotwired/turbo';"],
            ],
            ['assets/app.js' => $appJsOriginal],
        ];

        yield 'different_files_unconfigures_old_and_configures_new' => [
            ['assets/app.js' => $appJsOriginal, 'assets/bootstrap.js' => $bootstrapJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import * as Turbo from '@hotwired/turbo';"],
            ],
            [
                ['file' => 'assets/bootstrap.js', 'position' => 'top', 'content' => "import * as Turbo from '@hotwired/turbo';"],
            ],
            [
                'assets/app.js' => <<<JS
                    import './bootstrap';

                    console.log(Turbo);
                    JS,
                'assets/bootstrap.js' => <<<JS
                    import * as Turbo from '@hotwired/turbo';
                    console.log('bootstrap.js');

                    console.log('on the bottom');
                    JS,
            ],
        ];

        yield 'recipe_changes_but_ignored_because_package_not_installed' => [
            ['assets/app.js' => $appJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './bootstrap';", 'requires' => 'symfony/not-installed'],
            ],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './stimulus_bootstrap';", 'requires' => 'symfony/not-installed'],
            ],
            [], // no changes will come back in the RecipePatch
        ];

        yield 'recipe_changes_are_applied_if_required_package_installed' => [
            ['assets/app.js' => $appJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './bootstrap';", 'requires' => 'symfony/installed-package'],
            ],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './stimulus_bootstrap';", 'requires' => 'symfony/installed-package'],
            ],
            ['assets/app.js' => <<<JS
                import './stimulus_bootstrap';
                import * as Turbo from '@hotwired/turbo';

                console.log(Turbo);
                JS
            ],
        ];
    }

    private function runConfigure(array $config, ?Composer $composer = null)
    {
        $configurator = $this->createConfigurator($composer);

        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $configurator->configure($recipe, $config, $lock);
    }

    private function runUnconfigure(array $config)
    {
        $configurator = $this->createConfigurator();

        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $configurator->unconfigure($recipe, $config, $lock);
    }

    private function createConfigurator(?Composer $composer = null)
    {
        return new AddLinesConfigurator(
            $composer ?: $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
    }

    private function saveFile(string $filename, string $contents)
    {
        $path = FLEX_TEST_DIR.'/'.$filename;
        if (!file_exists(\dirname($path))) {
            @mkdir(\dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function readFile(string $filename): string
    {
        return file_get_contents(FLEX_TEST_DIR.'/'.$filename);
    }

    private function createComposerMockWithPackagesInstalled(array $packages)
    {
        $packages = array_map(fn ($package) => explode(':', $package), $packages);

        $packageNames = array_column($packages, 0);
        $constraints = array_column($packages, 1);

        $repository = $this->createStub(InstalledRepositoryInterface::class);
        $repository
            ->method('findPackage')
            ->willReturnCallback(function ($name, $constraint) use ($packageNames, $constraints) {
                if (\in_array($name, $packageNames) && ('*' === $constraint || \in_array($constraint, $constraints))) {
                    return new Package($name, '1.0.0', '1.0.0');
                }

                return null;
            });
        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager
            ->method('getLocalRepository')
            ->willReturn($repository);
        $composer = $this->createStub(Composer::class);
        $composer
            ->method('getRepositoryManager')
            ->willReturn($repositoryManager);

        return $composer;
    }
}
