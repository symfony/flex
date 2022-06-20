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

use Symfony\Flex\Configurator\GitignoreConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class GitignoreConfiguratorTest extends ConfiguratorTest
{
    protected function createConfigurator(): GitignoreConfigurator
    {
        return new GitignoreConfigurator(
            $this->composer,
            $this->io,
            new Options(['public-dir' => 'public', 'root-dir' => FLEX_TEST_DIR])
        );
    }

    public function testConfigure()
    {
        @mkdir(FLEX_TEST_DIR);
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $recipe1 = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe1->expects($this->any())->method('getName')->willReturn('FooBundle');

        $recipe2 = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe2->expects($this->any())->method('getName')->willReturn('BarBundle');

        $gitignore = FLEX_TEST_DIR.'/.gitignore';
        @unlink($gitignore);
        touch($gitignore);

        $vars1 = [
            '.env',
            '/%PUBLIC_DIR%/bundles/',
        ];
        $vars2 = [
            '/var/',
            '/vendor/',
        ];

        $gitignoreContents1 = <<<EOF
###> FooBundle ###
.env
/public/bundles/
###< FooBundle ###
EOF;
        $gitignoreContents2 = <<<EOF
###> BarBundle ###
/var/
/vendor/
###< BarBundle ###
EOF;

        $this->configurator->configure($recipe1, $vars1, $lock);
        $this->assertStringEqualsFile($gitignore, "\n".$gitignoreContents1."\n");

        $this->configurator->configure($recipe2, $vars2, $lock);
        $this->assertStringEqualsFile($gitignore, "\n".$gitignoreContents1."\n\n".$gitignoreContents2."\n");

        $this->configurator->configure($recipe1, $vars1, $lock);
        $this->configurator->configure($recipe2, $vars2, $lock);
        $this->assertStringEqualsFile($gitignore, "\n".$gitignoreContents1."\n\n".$gitignoreContents2."\n");

        $this->configurator->unconfigure($recipe1, $vars1, $lock);
        $this->assertStringEqualsFile($gitignore, $gitignoreContents2."\n");

        $this->configurator->unconfigure($recipe2, $vars2, $lock);
        $this->assertStringEqualsFile($gitignore, '');

        @unlink($gitignore);
    }

    public function testConfigureForce()
    {
        @mkdir(FLEX_TEST_DIR);

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipe->expects($this->any())->method('getName')->willReturn('FooBundle');

        $gitignore = FLEX_TEST_DIR.'/.gitignore';
        @unlink($gitignore);
        touch($gitignore);
        file_put_contents($gitignore, "# preexisting content\n");

        $contentsConfigure = <<<EOF
# preexisting content

###> FooBundle ###
.env
###< FooBundle ###

# new content
EOF;
        $contentsForce = <<<EOF
# preexisting content

###> FooBundle ###
.env
.env.test
###< FooBundle ###

# new content
EOF;

        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();
        $this->configurator->configure($recipe, [
            '.env',
        ], $lock);
        file_put_contents($gitignore, "\n# new content", \FILE_APPEND);
        $this->assertStringEqualsFile($gitignore, $contentsConfigure);

        $this->configurator->configure($recipe, [
            '.env',
            '.env.test',
        ], $lock, [
            'force' => true,
        ]);
        $this->assertStringEqualsFile($gitignore, $contentsForce);

        @unlink($gitignore);
    }

    public function testUpdate()
    {
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
            FLEX_TEST_DIR.'/.gitignore',
            <<<EOF
# preexisting content

###> symfony/foo-bundle ###
/.env.local
/.env.CUSTOMIZED.php
/.env.*.local
/new_custom_entry
###< symfony/foo-bundle ###

###> symfony/bar-bundle ###
/.bar_file
###< symfony/bar-bundle ###

# new content
EOF
        );

        $this->configurator->update(
            $recipeUpdate,
            ['/.env.local', '/.env.local.php', '/.env.*.local', '/vendor/'],
            ['/.env.LOCAL', '/.env.LOCAL.php', '/.env.*.LOCAL', '/%VAR_DIR%/']
        );

        $this->assertSame(['.gitignore' => <<<EOF
# preexisting content

###> symfony/foo-bundle ###
/.env.local
/.env.local.php
/.env.*.local
/vendor/
###< symfony/foo-bundle ###

###> symfony/bar-bundle ###
/.bar_file
###< symfony/bar-bundle ###

# new content
EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['.gitignore' => <<<EOF
# preexisting content

###> symfony/foo-bundle ###
/.env.LOCAL
/.env.LOCAL.php
/.env.*.LOCAL
/%VAR_DIR%/
###< symfony/foo-bundle ###

###> symfony/bar-bundle ###
/.bar_file
###< symfony/bar-bundle ###

# new content
EOF
        ], $recipeUpdate->getNewFiles());
    }
}
