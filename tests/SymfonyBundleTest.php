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
use Composer\Package\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\SymfonyBundle;

class SymfonyBundleTest extends TestCase
{
    #[DataProvider('getNamespaces')]
    public function testGetClassNamesForInstall($package, $autoload, $classes, $type = null)
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(__DIR__.'/Fixtures/vendor');
        $composer = $this->getMockBuilder(Composer::class)->getMock();
        $composer->expects($this->once())->method('getConfig')->willReturn($config);
        $package = new Package($package, '1.0', '1.0');
        $package->setAutoload($autoload);
        if ($type) {
            $package->setType($type);
        }

        $bundle = new SymfonyBundle($composer, $package, 'install');
        $this->assertSame($classes, $bundle->getClassNames());
    }

    public static function getNamespaces()
    {
        $packages = FlexTest::getTestPackages();
        foreach ($packages as $name => $info) {
            $packageData = [$name, $info['autoload'], $info['bundles']];
            if (isset($info['type'])) {
                $packageData[] = $info['type'];
            }

            yield $packageData;
        }
    }
}
