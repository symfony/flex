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

use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\PackageJsonSynchronizer;
use Symfony\Flex\ScriptExecutor;

class PackageJsonSynchronizerTest extends TestCase
{
    private $tempDir;
    private $synchronizer;
    private $scriptExecutor;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/flex-package-json-'.substr(md5(uniqid('', true)), 0, 6);
        (new Filesystem())->mirror(__DIR__.'/Fixtures/packageJson', $this->tempDir);
        $this->scriptExecutor = $this->createMock(ScriptExecutor::class);

        $this->synchronizer = new PackageJsonSynchronizer(
            $this->tempDir,
            'vendor',
            $this->scriptExecutor,
            $this->createMock(IOInterface::class)
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    public function testSynchronizeNoPackage()
    {
        $this->synchronizer->synchronize([]);

        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
                    '@symfony/existing-package' => 'file:vendor/symfony/existing-package/Resources/assets',
                ],
                'browserslist' => [
                    'defaults',
                ],
            ],
            json_decode(file_get_contents($this->tempDir.'/package.json'), true)
        );

        $this->assertSame(
            [
                'controllers' => [],
                'entrypoints' => [],
            ],
            json_decode(file_get_contents($this->tempDir.'/assets/controllers.json'), true)
        );

        unlink($this->tempDir.'/vendor/symfony/existing-package/Resources/assets/package.json');
        $this->synchronizer->synchronize([]);

        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
                ],
                'browserslist' => [
                    'defaults',
                ],
            ],
            json_decode(file_get_contents($this->tempDir.'/package.json'), true)
        );
    }

    public function testSynchronizeExistingPackage()
    {
        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing package references and config
        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    '@hotcookies/bar' => '^1.1|^2',
                    '@hotdogs/bun' => '^2',
                    '@symfony/existing-package' => 'file:vendor/symfony/existing-package/Resources/assets',
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
                ],
                'browserslist' => [
                    'defaults',
                ],
            ],
            json_decode(file_get_contents($this->tempDir.'/package.json'), true)
        );

        $this->assertSame(
            [
                'controllers' => [
                    '@symfony/existing-package' => [
                        'mock' => [
                            'enabled' => false,
                            // the "fetch" replaces the old "webpackMode"
                            'fetch' => 'eager',
                            'autoimport' => [
                                '@symfony/existing-package/dist/style.css' => false,
                                '@symfony/existing-package/dist/new-style.css' => true,
                            ],
                        ],
                    ],
                ],
                'entrypoints' => [],
            ],
            json_decode(file_get_contents($this->tempDir.'/assets/controllers.json'), true)
        );
    }

    public function testSynchronizeNewPackage()
    {
        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
            [
                'name' => 'symfony/new-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing package references and config and add the new package, while keeping the formatting
        $this->assertSame(
            '{
   "name": "symfony/fixture",
   "devDependencies": {
      "@hotdogs/bun": "^2",
      "@symfony/existing-package": "file:vendor/symfony/existing-package/Resources/assets",
      "@symfony/new-package": "file:vendor/symfony/new-package/assets",
      "@symfony/stimulus-bridge": "^1.0.0",
      "stimulus": "^1.1.1"
   },
   "browserslist": [
      "defaults"
   ]
}',
            trim(file_get_contents($this->tempDir.'/package.json'))
        );

        $this->assertSame(
            [
                'controllers' => [
                    '@symfony/existing-package' => [
                        'mock' => [
                            'enabled' => false,
                            'fetch' => 'eager',
                            'autoimport' => [
                                '@symfony/existing-package/dist/style.css' => false,
                                '@symfony/existing-package/dist/new-style.css' => true,
                            ],
                        ],
                    ],
                    '@symfony/new-package' => [
                        'new' => [
                            'enabled' => true,
                            'fetch' => 'lazy',
                            'autoimport' => [
                                '@symfony/new-package/dist/style.css' => true,
                            ],
                        ],
                    ],
                ],
                'entrypoints' => ['admin.js'],
            ],
            json_decode(file_get_contents($this->tempDir.'/assets/controllers.json'), true)
        );
    }

    public function testArrayFormattingHasNotChanged()
    {
        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing array formatting
        $this->assertSame(
            '{
   "name": "symfony/fixture",
   "devDependencies": {
      "@hotcookies/bar": "^1.1|^2",
      "@hotdogs/bun": "^2",
      "@symfony/existing-package": "file:vendor/symfony/existing-package/Resources/assets",
      "@symfony/stimulus-bridge": "^1.0.0",
      "stimulus": "^1.1.1"
   },
   "browserslist": [
      "defaults"
   ]
}',
            trim(file_get_contents($this->tempDir.'/package.json'))
        );
    }

    public function testExistingElevatedPackage()
    {
        (new Filesystem())->copy($this->tempDir.'/elevated_dependencies_package.json', $this->tempDir.'/package.json', true);

        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing package references and config
        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'dependencies' => [
                    '@hotcookies/bar' => '^1.1|^2',
                    '@hotdogs/bun' => '^2',
                    '@symfony/existing-package' => 'file:vendor/symfony/existing-package/Resources/assets',
                ],
                'devDependencies' => [
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
                ],
                'browserslist' => [
                    'defaults',
                ],
            ],
            json_decode(file_get_contents($this->tempDir.'/package.json'), true)
        );
    }

    public function testStricterConstraintsAreKeptNonMatchingAreReplaced()
    {
        (new Filesystem())->copy($this->tempDir.'/stricter_constraints_package.json', $this->tempDir.'/package.json', true);

        (new Filesystem())->copy($this->tempDir.'/stricter_constraints_package.json', $this->tempDir.'/package.json', true);

        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing constraints when stricter than packages ones
        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    // this satisfies the constraint, so it's kept
                    '@hotcookies/bar' => '^2',
                    // this was too low, so it's replaced
                    '@hotdogs/bun' => '^2',
                    '@symfony/existing-package' => 'file:vendor/symfony/existing-package/Resources/assets',
                ],
                'browserslist' => [
                    'defaults',
                ],
            ],
            json_decode(file_get_contents($this->tempDir.'/package.json'), true)
        );
    }

    public function testSynchronizePackageWithoutNeedingFilePackage()
    {
        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
            [
                'name' => 'symfony/package-no-file-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // Should keep existing package references and config and add the new package, while keeping the formatting
        $this->assertSame(
            '{
   "name": "symfony/fixture",
   "devDependencies": {
      "@hotdogs/bun": "^2",
      "@symfony/existing-package": "file:vendor/symfony/existing-package/Resources/assets",
      "@symfony/stimulus-bridge": "^1.0.0",
      "stimulus": "^1.1.1"
   },
   "browserslist": [
      "defaults"
   ]
}',
            trim(file_get_contents($this->tempDir.'/package.json'))
        );
    }

    public function testSynchronizeAssetMapperNewPackage()
    {
        file_put_contents($this->tempDir.'/importmap.php', '<?php return [];');

        $fileModulePath = $this->tempDir.'/vendor/symfony/new-package/assets/dist/loader.js';
        $this->scriptExecutor->expects($this->exactly(2))
            ->method('execute')
            ->withConsecutive(
                ['symfony-cmd', 'importmap:require', ['@hotcake/foo@^1.9.0']],
                ['symfony-cmd', 'importmap:require', ['@symfony/new-package', '--path='.$fileModulePath]]
            );

        $this->synchronizer->synchronize([
            [
                // no "importmap" specific config, but still registered as a controller
                'name' => 'symfony/existing-package',
                'keywords' => ['symfony-ux'],
            ],
            [
                'name' => 'symfony/new-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);

        // package.json exists, but should remain untouched because importmap.php was found
        $this->assertSame(
            '{
   "name": "symfony/fixture",
   "devDependencies": {
      "@symfony/stimulus-bridge": "^1.0.0",
      "stimulus": "^1.1.1",
      "@symfony/existing-package": "file:vendor/symfony/existing-package/Resources/assets"
   },
   "browserslist": [
      "defaults"
   ]
}',
            trim(file_get_contents($this->tempDir.'/package.json'))
        );

        // controllers.json updated like normal
        $this->assertSame(
            [
                'controllers' => [
                    '@symfony/existing-package' => [
                        'mock' => [
                            'enabled' => false,
                            'fetch' => 'eager',
                            'autoimport' => [
                                '@symfony/existing-package/dist/style.css' => false,
                                '@symfony/existing-package/dist/new-style.css' => true,
                            ],
                        ],
                    ],
                    '@symfony/new-package' => [
                        'new' => [
                            'enabled' => true,
                            'fetch' => 'lazy',
                            'autoimport' => [
                                '@symfony/new-package/dist/style.css' => true,
                            ],
                        ],
                    ],
                ],
                'entrypoints' => ['admin.js'],
            ],
            json_decode(file_get_contents($this->tempDir.'/assets/controllers.json'), true)
        );
    }

    public function testSynchronizeAssetMapperUpgradesPackageIfNeeded()
    {
        $importMap = [
            '@hotcake/foo' => [
                // constraint in package.json is ^1.9.0
                'version' => '1.8.0',
            ],
        ];
        file_put_contents($this->tempDir.'/importmap.php', sprintf('<?php return %s;', var_export($importMap, true)));

        $fileModulePath = $this->tempDir.'/vendor/symfony/new-package/assets/dist/loader.js';
        $this->scriptExecutor->expects($this->exactly(2))
            ->method('execute')
            ->withConsecutive(
                ['symfony-cmd', 'importmap:require', ['@hotcake/foo@^1.9.0']],
                ['symfony-cmd', 'importmap:require', ['@symfony/new-package', '--path='.$fileModulePath]]
            );

        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/new-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);
    }

    public function testSynchronizeAssetMapperSkipsUpgradeIfAlreadySatisfied()
    {
        $importMap = [
            '@hotcake/foo' => [
                // constraint in package.json is ^1.9.0
                'version' => '1.9.1',
            ],
        ];
        file_put_contents($this->tempDir.'/importmap.php', sprintf('<?php return %s;', var_export($importMap, true)));

        $fileModulePath = $this->tempDir.'/vendor/symfony/new-package/assets/dist/loader.js';
        $this->scriptExecutor->expects($this->once())
            ->method('execute')
            ->withConsecutive(
                ['symfony-cmd', 'importmap:require', ['@symfony/new-package', '--path='.$fileModulePath]]
            );

        $this->synchronizer->synchronize([
            [
                'name' => 'symfony/new-package',
                'keywords' => ['symfony-ux'],
            ],
        ]);
    }
}
