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

use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Semver\Constraint\Constraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Downloader;
use Symfony\Flex\PackageFilter;

#[RequiresMethod(PrePoolCreateEvent::class, '__construct')]
class PackageFilterTest extends TestCase
{
    #[DataProvider('provideRemoveLegacyPackages')]
    public function testRemoveLegacyPackages(array $expected, array $packages, string $symfonyRequire, array $versions, array $lockedPackages = [])
    {
        $downloader = $this->createStub(Downloader::class);
        $downloader
            ->method('getVersions')
            ->willReturn($versions);
        $filter = new PackageFilter(new NullIO(), $symfonyRequire, $downloader);

        $configToPackage = static function (array $configs) {
            $l = new ArrayLoader();
            $packages = [];
            foreach ($configs as $name => $versions) {
                foreach ($versions as $version => $extra) {
                    $packages[] = $l->load([
                        'name' => $name,
                        'version' => $version,
                    ] + $extra, CompletePackage::class);
                }
            }

            return $packages;
        };
        $sortPackages = static function (PackageInterface $a, PackageInterface $b) {
            return [$a->getName(), $a->getVersion()] <=> [$b->getName(), $b->getVersion()];
        };

        $expected = $configToPackage($expected);
        $packages = $configToPackage($packages);
        $lockedPackages = $configToPackage($lockedPackages);
        $rootPackage = new RootPackage('test/test', '1.0.0.0', '1.0');
        $rootPackage->setRequires([
            'symfony/bar' => new Link('__root__', 'symfony/bar', new Constraint('>=', '3.0.0.0')),
        ]);

        $actual = $filter->removeLegacyPackages($packages, $rootPackage, $lockedPackages);

        usort($expected, $sortPackages);
        usort($actual, $sortPackages);

        $this->assertEquals($expected, $actual);
    }

    private function configToPackage(array $configs)
    {
        $l = new ArrayLoader();
        $packages = [];
        foreach ($configs as $name => $versions) {
            foreach ($versions as $version => $extra) {
                $packages[] = $l->load([
                    'name' => $name,
                    'version' => $version,
                ] + $extra, CompletePackage::class);
            }
        }

        return $packages;
    }

