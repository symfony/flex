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
    public function testGetClassNamesForInstall($package, $autoload, $class)
    {
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())->method('get')->will($this->returnValue(__DIR__.'/Fixtures/vendor'));
        $composer = $this->getMockBuilder('Composer\Composer')->getMock();
        $composer->expects($this->once())->method('getConfig')->will($this->returnValue($config));
        $package = new Package($package, '1.0', '1.0');
        $package->setAutoload($autoload);
        $bundle = new SymfonyBundle($composer, $package, 'install');
        $this->assertContains($class, $bundle->getClassNames());
    }

    public function getNamespaces()
    {
        return [
            [
                'symfony/debug-bundle',
                ['psr-4' => ['Symfony\\Bundle\\DebugBundle\\' => '']],
                'Symfony\\Bundle\\DebugBundle\\DebugBundle',
            ],
            [
                'doctrine/doctrine-cache-bundle',
                ['psr-4' => ['Doctrine\\Bundle\\DoctrineCacheBundle\\' => '']],
                'Doctrine\\Bundle\\DoctrineCacheBundle\\DoctrineCacheBundle',
            ],
            [
                'eightpoints/guzzle-bundle',
                ['psr-0' => ['EightPoints\\Bundle\\GuzzleBundle' => '']],
                'EightPoints\\Bundle\\GuzzleBundle\\GuzzleBundle',
            ],
            [
                'easycorp/easy-security-bundle',
                ['psr-4' => ['EasyCorp\\Bundle\\EasySecurityBundle\\' => '']],
                'EasyCorp\\Bundle\\EasySecurityBundle\\EasySecurityBundle',
            ],
            [
                'symfony-cmf/routing-bundle',
                ['psr-4' => ['Symfony\\Cmf\\Bundle\\RoutingBundle\\' => '']],
                'Symfony\\Cmf\\Bundle\\RoutingBundle\\CmfRoutingBundle',
            ],
            [
                'easycorp/easy-deploy-bundle',
                ['psr-4' => ['EasyCorp\\Bundle\\EasyDeployBundle\\' => 'src/']],
                'EasyCorp\\Bundle\\EasyDeployBundle\\EasyDeployBundle',
            ],
            [
                'easycorp/easy-deploy-bundle',
                ['psr-4' => ['EasyCorp\\Bundle\\EasyDeployBundle\\' => ['src', 'tests']]],
                'EasyCorp\\Bundle\\EasyDeployBundle\\EasyDeployBundle',
            ],
        ];
    }
}
