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
use Composer\Util\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\ComposerCommandsConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class ComposerCommandConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        @mkdir(FLEX_TEST_DIR);
        if (method_exists(Platform::class, 'putEnv')) {
            Platform::putEnv('COMPOSER', FLEX_TEST_DIR.'/composer.json');
        } else {
            putenv('COMPOSER='.FLEX_TEST_DIR.'/composer.json');
        }
    }

    protected function tearDown(): void
    {
        @unlink(FLEX_TEST_DIR.'/composer.json');
        @rmdir(FLEX_TEST_DIR);
        if (method_exists(Platform::class, 'clearEnv')) {
            Platform::clearEnv('COMPOSER');
        } else {
            putenv('COMPOSER');
        }
    }

    #[DataProvider('providerForConfigureMethod')]
    public function testConfigure($composerSchema, string $expectedComposerJson)
    {
        file_put_contents(FLEX_TEST_DIR.'/composer.json', json_encode($composerSchema, \JSON_PRETTY_PRINT));

        $configurator = new ComposerCommandsConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);

        $configurator->configure($recipe, [
            'do:cool-stuff' => 'symfony-cmd',
        ], $lock);
        $this->assertEquals(
            $expectedComposerJson,
            file_get_contents(FLEX_TEST_DIR.'/composer.json')
        );
    }

    public static function providerForConfigureMethod(): iterable
    {
        yield 'without_scripts_block' => [
            new \stdClass(),
            <<<EOF
            {
                "scripts": {
                    "do:cool-stuff": "symfony-cmd"
                }
            }

            EOF,
        ];

        yield 'with_existing_command' => [
            [
                'scripts' => [
                    'foo' => 'bar',
                ],
            ],
            <<<EOF
            {
                "scripts": {
                    "foo": "bar",
                    "do:cool-stuff": "symfony-cmd"
                }
            }

            EOF,
        ];

        yield 'with_existing_auto_scripts' => [
            [
                'scripts' => [
                    'auto-scripts' => [
                        'cache:clear' => 'symfony-cmd',
                        'assets:install %PUBLIC_DIR%' => 'symfony-cmd',
                    ],
                    'post-install-cmd' => ['@auto-scripts'],
                    'post-update-cmd' => ['@auto-scripts'],
                ],
            ],
            <<<EOF
            {
                "scripts": {
                    "auto-scripts": {
                        "cache:clear": "symfony-cmd",
                        "assets:install %PUBLIC_DIR%": "symfony-cmd"
                    },
                    "post-install-cmd": [
                        "@auto-scripts"
                    ],
                    "post-update-cmd": [
                        "@auto-scripts"
                    ],
                    "do:cool-stuff": "symfony-cmd"
                }
            }

            EOF,
        ];
    }

    #[DataProvider('providerForUnconfigureMethod')]
    public function testUnconfigure($composerSchema, string $expectedComposerJson)
    {
        file_put_contents(FLEX_TEST_DIR.'/composer.json', json_encode($composerSchema, \JSON_PRETTY_PRINT));

        $configurator = new ComposerCommandsConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $lock = $this->createStub(Lock::class);

        $configurator->unconfigure($recipe, [
            'do:cool-stuff' => 'symfony-cmd',
        ], $lock);
        $this->assertEquals(
            $expectedComposerJson,
            file_get_contents(FLEX_TEST_DIR.'/composer.json')
        );
    }

    public static function providerForUnconfigureMethod(): iterable
    {
        yield 'unconfigure_one_command_with_auto_scripts' => [
            [
                'scripts' => [
                    'auto-scripts' => [
                        'cache:clear' => 'symfony-cmd',
                        'assets:install %PUBLIC_DIR%' => 'symfony-cmd',
                    ],
                    'post-install-cmd' => ['@auto-scripts'],
                    'post-update-cmd' => ['@auto-scripts'],
                    'do:cool-stuff' => 'symfony-cmd',
                    'do:another-cool-stuff' => 'symfony-cmd-2',
                ],
            ],
            <<<EOF
            {
                "scripts": {
                    "auto-scripts": {
                        "cache:clear": "symfony-cmd",
                        "assets:install %PUBLIC_DIR%": "symfony-cmd"
                    },
                    "post-install-cmd": [
                        "@auto-scripts"
                    ],
                    "post-update-cmd": [
                        "@auto-scripts"
                    ],
                    "do:another-cool-stuff": "symfony-cmd-2"
                }
            }

            EOF,
        ];

        yield 'unconfigure_command' => [
            [
                'scripts' => [
                    'do:another-cool-stuff' => 'symfony-cmd-2',
                    'do:cool-stuff' => 'symfony-cmd',
                ],
            ],
            <<<EOF
            {
                "scripts": {
                    "do:another-cool-stuff": "symfony-cmd-2"
                }
            }

            EOF,
        ];
    }

    public function testUpdate()
    {
        $configurator = new ComposerCommandsConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipeUpdate = new RecipeUpdate(
            $this->createStub(Recipe::class),
            $this->createStub(Recipe::class),
            $this->createStub(Lock::class),
            FLEX_TEST_DIR
        );

        file_put_contents(FLEX_TEST_DIR.'/composer.json', json_encode([
            'scripts' => [
                'auto-scripts' => [
                    'cache:clear' => 'symfony-cmd',
                    'assets:install %PUBLIC_DIR%' => 'symfony-cmd',
                ],
                'post-install-cmd' => ['@auto-scripts'],
                'post-update-cmd' => ['@auto-scripts'],
                'foo' => 'bar',
            ],
        ], \JSON_PRETTY_PRINT));

        $configurator->update(
            $recipeUpdate,
            ['foo' => 'bar'],
            ['foo' => 'baz', 'do:cool-stuff' => 'symfony-cmd']
        );

        $expectedComposerJsonOriginal = <<<EOF
            {
                "scripts": {
                    "auto-scripts": {
                        "cache:clear": "symfony-cmd",
                        "assets:install %PUBLIC_DIR%": "symfony-cmd"
                    },
                    "post-install-cmd": [
                        "@auto-scripts"
                    ],
                    "post-update-cmd": [
                        "@auto-scripts"
                    ],
                    "foo": "bar"
                }
            }

            EOF;
        $this->assertSame(['composer.json' => $expectedComposerJsonOriginal], $recipeUpdate->getOriginalFiles());

        $expectedComposerJsonNew = <<<EOF
            {
                "scripts": {
                    "auto-scripts": {
                        "cache:clear": "symfony-cmd",
                        "assets:install %PUBLIC_DIR%": "symfony-cmd"
                    },
                    "post-install-cmd": [
                        "@auto-scripts"
                    ],
                    "post-update-cmd": [
                        "@auto-scripts"
                    ],
                    "foo": "baz",
                    "do:cool-stuff": "symfony-cmd"
                }
            }

            EOF;
        $this->assertSame(['composer.json' => $expectedComposerJsonNew], $recipeUpdate->getNewFiles());
    }
}
