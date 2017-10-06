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
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator;
use Symfony\Flex\Options;

class ConfiguratorTest extends TestCase
{
    public function testAdd()
    {
        $composer = new Composer();
        $io = new NullIO();
        $options = new Options();
        $configurator = new Configurator($composer, $io, $options);
        $ref = new \ReflectionClass($configurator);
        $property = $ref->getProperty('configurators');
        $property->setAccessible(true);

        $this->assertArrayNotHasKey('foo/mock-configurator', $property->getValue($configurator));
        $mockConfigurator = $this->getMockForAbstractClass(Configurator\AbstractConfigurator::class, [$composer, $io, $options]);
        $configurator->add('foo/mock-configurator', get_class($mockConfigurator));
        $this->assertArrayHasKey('foo/mock-configurator', $property->getValue($configurator));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Flex configurator name "mock-configurator" must be prefixed with the vendor name, ex. "foo/custom-configurator".
     */
    public function testAddWithoutVendorName()
    {
        $composer = new Composer();
        $io = new NullIO();
        $options = new Options();
        $configurator = new Configurator($composer, $io, $options);
        $mockConfigurator = $this->getMockForAbstractClass(Configurator\AbstractConfigurator::class, [$composer, $io, $options]);
        $configurator->add('mock-configurator', get_class($mockConfigurator));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Flex configurator with the name "foo/mock-configurator" already exists.
     */
    public function testAddWithExistingConfiguratorName()
    {
        $composer = new Composer();
        $io = new NullIO();
        $options = new Options();
        $configurator = new Configurator($composer, $io, $options);
        $mockConfigurator = $this->getMockForAbstractClass(Configurator\AbstractConfigurator::class, [$composer, $io, $options]);
        $configurator->add('foo/mock-configurator', get_class($mockConfigurator));
        $configurator->add('foo/mock-configurator', get_class($mockConfigurator));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Flex configurator class "stdClass" must extend the class "Symfony\Flex\Configurator\AbstractConfigurator".
     */
    public function testAddWithoutAbstractConfiguratorClass()
    {
        $composer = new Composer();
        $io = new NullIO();
        $options = new Options();
        $configurator = new Configurator($composer, $io, $options);
        $configurator->add('foo/mock-configurator', \stdClass::class);
    }
}