    public static function provideRemoveLegacyPackages()
    {
        $branchAlias = function ($versionAlias) {
            return [
                'extra' => [
                    'branch-alias' => [
                        'dev-main' => $versionAlias.'-dev',
                    ],
                ],
            ];
        };

        $packages = [
            'foo/unrelated' => [
                '1.0.0' => [],
            ],
            'symfony/symfony' => [
                '3.3.0' => ['version_normalized' => '3.3.0.0'],
                '3.4.0' => ['version_normalized' => '3.4.0.0'],
                'dev-main' => $branchAlias('3.5'),
            ],
            'symfony/foo' => [
                '3.3.0' => ['version_normalized' => '3.3.0.0'],
                '3.4.0' => ['version_normalized' => '3.4.0.0'],
                'dev-main' => $branchAlias('3.5'),
            ],
        ];

        yield 'empty-intersection-ignores-2' => [$packages, $packages, '~2.0', ['splits' => [
            'symfony/foo' => ['3.3', '3.4', '3.5'],
        ]]];
        yield 'empty-intersection-ignores-4' => [$packages, $packages, '~4.0', ['splits' => [
            'symfony/foo' => ['3.3', '3.4', '3.5'],
        ]]];

        $expected = $packages;
        unset($expected['symfony/symfony']['3.3.0']);
        unset($expected['symfony/foo']['3.3.0']);

        yield 'non-empty-intersection-filters' => [$expected, $packages, '~3.4', ['splits' => [
            'symfony/foo' => ['3.3', '3.4', '3.5'],
        ]]];

        unset($expected['symfony/symfony']['3.4.0']);
        unset($expected['symfony/foo']['3.4.0']);

        yield 'main-only' => [$expected, $packages, '~3.5', ['splits' => [
            'symfony/foo' => ['3.4', '3.5'],
        ]]];

        $packages = [
            'symfony/symfony' => [
                '2.8.0' => ['version_normalized' => '2.8.0.0'],
            ],
            'symfony/legacy' => [
                '2.8.0' => ['version_normalized' => '2.8.0.0'],
                'dev-main' => $branchAlias('2.8'),
            ],
        ];

        yield 'legacy-are-not-filtered' => [$packages, $packages, '~3.0', ['splits' => [
            'symfony/legacy' => ['2.8'],
            'symfony/foo' => ['2.8'],
        ]]];

        $packages = [
            'symfony/symfony' => [
                '2.8.0' => ['version_normalized' => '2.8.0.0'],
                'dev-main' => $branchAlias('3.0'),
            ],
            'symfony/foo' => [
                '2.8.0' => ['version_normalized' => '2.8.0.0'],
                'dev-main' => $branchAlias('3.0'),
            ],
            'symfony/new' => [
                'dev-main' => $branchAlias('3.0'),
            ],
        ];

        $expected = $packages;
        unset($expected['symfony/symfony']['dev-main']);
        unset($expected['symfony/foo']['dev-main']);

        yield 'main-is-filtered-only-when-in-range' => [$expected, $packages, '~2.8', ['splits' => [
            'symfony/foo' => ['2.8', '3.0'],
            'symfony/new' => ['3.0'],
        ]]];

        $packages = [
            'symfony/symfony' => [
                '3.0.0' => ['version_normalized' => '3.0.0.0'],
            ],
            'symfony/foo' => [
                '3.0.0' => ['version_normalized' => '3.0.0.0'],
            ],
        ];

        $lockedPackages = [
            'symfony/foo' => [
                '3.0.0' => ['version_normalized' => '3.0.0.0'],
            ],
        ];

        yield 'locked-packages-are-preserved' => [$packages, $packages, '~2.8', ['splits' => [
            'symfony/foo' => ['2.8', '3.0'],
        ]], $lockedPackages];

        $packages = [
            'symfony/symfony' => [
                '3.0.0' => ['version_normalized' => '3.0.0.0'],
            ],
            'symfony/bar' => [
                '3.0.0' => ['version_normalized' => '3.0.0.0'],
            ],
        ];

        yield 'root-constraints-are-preserved' => [$packages, $packages, '~2.8', ['splits' => [
            'symfony/bar' => ['2.8', '3.0'],
        ]]];
    }

    public function testIgnorePreleases()
    {
        $io = new NullIO();
        $downloader = $this->createStub(Downloader::class);
        $filter = new PackageFilter($io, '', $downloader, true);

        $stablePkg = $this->createPackageMock('pkg/stable', 'stable');
        $devPkg = $this->createPackageMock('pkg/dev', 'dev');
        $alphaPkg = $this->createPackageMock('pkg/alpha', 'alpha');
        $betaPkg = $this->createPackageMock('pkg/beta', 'beta');
        $rcPkg = $this->createPackageMock('pkg/rc', 'RC');

        $root = $this->createStub(RootPackageInterface::class);

        $result = $filter->removeLegacyPackages([$stablePkg, $devPkg, $alphaPkg, $betaPkg, $rcPkg], $root, []);

        $this->assertSame([$stablePkg, $devPkg], $result);
    }

    public function testWithoutIgnorePreleases()
    {
        $io = new NullIO();
        $downloader = $this->createStub(Downloader::class);
        $filter = new PackageFilter($io, '', $downloader, false);

        $packages = [
            $this->createPackageMock('pkg/stable', 'stable'),
            $this->createPackageMock('pkg/dev', 'dev'),
            $this->createPackageMock('pkg/alpha', 'alpha'),
            $this->createPackageMock('pkg/beta', 'beta'),
            $this->createPackageMock('pkg/rc', 'RC'),
        ];

        $root = $this->createStub(RootPackageInterface::class);

        $result = $filter->removeLegacyPackages($packages, $root, []);

        $this->assertSame($packages, $result);
    }

    private function createPackageMock(string $name, string $stability): PackageInterface
    {
        $package = $this->createStub(PackageInterface::class);
        $package->method('getName')->willReturn($name);
        $package->method('getVersion')->willReturn('1.0.0');
        $package->method('getStability')->willReturn($stability);

        return $package;
    }
}
