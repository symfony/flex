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

        $this->assertStringEqualsFile($env, $envContents);

        $configurator->configure($recipe, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            '#1' => 'Comment 1',
            '#2' => 'Comment 3',
            '#TRUSTED_SECRET' => 's3cretf0rt3st',
            'APP_SECRET' => 's3cretf0rt3st',
        ]);

        $this->assertStringEqualsFile($env, $envContents);

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

        @unlink($env);
    }
}
