<?php

namespace Symfony\Flex\Configurator;

function getcwd()
{
    return sys_get_temp_dir();
}

namespace Symfony\Flex\Tests\Configurator;

use Symfony\Flex\Configurator\BundlesConfigurator;

class BundlesConfiguratorTest extends \PHPUnit_Framework_TestCase
{
    public function testConfigure()
    {
        $configurator = new BundlesConfigurator(
            $this->getMockBuilder('Composer\Composer')->getMock(),
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getMockBuilder('Symfony\Flex\Options')->getMock()
        );

        $recipe = $this->getMockBuilder('Symfony\Flex\Recipe')->disableOriginalConstructor()->getMock();

        $config = sys_get_temp_dir().'/conf/bundles.php';
        @unlink($config);
        $configurator->configure($recipe, array('FooBundle' => ['dev', 'test']));
        $this->assertEquals(<<<EOF
<?php

return [
    FooBundle => ["dev" => true, "test" => true],
];

EOF
        , file_get_contents($config));
    }
}
