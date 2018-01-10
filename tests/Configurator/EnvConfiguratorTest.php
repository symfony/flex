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

use Symfony\Flex\Configurator\EnvConfigurator;
use Symfony\Flex\Options;
use PHPUnit\Framework\TestCase;

class EnvConfiguratorTest extends TestCase
{
    public function testConfigure()
    {
        $configurator = new EnvConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options()
        );

        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();
        $recipe->expects($this->any())->method('getName')->will($this->returnValue('FooBundle'));

        $env = sys_get_temp_dir().'/.env.dist';
        @unlink($env);
        touch($env);

        $phpunit = sys_get_temp_dir().'/phpunit.xml';
        $phpunitDist = $phpunit.'.dist';
        @unlink($phpunit, $phpunitDist);
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
        ]);

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
        ]);

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
        ]);

        $this->assertStringEqualsFile(
            $env,
            <<<EOF


EOF
        );

        $this->assertFileEquals(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunitDist);
        $this->assertFileEquals(__DIR__.'/../Fixtures/phpunit.xml.dist', $phpunit);

        @unlink($phpunit, $env);
    }
}
