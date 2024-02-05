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
        $this->saveFile('assets/app.js', <<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
        );

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'top',
                'content' => "import './bootstrap';",
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<EOF
import './bootstrap';
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
            ,
            $actualContents);
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
EOF
            ,
            $actualContents);
    }

    public function testLinesAddedToBottomOfFile()
    {
        $this->saveFile('assets/app.js', <<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
        );

        $this->runConfigure([
            [
                'file' => 'assets/app.js',
                'position' => 'bottom',
                'content' => "import './bootstrap';",
            ],
        ]);
        $actualContents = $this->readFile('assets/app.js');
        $this->assertSame(<<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
import './bootstrap';
EOF
            ,
            $actualContents);
    }

    public function testLinesAddedAfterTarget()
    {
        $this->saveFile('webpack.config.js', <<<EOF
const Encore = require('@symfony/webpack-encore');

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    .addEntry('app', './assets/app.js')

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()
;

module.exports = Encore.getWebpackConfig();
EOF
        );

        $this->runConfigure([
            [
                'file' => 'webpack.config.js',
                'position' => 'after_target',
                'target' => '.addEntry(\'app\', \'./assets/app.js\')',
                'content' => <<<EOF

    // enables the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
    .enableStimulusBridge('./assets/controllers.json')
EOF
            ],
        ]);

        $actualContents = $this->readFile('webpack.config.js');
        $this->assertSame(<<<EOF
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
EOF
            ,
            $actualContents);
    }

    public function testSkippedIfTargetCannotBeFound()
    {
        $originalContent = <<<EOF
const Encore = require('@symfony/webpack-encore');

Encore
    .setOutputPath('public/build/')
;

module.exports = Encore.getWebpackConfig();
EOF;

        $this->saveFile('webpack.config.js', $originalContent);

        $this->runConfigure([
            [
                'file' => 'webpack.config.js',
                'position' => 'after_target',
                'target' => '.addEntry(\'app\', \'./assets/app.js\')',
                'content' => <<<EOF

    // some new line
EOF
            ],
        ]);

        $this->assertSame($originalContent, $this->readFile('webpack.config.js'));
    }

    public function testPatchIgnoredIfValueAlreadyExists()
    {
        $originalContents = <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF;

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
        $this->saveFile('assets/app.js', <<<EOF
import * as Turbo from '@hotwired/turbo';
EOF
        );

        $this->saveFile('assets/bootstrap.js', <<<EOF
console.log('bootstrap.js');
EOF
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

        $this->assertSame(<<<EOF
import './bootstrap';
import * as Turbo from '@hotwired/turbo';
EOF
            ,
            $this->readFile('assets/app.js'));

        $this->assertSame(<<<EOF
console.log('bootstrap.js');
console.log('on the bottom');
EOF
            ,
            $this->readFile('assets/bootstrap.js'));
    }

    public function testLineSkippedIfRequiredPackageMissing()
    {
        $this->saveFile('assets/app.js', <<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
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
        $this->assertSame(<<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
            ,
            $actualContents);
    }

    public function testLineProcessedIfRequiredPackageIsPresent()
    {
        $this->saveFile('assets/app.js', <<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
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
        $this->assertSame(<<<EOF
import './bootstrap';
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
            ,
            $actualContents);
    }

    /**
     * @dataProvider getUnconfigureTests
     */
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
        $this->saveFile('config/file.txt',
            <<<EOF
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
EOF
            , $actualContents);
    }

    public function getUnconfigureTests()
    {
        yield 'found_middle' => [
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
            ,
            "import './bootstrap';",
            <<<EOF
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
        ];

        yield 'found_top' => [
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
            ,
            "import * as Turbo from '@hotwired/turbo';",
            <<<EOF
import './bootstrap';

console.log(Turbo);
EOF
        ];

        yield 'found_bottom' => [
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
            ,
            'console.log(Turbo);',
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

EOF
        ];

        yield 'not_found' => [
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
            ,
            "console.log('not found');",
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
        ];

        yield 'found_twice_in_file' => [
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
console.log(Turbo);
EOF
            ,
            'console.log(Turbo);',
            <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF
        ];
    }

    /**
     * @dataProvider getUpdateTests
     */
    public function testUpdate(array $originalFiles, array $originalConfig, array $newConfig, array $expectedFiles)
    {
        foreach ($originalFiles as $filename => $originalContents) {
            $this->saveFile($filename, $originalContents);
        }

        $composer = $this->createComposerMockWithPackagesInstalled([
            'symfony/installed-package',
        ]);
        $configurator = $this->createConfigurator($composer);
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $recipeUpdate = new RecipeUpdate($recipe, $recipe, $lock, FLEX_TEST_DIR);
        $configurator->update($recipeUpdate, $originalConfig, $newConfig);

        $this->assertCount(\count($expectedFiles), $recipeUpdate->getNewFiles());
        foreach ($expectedFiles as $filename => $expectedContents) {
            $this->assertSame($this->readFile($filename), $recipeUpdate->getOriginalFiles()[$filename]);
            $this->assertSame($expectedContents, $recipeUpdate->getNewFiles()[$filename]);
        }
    }

    public function getUpdateTests()
    {
        $appJsOriginal = <<<EOF
import * as Turbo from '@hotwired/turbo';
import './bootstrap';

console.log(Turbo);
EOF;

        $bootstrapJsOriginal = <<<EOF
console.log('bootstrap.js');

console.log('on the bottom');
EOF;

        yield 'recipe_changes_patch_contents' => [
            ['assets/app.js' => $appJsOriginal],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './bootstrap';"],
            ],
            [
                ['file' => 'assets/app.js', 'position' => 'top', 'content' => "import './stimulus_bootstrap';"],
            ],
            ['assets/app.js' => <<<EOF
import './stimulus_bootstrap';
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
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
                'assets/app.js' => <<<EOF
import './bootstrap';

console.log(Turbo);
EOF
                ,
                'assets/bootstrap.js' => <<<EOF
import * as Turbo from '@hotwired/turbo';
console.log('bootstrap.js');

console.log('on the bottom');
EOF
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
            ['assets/app.js' => <<<EOF
import './stimulus_bootstrap';
import * as Turbo from '@hotwired/turbo';

console.log(Turbo);
EOF
            ],
        ];
    }

    private function runConfigure(array $config, Composer $composer = null)
    {
        $configurator = $this->createConfigurator($composer);

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $configurator->configure($recipe, $config, $lock);
    }

    private function runUnconfigure(array $config)
    {
        $configurator = $this->createConfigurator();

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $configurator->unconfigure($recipe, $config, $lock);
    }

    private function createConfigurator(Composer $composer = null)
    {
        return new AddLinesConfigurator(
            $composer ?: $this->getMockBuilder(Composer::class)->getMock(),
            $this->getMockBuilder(IOInterface::class)->getMock(),
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
        $repository = $this->getMockBuilder(InstalledRepositoryInterface::class)->getMock();
        $repository->expects($this->any())
            ->method('findPackage')
            ->willReturnCallback(function ($name) use ($packages) {
                if (\in_array($name, $packages)) {
                    return new Package($name, '1.0.0', '1.0.0');
                }

                return null;
            });
        $repositoryManager = $this->getMockBuilder(RepositoryManager::class)->disableOriginalConstructor()->getMock();
        $repositoryManager->expects($this->any())
            ->method('getLocalRepository')
            ->willReturn($repository);
        $composer = $this->getMockBuilder(Composer::class)->getMock();
        $composer->expects($this->any())
            ->method('getRepositoryManager')
            ->willReturn($repositoryManager);

        return $composer;
    }
}
