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

use Symfony\Flex\Configurator\ContainerConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class ContainerConfiguratorTest extends ConfiguratorTest
{
    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(FLEX_TEST_DIR);
    }

    protected function createConfigurator(): ContainerConfigurator
    {
        return new ContainerConfigurator(
            $this->composer,
            $this->io,
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
    }

    public function testConfigure()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        @mkdir(\dirname($config));
        file_put_contents(
            $config,
            <<<EOF
# comment
parameters:

services:

EOF
        );
        $this->configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
# comment
parameters:
    locale: 'en'

services:

EOF
        , file_get_contents($config));

        $this->configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
# comment
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithoutParametersKey()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
services:

EOF
        );
        $this->configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
parameters:
    locale: 'en'

services:

EOF
        , file_get_contents($config));

        $this->configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithoutDuplicated()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
parameters:
    locale: es

services:

EOF
        );
        $this->configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
parameters:
    locale: es

services:

EOF
        , file_get_contents($config));

        $this->configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<EOF
parameters:

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithComplexContent()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
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
        $this->configurator->configure($recipe, ['locale' => 'en', 'foobar' => 'baz'], $lock);
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

        $this->configurator->unconfigure($recipe, ['locale' => 'en', 'foobar' => 'baz'], $lock);
        $this->assertEquals(<<<EOF
parameters:
    # comment 1

    # comment 2
    foo: bar

services:

EOF
        , file_get_contents($config));
    }

    public function testConfigureWithComplexContent2()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
parameters:
    # comment 1
    locale: es

services:

EOF
        );
        $this->configurator->configure($recipe, ['locale' => 'en', 'foobar' => 'baz', 'array' => ['key1' => 'value', 'key2' => "Escape ' one quote"], 'key1' => 'Keep It'], $lock);
        $this->assertEquals(<<<EOF
parameters:
    # comment 1
    locale: es
    foobar: 'baz'
    array:
        key1: 'value'
        key2: 'Escape '' one quote'
    key1: 'Keep It'

services:

EOF
            , file_get_contents($config));

        $this->configurator->unconfigure($recipe, ['locale' => 'en', 'array' => ['key1' => 'value', 'key2' => "Escape ' one quote"]], $lock);
        $this->assertEquals(<<<EOF
parameters:
    # comment 1
    foobar: 'baz'
    key1: 'Keep It'

services:

EOF
            , file_get_contents($config));
    }

    public function testConfigureWithEnvVariable()
    {
        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<EOF
# comment
parameters:
    env(APP_ENV): ''

services:

EOF
        );
        $this->configurator->configure($recipe, ['env(APP_ENV)' => ''], $lock);
        $this->assertEquals(<<<EOF
# comment
parameters:
    env(APP_ENV): ''

services:

EOF
            , file_get_contents($config));

        $this->configurator->unconfigure($recipe, ['env(APP_ENV)' => ''], $lock);
        $this->assertEquals(<<<EOF
# comment
parameters:

services:

EOF
            , file_get_contents($config));
    }

    public function testUpdate()
    {
        $recipeUpdate = new RecipeUpdate(
            $this->createMock(Recipe::class),
            $this->createMock(Recipe::class),
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR.'/config');
        file_put_contents(
            FLEX_TEST_DIR.'/config/services.yaml',
            <<<EOF
parameters:
    # comment 1
    locale: es

    # comment 2
    foo: bar

services:

EOF
        );

        $this->configurator->update(
            $recipeUpdate,
            ['locale' => 'en', 'foobar' => 'baz'],
            ['locale' => 'fr', 'foobar' => 'baz', 'new_one' => 'hallo']
        );

        $this->assertSame(['config/services.yaml' => <<<EOF
parameters:
    # comment 1
    locale: en

    # comment 2
    foo: bar
    foobar: 'baz'

services:

EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['config/services.yaml' => <<<EOF
parameters:
    # comment 1
    locale: fr

    # comment 2
    foo: bar
    foobar: 'baz'
    new_one: 'hallo'

services:

EOF
        ], $recipeUpdate->getNewFiles());
    }
}
