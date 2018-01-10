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

require_once __DIR__.'/TmpDirMock.php';

use Symfony\Flex\Configurator\ContainerConfigurator;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Options;

class ContainerConfiguratorTest extends TestCase
{
    public function testConfigure()
    {
        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();
        $config = sys_get_temp_dir().'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
# comment
parameters:

services:

EOF
        );
        $configurator = new ContainerConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options(['config-dir' => dirname($config)])
        );
        $configurator->configure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
# comment
parameters:
    locale: 'en'

services:

EOF
        , file_get_contents($config));

        $configurator->unconfigure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
# comment
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithoutParametersKey()
    {
        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();
        $config = sys_get_temp_dir().'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
services:

EOF
        );
        $configurator = new ContainerConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options(['config-dir' => dirname($config)])
        );
        $configurator->configure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
parameters:
    locale: 'en'

services:

EOF
        , file_get_contents($config));

        $configurator->unconfigure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithoutDuplicated()
    {
        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();
        $config = sys_get_temp_dir().'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
parameters:
    locale: es

services:

EOF
        );
        $configurator = new ContainerConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options(['config-dir' => dirname($config)])
        );
        $configurator->configure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
parameters:
    locale: es

services:

EOF
        , file_get_contents($config));

        $configurator->unconfigure($recipe, ['locale' => 'en']);
        $this->assertEquals(<<<EOF
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithComplexContent()
    {
        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();
        $config = sys_get_temp_dir().'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
parameters:
    # comment 1
    locale: es

    # comment 2
    foo: bar

services:

EOF
        );
        $configurator = new ContainerConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options(['config-dir' => dirname($config)])
        );
        $configurator->configure($recipe, ['locale' => 'en', 'foobar' => 'baz']);
        $this->assertEquals(<<<EOF
parameters:
    # comment 1
    locale: es

    # comment 2
    foo: bar
    foobar: 'baz'

services:

EOF
        , file_get_contents($config));

        $configurator->unconfigure($recipe, ['locale' => 'en', 'foobar' => 'baz']);
        $this->assertEquals(<<<EOF
parameters:
    # comment 1

    # comment 2
    foo: bar

services:

EOF
        , file_get_contents($config));
    }
}
