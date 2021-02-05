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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\PackageJsonSynchronizer;

class PackageJsonSynchronizerTest extends TestCase
{
    private $tempDir;
    private $synchronizer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/flex-package-json-'.substr(md5(uniqid('', true)), 0, 6);
        (new Filesystem())->mirror(__DIR__.'/Fixtures/packageJson/synchronize', $this->tempDir);

        $this->synchronizer = new PackageJsonSynchronizer($this->tempDir, 'vendor');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    public function testShouldNotSynchronizeNoPackageJson()
    {
        $synchronizer = new PackageJsonSynchronizer(__DIR__.'/Fixtures/packageJson/shouldSynchronize/no-package-json', 'vendor');
        $this->assertFalse($synchronizer->shouldSynchronize(['symfony/package']));
    }

    public function testShouldNotSynchronizeNoUxPackage()
    {
        $synchronizer = new PackageJsonSynchronizer(__DIR__.'/Fixtures/packageJson/shouldSynchronize/no-ux-package', 'vendor');
        $this->assertFalse($synchronizer->shouldSynchronize(['symfony/package']));
    }

    public function testShouldSynchronizeValid()
    {
        $synchronizer = new PackageJsonSynchronizer(__DIR__.'/Fixtures/packageJson/shouldSynchronize/valid', 'vendor');
        $this->assertTrue($synchronizer->shouldSynchronize(['symfony/package']));
    }

    public function testSynchronizeNoPackage()
    {
        $this->synchronizer->synchronize([]);

        // Should remove existing package references as it has been removed from the lock
        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
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
    }

    public function testSynchronizeExistingPackage()
    {
        $this->synchronizer->synchronize(['symfony/existing-package']);

        // Should keep existing package references and config
        $this->assertSame(
            [
                'name' => 'symfony/fixture',
                'devDependencies' => [
                    '@symfony/existing-package' => 'file:vendor/symfony/existing-package/Resources/assets',
                    '@symfony/stimulus-bridge' => '^1.0.0',
                    'stimulus' => '^1.1.1',
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
        $this->synchronizer->synchronize(['symfony/existing-package', 'symfony/new-package']);

        // Should keep existing package references and config and add the new package, while keeping the formatting
        $this->assertSame(
            '{
   "name": "symfony/fixture",
   "devDependencies": {
      "@symfony/existing-package": "file:vendor/symfony/existing-package/Resources/assets",
      "@symfony/new-package": "file:vendor/symfony/new-package/assets",
      "@symfony/stimulus-bridge": "^1.0.0",
      "stimulus": "^1.1.1"
   }
}',
            file_get_contents($this->tempDir.'/package.json')
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
}
