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
use Symfony\Flex\Configurator\EnvConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class EnvConfiguratorTest extends TestCase
{
    public function testConfigure()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new EnvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
        $lock = $this->createStub(Lock::class);

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.dist';
        @unlink($env);
        touch($env);

        $phpunit = FLEX_TEST_DIR.'/phpunit.xml';
        $phpunitDist = $phpunit.'.dist';
        @unlink($phpunit);
        @unlink($phpunitDist);
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunit);
        $configurator->configure($recipe, [
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
            'APP_QUOTES' => "'foo' 'bar'",
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
APP_QUOTES="'foo' 'bar'"
###< FooBundle ###

EOF;
        $xmlContents = <<<EOF
<?xml version="1.0" encoding="UTF-8"?>

<!-- https://phpunit.de/manual/current/en/appendixes.configuration.html -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.1/phpunit.xsd"
         backupGlobals="false"
         colors="true"
         bootstrap="vendor/autoload.php"
>
    <php>
        <ini name="error_reporting" value="-1" />
        <env name="KERNEL_CLASS" value="App\Kernel" />

        <!-- ###+ FooBundle ### -->
        <env name="APP_ENV" value="test bar"/>
        <env name="APP_DEBUG" value="0"/>
        <env name="APP_PARAGRAPH" value="foo&#10;&quot;bar&quot;\\t"/>
        <env name="DATABASE_URL" value="mysql://root@127.0.0.1:3306/symfony?charset=utf8mb4&amp;serverVersion=5.7"/>
        <env name="MAILER_URL" value="null://localhost"/>
        <env name="MAILER_USER" value="fabien"/>
        <!-- Comment 1 -->
        <!-- Comment 3 -->
        <!-- env name="TRUSTED_SECRET" value="s3cretf0rt3st&quot;&lt;&gt;" -->
        <env name="APP_SECRET" value="s3cretf0rt3st&quot;&lt;&gt;"/>
        <env name="ALLOWED_LANGUAGES" value="[&quot;en&quot;,&quot;de&quot;,&quot;es&quot;]"/>
        <env name="APP_QUOTES" value="'foo' 'bar'"/>
        <!-- ###- FooBundle ### -->
    </php>

    <testsuites>
        <testsuite name="Project Test Suite">
            <directory>tests/</directory>
        </testsuite>
    </testsuites>
</phpunit>

EOF;

        $this->assertStringEqualsFile($env, $envContents);
        $this->assertStringEqualsFile($phpunitDist, $xmlContents);
        $this->assertStringEqualsFile($phpunit, $xmlContents);

        $configurator->configure($recipe, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            '#1' => 'Comment 1',
            '#2' => 'Comment 3',
            '#TRUSTED_SECRET' => 's3cretf0rt3st',
            'APP_SECRET' => 's3cretf0rt3st',
        ], $lock);

        $this->assertStringEqualsFile($env, $envContents);
        $this->assertStringEqualsFile($phpunitDist, $xmlContents);
        $this->assertStringEqualsFile($phpunit, $xmlContents);

        $configurator->unconfigure($recipe, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            '#1' => 'Comment 1',
            '#2' => 'Comment 3',
            '#TRUSTED_SECRET' => 's3cretf0rt3st',
            'APP_SECRET' => 's3cretf0rt3st',
        ], $lock);

        $this->assertStringEqualsFile(
            $env,
            <<<EOF


EOF
        );

        $this->assertFileEquals(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);
        $this->assertFileEquals(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunit);

        @unlink($env);
        @unlink($phpunit);
    }

    public function testConfigureGeneratedSecret()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new EnvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );
        $lock = $this->createStub(Lock::class);

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.dist';
        @unlink($env);
        touch($env);
        $phpunit = FLEX_TEST_DIR.'/phpunit.xml';
        $phpunitDist = $phpunit.'.dist';
        @unlink($phpunit);
        @unlink($phpunitDist);

        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunit);

        $configurator->configure($recipe, [
            '#TRUSTED_SECRET_1' => '%generate(secret,32)%',
            '#TRUSTED_SECRET_2' => '%generate(secret, 32)%',
            '#TRUSTED_SECRET_3' => '%generate(secret,     32)%',
            'APP_SECRET' => '%generate(secret)%',
        ], $lock);

        $envContents = file_get_contents($env);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_1=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_2=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/#TRUSTED_SECRET_3=[a-z0-9]{64}/', $envContents);
        $this->assertMatchesRegularExpression('/APP_SECRET=[a-z0-9]{32}/', $envContents);
        @unlink($env);

        foreach ([$phpunitDist, $phpunit] as $file) {
            $fileContents = file_get_contents($file);

            $this->assertMatchesRegularExpression('/<!-- env name="TRUSTED_SECRET_1" value="[a-z0-9]{64}" -->/', $fileContents);
            $this->assertMatchesRegularExpression('/<!-- env name="TRUSTED_SECRET_2" value="[a-z0-9]{64}" -->/', $fileContents);
            $this->assertMatchesRegularExpression('/<!-- env name="TRUSTED_SECRET_3" value="[a-z0-9]{64}" -->/', $fileContents);
            $this->assertMatchesRegularExpression('/<env name="APP_SECRET" value="[a-z0-9]{32}"\/>/', $fileContents);
        }
    }

    public function testUpdatePhpUnit()
    {
        $configurator = new EnvConfigurator(
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
        foreach (['.env', '.env.dist', '.env.test', 'phpunit.dist.xml', 'phpunit.xml'] as $file) {
            @unlink(FLEX_TEST_DIR.'/'.$file);
        }
        file_put_contents(
            FLEX_TEST_DIR.'/phpunit.xml.dist',
            <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <ini name="error_reporting" value="-1" />

        <!-- ###+ symfony/foo-bundle ### -->
        <env name="APP_ENV" value="test bar"/>
        <env name="APP_SECRET" value="EXISTING&amp;SECRET"/>
        <!-- env name="TRUSTED_SECRET" value="EXISTING_TRUSTED_SECRET" -->
        <!-- ###- symfony/foo-bundle ### -->
    </php>
</phpunit>
EOF
        );

        $configurator->update(
            $recipeUpdate,
            ['APP_ENV' => 'original', 'APP_SECRET' => '%generate(secret)%', '#TRUSTED_SECRET' => '%generate(secret, 32)%'],
            ['APP_ENV' => 'updated', 'APP_SECRET' => '%generate(secret)%', '#TRUSTED_SECRET' => '%generate(secret, 32)%', 'NEW_VAR' => 'orange']
        );

        $this->assertSame(['phpunit.xml.dist' => <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <ini name="error_reporting" value="-1" />

        <!-- ###+ symfony/foo-bundle ### -->
        <env name="APP_ENV" value="original"/>
        <env name="APP_SECRET" value="EXISTING&amp;SECRET"/>
        <!-- env name="TRUSTED_SECRET" value="EXISTING_TRUSTED_SECRET" -->
        <!-- ###- symfony/foo-bundle ### -->
    </php>
</phpunit>
EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['phpunit.xml.dist' => <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <ini name="error_reporting" value="-1" />

        <!-- ###+ symfony/foo-bundle ### -->
        <env name="APP_ENV" value="updated"/>
        <env name="APP_SECRET" value="EXISTING&amp;SECRET"/>
        <!-- env name="TRUSTED_SECRET" value="EXISTING_TRUSTED_SECRET" -->
        <env name="NEW_VAR" value="orange"/>
        <!-- ###- symfony/foo-bundle ### -->
    </php>
</phpunit>
EOF
        ], $recipeUpdate->getNewFiles());

        @unlink(FLEX_TEST_DIR.'/phpunit.xml.dist');
    }

    public function testConfigureForce()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new EnvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.dist';
        $phpunit = FLEX_TEST_DIR.'/phpunit.xml';
        $phpunitDist = $phpunit.'.dist';
        @unlink($env);
        @unlink($phpunit);
        @unlink($phpunitDist);
        touch($env);
        file_put_contents($env, "# preexisting content\n");
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunit);

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

        $xmlContentsConfigure = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>

<!-- https://phpunit.de/manual/current/en/appendixes.configuration.html -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.1/phpunit.xsd"
         backupGlobals="false"
         colors="true"
         bootstrap="vendor/autoload.php"
>
    <php>
        <ini name="error_reporting" value="-1" />
        <env name="KERNEL_CLASS" value="App\Kernel" />

        <!-- ###+ FooBundle ### -->
        <env name="FOO" value="bar"/>
        <!-- ###- FooBundle ### -->

        <env name="NEW" value="content"/>
    </php>

    <testsuites>
        <testsuite name="Project Test Suite">
            <directory>tests/</directory>
        </testsuite>
    </testsuites>
</phpunit>

EOT;
        $xmlContentsForce = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>

<!-- https://phpunit.de/manual/current/en/appendixes.configuration.html -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.1/phpunit.xsd"
         backupGlobals="false"
         colors="true"
         bootstrap="vendor/autoload.php"
>
    <php>
        <ini name="error_reporting" value="-1" />
        <env name="KERNEL_CLASS" value="App\Kernel" />

        <!-- ###+ FooBundle ### -->
        <env name="FOO" value="bar"/>
        <env name="OOF" value="rab"/>
        <!-- ###- FooBundle ### -->

        <env name="NEW" value="content"/>
    </php>

    <testsuites>
        <testsuite name="Project Test Suite">
            <directory>tests/</directory>
        </testsuite>
    </testsuites>
</phpunit>

EOT;

        $lock = $this->createStub(Lock::class);

        $configurator->configure($recipe, [
            'FOO' => 'bar',
        ], $lock);

        file_put_contents($env, "\n# new content\n", \FILE_APPEND);
        file_put_contents($phpunit, str_replace(
            '    </php>',
            "\n        <env name=\"NEW\" value=\"content\"/>\n    </php>",
            file_get_contents($phpunit)
        ));
        file_put_contents($phpunitDist, str_replace(
            '    </php>',
            "\n        <env name=\"NEW\" value=\"content\"/>\n    </php>",
            file_get_contents($phpunitDist)
        ));

        $this->assertStringEqualsFile($env, $envContentsConfigure);
        $this->assertStringEqualsFile($phpunit, $xmlContentsConfigure);
        $this->assertStringEqualsFile($phpunitDist, $xmlContentsConfigure);

        $configurator->configure($recipe, [
            'FOO' => 'bar',
            'OOF' => 'rab',
        ], $lock, [
            'force' => true,
        ]);

        $this->assertStringEqualsFile($env, $envContentsForce);
        $this->assertStringEqualsFile($phpunit, $xmlContentsForce);
        $this->assertStringEqualsFile($phpunitDist, $xmlContentsForce);

        @unlink($env);
        @unlink($phpunit);
        @unlink($phpunitDist);
    }

    public function testConfigureWithDollarsAndBackslashesInValues()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new EnvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env.dist';
        $phpunitDist = FLEX_TEST_DIR.'/phpunit.xml.dist';
        @unlink($env);
        @unlink($phpunitDist);
        touch($env);
        copy(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);

        $envContents = <<<'EOT'

