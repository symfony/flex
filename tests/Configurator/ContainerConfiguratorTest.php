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
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\ContainerConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class ContainerConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        @mkdir(FLEX_TEST_DIR);
    }

    public function testConfigure()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        @mkdir(\dirname($config));
        file_put_contents(
            $config,
            <<<YAML
                # comment
                parameters:

                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            # comment
            parameters:
                locale: 'en'

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            # comment
            parameters:

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testConfigureWithoutParametersKey()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<YAML
                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                locale: 'en'

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            parameters:

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testConfigureWithoutDuplicated()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<YAML
                parameters:
                    locale: es

                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                locale: es

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['locale' => 'en'], $lock);
        $this->assertEquals(<<<YAML
            parameters:

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testConfigureWithComplexContent()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<YAML
                parameters:
                    # comment 1
                    locale: es

                    # comment 2
                    foo: bar

                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['locale' => 'en', 'foobar' => 'baz'], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                # comment 1
                locale: es

                # comment 2
                foo: bar
                foobar: 'baz'

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['locale' => 'en', 'foobar' => 'baz'], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                # comment 1

                # comment 2
                foo: bar

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testConfigureWithComplexContent2()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<YAML
                parameters:
                    # comment 1
                    locale: es

                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['locale' => 'en', 'foobar' => 'baz', 'array' => ['key1' => 'value', 'key2' => "Escape ' one quote"], 'key1' => 'Keep It'], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                # comment 1
                locale: es
                foobar: 'baz'
                array:
                    key1: 'value'
                    key2: 'Escape '' one quote'
                key1: 'Keep It'

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['locale' => 'en', 'array' => ['key1' => 'value', 'key2' => "Escape ' one quote"]], $lock);
        $this->assertEquals(<<<YAML
            parameters:
                # comment 1
                foobar: 'baz'
                key1: 'Keep It'

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testConfigureWithEnvVariable()
    {
        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);
        $config = FLEX_TEST_DIR.'/config/services.yaml';
        file_put_contents(
            $config,
            <<<YAML
                # comment
                parameters:
                    env(APP_ENV): ''

                services:

                YAML
        );
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
        $configurator->configure($recipe, ['env(APP_ENV)' => ''], $lock);
        $this->assertEquals(<<<YAML
            # comment
            parameters:
                env(APP_ENV): ''

            services:

            YAML,
            file_get_contents($config)
        );

        $configurator->unconfigure($recipe, ['env(APP_ENV)' => ''], $lock);
        $this->assertEquals(<<<YAML
            # comment
            parameters:

            services:

            YAML,
            file_get_contents($config)
        );
    }

    public function testUpdate()
    {
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );

        $recipeUpdate = new RecipeUpdate(
            $this->createStub(Recipe::class),
            $this->createStub(Recipe::class),
            $this->createStub(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR.'/config');
        file_put_contents(
            FLEX_TEST_DIR.'/config/services.yaml',
            <<<YAML
                parameters:
                    # comment 1
                    locale: es

                    # comment 2
                    foo: bar

                services:

                YAML
        );

        $configurator->update(
            $recipeUpdate,
            ['locale' => 'en', 'foobar' => 'baz'],
            ['locale' => 'fr', 'foobar' => 'baz', 'new_one' => 'hallo']
        );

        $this->assertSame(
            [
                'config/services.yaml' => <<<YAML
                    parameters:
                        # comment 1
                        locale: en

                        # comment 2
                        foo: bar
                        foobar: 'baz'

                    services:

                    YAML,
            ],
            $recipeUpdate->getOriginalFiles()
        );

        $this->assertSame(
            [
                'config/services.yaml' => <<<YAML
                    parameters:
                        # comment 1
                        locale: fr

                        # comment 2
                        foo: bar
                        foobar: 'baz'
                        new_one: 'hallo'

                    services:

                    YAML,
            ],
            $recipeUpdate->getNewFiles()
        );
    }

    public function testUpdateWithNoRemovedKeysInUpdate()
    {
        $configurator = new ContainerConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );

        $recipeUpdate = new RecipeUpdate(
            $this->createStub(Recipe::class),
            $this->createStub(Recipe::class),
            $this->createStub(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR.'/config');
        file_put_contents(
            FLEX_TEST_DIR.'/config/services.yaml',
            <<<YAML
                parameters:
                    locale: es
                    something: else

                services:
                    foo_router: '@router'
                YAML
        );

        $configurator->update(
            $recipeUpdate,
            ['locale' => 'en'],
            []
        );

        $this->assertSame(
            [
                'config/services.yaml' => <<<YAML
                    parameters:
                        locale: en
                        something: else

                    services:
                        foo_router: '@router'
                    YAML,
            ],
            $recipeUpdate->getOriginalFiles()
        );

        $this->assertSame(
            [
                'config/services.yaml' => <<<YAML
                    parameters:
                        something: else

                    services:
                        foo_router: '@router'
                    YAML,
            ],
            $recipeUpdate->getNewFiles()
        );
    }
}
