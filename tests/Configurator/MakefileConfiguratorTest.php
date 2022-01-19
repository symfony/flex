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
use Symfony\Flex\Configurator\MakefileConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class MakefileConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        @mkdir(FLEX_TEST_DIR);
    }

    public function testConfigure()
    {
        $configurator = new MakefileConfigurator(
            $this->getMockBuilder(Composer::class)->getMock(),
            $this->getMockBuilder(IOInterface::class)->getMock(),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $recipe1 = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe1->expects($this->any())->method('getName')->willReturn('FooBundle');

        $recipe2 = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe2->expects($this->any())->method('getName')->willReturn('BarBundle');

        $makefile = FLEX_TEST_DIR.'/Makefile';
        @unlink($makefile);
        touch($makefile);

        $makefile1 = explode(
            "\n",
            <<<EOF
CONSOLE := $(shell which bin/console)
sf_console:
ifndef CONSOLE
	@printf "Run \033[32mcomposer require cli\033[39m to install the Symfony console.\n"
endif
EOF
        );
        $makefile2 = explode(
            "\n",
            <<<EOF
cache-clear:
ifdef CONSOLE
	@$(CONSOLE) cache:clear --no-warmup
else
	@rm -rf var/cache/*
endif
.PHONY: cache-clear
EOF
        );

        $makefileContents1 = "###> FooBundle ###\n".implode("\n", $makefile1)."\n###< FooBundle ###";
        $makefileContents2 = "###> BarBundle ###\n".implode("\n", $makefile2)."\n###< BarBundle ###";

        $configurator->configure($recipe1, $makefile1, $lock);
        $this->assertStringEqualsFile($makefile, "\n".$makefileContents1."\n");

        $configurator->configure($recipe2, $makefile2, $lock);
        $this->assertStringEqualsFile($makefile, "\n".$makefileContents1."\n\n".$makefileContents2."\n");

        $configurator->configure($recipe1, $makefile1, $lock);
        $configurator->configure($recipe2, $makefile2, $lock);
        $this->assertStringEqualsFile($makefile, "\n".$makefileContents1."\n\n".$makefileContents2."\n");

        $configurator->unconfigure($recipe1, $makefile1, $lock);
        $this->assertStringEqualsFile($makefile, $makefileContents2."\n");

        $configurator->unconfigure($recipe2, $makefile2, $lock);
        $this->assertFalse(is_file($makefile));
    }

    public function testConfigureForce()
    {
        $configurator = new MakefileConfigurator(
            $this->getMockBuilder(Composer::class)->getMock(),
            $this->getMockBuilder(IOInterface::class)->getMock(),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe->expects($this->any())->method('getName')->willReturn('FooBundle');

        $makefile = FLEX_TEST_DIR.'/Makefile';
        @unlink($makefile);
        touch($makefile);
        file_put_contents($makefile, "# preexisting content\n");

        $bundleLinesConfigure = ['foo: bar'];
        $bundleLinesForce = ['foo: bar zut'];

        $contentConfigure = implode("\n", array_merge(
            ["# preexisting content\n\n###> FooBundle ###"],
            $bundleLinesConfigure,
            ["###< FooBundle ###\n\n# new content"]
        ));

        $contentForce = implode("\n", array_merge(
            ["# preexisting content\n\n###> FooBundle ###"],
            $bundleLinesForce,
            ["###< FooBundle ###\n\n# new content"]
        ));

        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $configurator->configure($recipe, $bundleLinesConfigure, $lock);
        file_put_contents($makefile, "\n# new content", \FILE_APPEND);
        $this->assertStringEqualsFile($makefile, $contentConfigure);

        $configurator->configure($recipe, $bundleLinesForce, $lock, [
            'force' => true,
        ]);
        $this->assertStringEqualsFile($makefile, $contentForce);
    }

    public function testUpdate()
    {
        $configurator = new MakefileConfigurator(
            $this->getMockBuilder(Composer::class)->getMock(),
            $this->getMockBuilder(IOInterface::class)->getMock(),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createMock(Recipe::class);
        $recipe->method('getName')
            ->willReturn('symfony/foo-bundle');
        $recipeUpdate = new RecipeUpdate(
            $recipe,
            $recipe,
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR);
        file_put_contents(
            FLEX_TEST_DIR.'/Makefile',
            <<<EOF
###> symfony/foo-bundle ###
CONSOLE := $(shell which bin/console)
sf_console_CUSTOM:
ifndef CONSOLE
    @printf "Run composer require cli to install the Symfony console."
endif
###< symfony/foo-bundle ###
###> symfony/bar-bundle ###
cache-clear:
ifdef CONSOLE
	@$(CONSOLE) cache:clear --no-warmup
else
	@rm -rf var/cache/*
endif
.PHONY: cache-clear
###< symfony/bar-bundle ###
EOF
        );

        $configurator->update(
            $recipeUpdate,
            [
                'CONSOLE := $(shell which bin/console)',
                'sf_console:',
                'ifndef CONSOLE',
                '    @printf "Run composer require cli to install the Symfony console."',
                'endif',
            ],
            [
                'CONSOLE := $(shell which bin/console)',
                'sf_console_CHANGED:',
                'ifndef CONSOLE',
                '    @printf "Run composer require cli to install the Symfony console."',
                'endif',
            ]
        );

        $this->assertSame(['Makefile' => <<<EOF
###> symfony/foo-bundle ###
CONSOLE := $(shell which bin/console)
sf_console:
ifndef CONSOLE
    @printf "Run composer require cli to install the Symfony console."
endif
###< symfony/foo-bundle ###
###> symfony/bar-bundle ###
cache-clear:
ifdef CONSOLE
	@$(CONSOLE) cache:clear --no-warmup
else
	@rm -rf var/cache/*
endif
.PHONY: cache-clear
###< symfony/bar-bundle ###
EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['Makefile' => <<<EOF
###> symfony/foo-bundle ###
CONSOLE := $(shell which bin/console)
sf_console_CHANGED:
ifndef CONSOLE
    @printf "Run composer require cli to install the Symfony console."
endif
###< symfony/foo-bundle ###
###> symfony/bar-bundle ###
cache-clear:
ifdef CONSOLE
	@$(CONSOLE) cache:clear --no-warmup
else
	@rm -rf var/cache/*
endif
.PHONY: cache-clear
###< symfony/bar-bundle ###
EOF
        ], $recipeUpdate->getNewFiles());
    }
}
