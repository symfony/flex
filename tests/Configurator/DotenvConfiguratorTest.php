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
use Symfony\Flex\Configurator\DotenvConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class DotenvConfiguratorTest extends TestCase
{
    public function testConfigure()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new DotenvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
        $lock = $this->createStub(Lock::class);

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.local';
        @unlink($env);
        touch($env);

        $configurator->configure($recipe, [
            'local' => [
                'APP_ENV' => 'test bar',
                'APP_DEBUG' => '0',
                'APP_PARAGRAPH' => "foo\n\"bar\"\\t",
                'DATABASE_URL' => 'mysql://root@127.0.0.1:3306/symfony?charset=utf8mb4&serverVersion=5.7',
                'MAILER_URL' => 'null://localhost',
                'MAILER_USER' => 'fabien',
                '#1' => 'Comment 1',
                '#2' => 'Comment 3',
                '#TRUSTED_SECRET' => 's3cretf0rt3st"<>',
                'APP_SECRET' => 's3cretf0rt3st"<>',
                'ALLOWED_LANGUAGES' => '\'["en","de","es"]\'',
            ],
        ], $lock);

        $envContents = <<<EOF

###> FooBundle ###
APP_ENV="test bar"
APP_DEBUG=0
APP_PARAGRAPH="foo\\n\\"bar\\"\\\\t"
DATABASE_URL="mysql://root@127.0.0.1:3306/symfony?charset=utf8mb4&serverVersion=5.7"
MAILER_URL=null://localhost
MAILER_USER=fabien
# Comment 1
# Comment 3
#TRUSTED_SECRET="s3cretf0rt3st\"<>"
APP_SECRET="s3cretf0rt3st\"<>"
ALLOWED_LANGUAGES='["en","de","es"]'
###< FooBundle ###

EOF;

        $this->assertStringEqualsFile($env, $envContents);

        $configurator->configure($recipe, [
            'local' => [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
                '#1' => 'Comment 1',
                '#2' => 'Comment 3',
                '#TRUSTED_SECRET' => 's3cretf0rt3st',
                'APP_SECRET' => 's3cretf0rt3st',
            ],
        ], $lock);

        $this->assertStringEqualsFile($env, $envContents);

        $configurator->unconfigure($recipe, [
            'local' => [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
                '#1' => 'Comment 1',
                '#2' => 'Comment 3',
                '#TRUSTED_SECRET' => 's3cretf0rt3st',
                'APP_SECRET' => 's3cretf0rt3st',
            ],
        ], $lock);

        $this->assertStringEqualsFile(
            $env,
            <<<EOF


EOF
        );

        @unlink($env);
    }

    public function testConfigureGeneratedSecret()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new DotenvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
        $lock = $this->createStub(Lock::class);

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.local';
        @unlink($env);
        touch($env);

        $configurator->configure($recipe, [
            'local' => [
                '#TRUSTED_SECRET_1' => '%generate(secret,32)%',
                '#TRUSTED_SECRET_2' => '%generate(secret, 32)%',
                '#TRUSTED_SECRET_3' => '%generate(secret,     32)%',
                'APP_SECRET' => '%generate(secret)%',
            ],
        ], $lock);

        $envContents = file_get_contents($env);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_1=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_2=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_3=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/APP_SECRET=[a-z0-9]{32}/', $envContents);
        @unlink($env);
    }

    public function testConfigureForce()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new DotenvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.local';
        @unlink($env);
        touch($env);
        file_put_contents($env, "# preexisting content\n");

        $envContentsConfigure = <<<EOT
# preexisting content

###> FooBundle ###
FOO=bar
###< FooBundle ###

# new content

EOT;
        $envContentsForce = <<<EOT
# preexisting content

###> FooBundle ###
FOO=bar
OOF=rab
###< FooBundle ###

# new content

EOT;

        $lock = $this->createStub(Lock::class);

        $configurator->configure($recipe, [
            'local' => [
                'FOO' => 'bar',
            ],
        ], $lock);

        file_put_contents($env, "\n# new content\n", \FILE_APPEND);

        $this->assertStringEqualsFile($env, $envContentsConfigure);

        $configurator->configure($recipe, [
            'local' => [
                'FOO' => 'bar',
                'OOF' => 'rab',
            ],
        ], $lock, [
            'force' => true,
        ]);

        $this->assertStringEqualsFile($env, $envContentsForce);

        @unlink($env);
    }

    public function testUpdate()
    {
        $configurator = new DotenvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')
            ->willReturn('symfony/foo-bundle');
        $recipeUpdate = new RecipeUpdate(
            $recipe,
            $recipe,
            $this->createStub(Lock::class),
            FLEX_TEST_DIR
        );

        @mkdir(FLEX_TEST_DIR);
        file_put_contents(
            FLEX_TEST_DIR.'/.env.local',
            <<<EOF
# Some comments on top
###> symfony/foo-bundle ###
APP_ENV="test bar"
APP_SECRET=EXISTING_SECRET_VALUE
APP_DEBUG=0
###< symfony/foo-bundle ###
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###
EOF
        );

        $configurator->update(
            $recipeUpdate,
            // %generate(secret)% should not regenerate a new value
            ['local' => ['APP_ENV' => 'original', 'APP_SECRET' => '%generate(secret)%', 'APP_DEBUG' => 0, 'EXTRA_VAR' => 'apple']],
            ['local' => ['APP_ENV' => 'updated', 'APP_SECRET' => '%generate(secret)%', 'APP_DEBUG' => 0, 'NEW_VAR' => 'orange']]
        );

        $this->assertSame(['.env.local' => <<<EOF
# Some comments on top
###> symfony/foo-bundle ###
APP_ENV=original
APP_SECRET=EXISTING_SECRET_VALUE
APP_DEBUG=0
EXTRA_VAR=apple
###< symfony/foo-bundle ###
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###
EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['.env.local' => <<<EOF
# Some comments on top
###> symfony/foo-bundle ###
APP_ENV=updated
APP_SECRET=EXISTING_SECRET_VALUE
APP_DEBUG=0
NEW_VAR=orange
###< symfony/foo-bundle ###
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###
EOF
        ], $recipeUpdate->getNewFiles());
    }
}
