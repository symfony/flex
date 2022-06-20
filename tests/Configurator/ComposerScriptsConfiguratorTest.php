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

use Composer\Util\Platform;
use Symfony\Flex\Configurator\ComposerScriptsConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class ComposerScriptsConfiguratorTest extends ConfiguratorTest
{
    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(FLEX_TEST_DIR);
        if (method_exists(Platform::class, 'putEnv')) {
            Platform::putEnv('COMPOSER', FLEX_TEST_DIR.'/composer.json');
        } else {
            putenv('COMPOSER='.FLEX_TEST_DIR.'/composer.json');
        }
    }

    protected function createConfigurator(): ComposerScriptsConfigurator
    {
        return new ComposerScriptsConfigurator(
            $this->composer,
            $this->io,
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
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

    public function testConfigure()
    {
        file_put_contents(FLEX_TEST_DIR.'/composer.json', json_encode([
            'scripts' => [
                'auto-scripts' => [
                    'cache:clear' => 'symfony-cmd',
                    'assets:install %PUBLIC_DIR%' => 'symfony-cmd',
                ],
                'post-install-cmd' => ['@auto-scripts'],
                'post-update-cmd' => ['@auto-scripts'],
            ],
        ], \JSON_PRETTY_PRINT));

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $this->configurator->configure($recipe, [
            'do:cool-stuff' => 'symfony-cmd',
        ], $lock);
        $this->assertEquals(<<<EOF
{
    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd",
            "do:cool-stuff": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ]
    }
}

EOF
            , file_get_contents(FLEX_TEST_DIR.'/composer.json')
        );
    }

    public function testUnconfigure()
    {
        file_put_contents(FLEX_TEST_DIR.'/composer.json', json_encode([
            'scripts' => [
                'auto-scripts' => [
                    'cache:clear' => 'symfony-cmd',
                    'assets:install %PUBLIC_DIR%' => 'symfony-cmd',
                ],
                'post-install-cmd' => ['@auto-scripts'],
                'post-update-cmd' => ['@auto-scripts'],
            ],
        ], \JSON_PRETTY_PRINT));

        $recipe = $this->createMock(Recipe::class);
        $lock = $this->createMock(Lock::class);

        $this->configurator->unconfigure($recipe, [
            'do:cool-stuff' => 'symfony-cmd',
            'cache:clear' => 'symfony-cmd',
        ], $lock);
        $this->assertEquals(<<<EOF
{
    "scripts": {
        "auto-scripts": {
            "assets:install %PUBLIC_DIR%": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ]
    }
}

EOF
            , file_get_contents(FLEX_TEST_DIR.'/composer.json')
        );
    }

    public function testUpdate()
    {
        $recipeUpdate = new RecipeUpdate(
            $this->createMock(Recipe::class),
            $this->createMock(Recipe::class),
            $this->createMock(Lock::class),
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
            ],
        ], \JSON_PRETTY_PRINT));

        $this->configurator->update(
            $recipeUpdate,
            ['cache:clear' => 'symfony-cmd'],
            ['cache:clear' => 'other-cmd', 'do:cool-stuff' => 'symfony-cmd']
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
        ]
    }
}

EOF
        ;
        $this->assertSame(['composer.json' => $expectedComposerJsonOriginal], $recipeUpdate->getOriginalFiles());

        $expectedComposerJsonNew = <<<EOF
{
    "scripts": {
        "auto-scripts": {
            "cache:clear": "other-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd",
            "do:cool-stuff": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ]
    }
}

EOF
        ;
        $this->assertSame(['composer.json' => $expectedComposerJsonNew], $recipeUpdate->getNewFiles());
    }
}
