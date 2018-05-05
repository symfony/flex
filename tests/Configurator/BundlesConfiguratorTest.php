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

use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\BundlesConfigurator;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class BundlesConfiguratorTest extends TestCase
{
    public function testConfigure()
    {
        $config = sys_get_temp_dir().'/config/bundles.php';

        $configurator = new BundlesConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Options(['config-dir' => dirname($config)])
        );

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();

        @unlink($config);
        $configurator->configure($recipe, [
            'FooBundle' => ['dev', 'test'],
            'Symfony\Bundle\FrameworkBundle\FrameworkBundle' => ['all'],
        ]);
        $this->assertEquals(<<<EOF
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    FooBundle::class => ['dev' => true, 'test' => true],
];

EOF
        , file_get_contents($config));
    }
}
