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

use Composer\Package\Package;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\SymfonyBundle;

class SymfonyBundleTest extends TestCase
{
    /**
     * @dataProvider getNamespaces
     */
    public function testGetClassNamesForInstall($autoload, $classes)
    {
        $installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')->getMock();
        $composer = $this->getMockBuilder('Composer\Composer')->getMock();
        $composer->expects($this->once())->method('getInstallationManager')->will($this->returnValue($installationManager));
        $package = new Package('foo/bar-bundle', '1.0', '1.0');
        $package->setAutoload($autoload);
        $bundle = new SymfonyBundle($composer, $package, 'install');
        $this->assertEquals($classes, $bundle->getClassNames());
    }

    public function getNamespaces()
    {
        return [
            [
                ['psr-4' => ['Symfony\\Bundle\\DebugBundle\\' => 'src/']],
                ['Symfony\\Bundle\\DebugBundle\\DebugBundle'],
            ],
            [
                ['psr-4' => ['Foo\\BarBundle\\' => 'src/']],
                ['Foo\\BarBundle\\FooBarBundle'],
            ],
        ];
    }
}