###> FooBundle ###
DB_PASSWORD=pa$1word
WIN_PATH=C:\\data
###< FooBundle ###

EOT;
        $xmlContents = <<<'EOT'
        <!-- ###+ FooBundle ### -->
        <env name="DB_PASSWORD" value="pa$1word"/>
        <env name="WIN_PATH" value="C:\\data"/>
        <!-- ###- FooBundle ### -->

EOT;

        $lock = $this->createStub(Lock::class);
        $vars = ['DB_PASSWORD' => 'pa$1word', 'WIN_PATH' => 'C:\\\\data'];

        $configurator->configure($recipe, $vars, $lock);

        $this->assertStringEqualsFile($env, $envContents);
        $this->assertStringContainsString($xmlContents, file_get_contents($phpunitDist));

        $configurator->configure($recipe, $vars, $lock, [
            'force' => true,
        ]);

        $this->assertStringEqualsFile($env, $envContents);
        $this->assertStringContainsString($xmlContents, file_get_contents($phpunitDist));

        @unlink($env);
        @unlink($phpunitDist);
    }

    public function testUpdate()
    {
        $configurator = new EnvConfigurator(
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
            FLEX_TEST_DIR.'/.env',
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
            ['APP_ENV' => 'original', 'APP_SECRET' => '%generate(secret)%', 'APP_DEBUG' => 0, 'EXTRA_VAR' => 'apple'],
            ['APP_ENV' => 'updated', 'APP_SECRET' => '%generate(secret)%', 'APP_DEBUG' => 0, 'NEW_VAR' => 'orange']
        );

        $this->assertSame(['.env' => <<<EOF
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

        $this->assertSame(['.env' => <<<EOF
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

    public function testUpdateWhenTheNewRecipeHasNoVars()
    {
        $configurator = new EnvConfigurator(
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
            FLEX_TEST_DIR.'/.env',
            <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

###> symfony/foo-bundle ###
FOO_DSN=foo://default
###< symfony/foo-bundle ###

EOF
        );

        $configurator->update($recipeUpdate, ['FOO_DSN' => 'foo://default'], []);

        $this->assertSame(['.env' => <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

###> symfony/foo-bundle ###
FOO_DSN=foo://default
###< symfony/foo-bundle ###

EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['.env' => <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

EOF
        ], $recipeUpdate->getNewFiles());
    }

    public function testUpdateWhenTheOriginalRecipeHasNoVars()
    {
        $configurator = new EnvConfigurator(
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
            FLEX_TEST_DIR.'/.env',
            <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

EOF
        );

        $configurator->update($recipeUpdate, [], ['FOO_DSN' => 'foo://default']);

        $this->assertSame(['.env' => <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

EOF
        ], $recipeUpdate->getOriginalFiles());

        $this->assertSame(['.env' => <<<EOF
# Some comments on top
###> symfony/baz-bundle ###
OTHER_VAR=1
###< symfony/baz-bundle ###

###> symfony/foo-bundle ###
FOO_DSN=foo://default
###< symfony/foo-bundle ###

EOF
        ], $recipeUpdate->getNewFiles());
    }

    public function testConfigureForceKeepsExistingQuotedSecrets()
    {
        @mkdir(FLEX_TEST_DIR);
        $configurator = new EnvConfigurator(
            $this->createStub(Composer::class),
            $this->createStub(IOInterface::class),
            new Options(['root-dir' => FLEX_TEST_DIR])
        );

        $recipe = $this->createStub(Recipe::class);
        $recipe->method('getName')->willReturn('FooBundle');

        $env = FLEX_TEST_DIR.'/.env';
        $envContents = <<<'EOF'
###> FooBundle ###
#TRUSTED_SECRET="my \"trusted\" secret"
APP_SECRET="my secret"
###< FooBundle ###

EOF;
        file_put_contents($env, $envContents);

        $configurator->configure($recipe, [
            '#TRUSTED_SECRET' => '%generate(secret,32)%',
            'APP_SECRET' => '%generate(secret)%',
        ], $this->createStub(Lock::class), ['force' => true]);

        $this->assertSame($envContents, file_get_contents($env));

        @unlink($env);
    }
}
