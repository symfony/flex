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

use Symfony\Flex\Configurator\BundlesConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class BundlesConfiguratorTest extends ConfiguratorTest
{
    protected function createConfigurator(): BundlesConfigurator
    {
        return new BundlesConfigurator(
            $this->composer,
            $this->io,
            new Options(['config-dir' => 'config', 'root-dir' => FLEX_TEST_DIR])
        );
    }

    public function testConfigure()
    {
        $config = FLEX_TEST_DIR.'/config/bundles.php';

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        @unlink($config);
        $this->configurator->configure($recipe, [
            'FooBundle' => ['dev', 'test'],
            'Symfony\Bundle\FrameworkBundle\FrameworkBundle' => ['all'],
        ], $lock);
        $this->assertEquals(<<<EOF
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    FooBundle::class => ['dev' => true, 'test' => true],
];

EOF
        , file_get_contents($config));
    }

    public function testConfigureWhenBundlesAlreadyExists()
    {
        $this->saveBundlesFile(<<<EOF
<?php

return [
    BarBundle::class => ['prod' => false, 'all' => true],
];
EOF
        );

        $recipe = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $lock = $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock();

        $this->configurator->configure($recipe, [
            'FooBundle' => ['dev', 'test'],
            'Symfony\Bundle\FrameworkBundle\FrameworkBundle' => ['all'],
        ], $lock);
        $this->assertEquals(<<<EOF
<?php

return [
    BarBundle::class => ['prod' => false, 'all' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    FooBundle::class => ['dev' => true, 'test' => true],
];

EOF
        , file_get_contents(FLEX_TEST_DIR.'/config/bundles.php'));
    }

    public function testUnconfigure()
    {
        $this->saveBundlesFile(<<<EOF
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    BarBundle::class => ['prod' => false, 'all' => true],
    OtherBundle::class => ['all' => true],
];
EOF
        );

        $recipe = $this->createMock(Recipe::class);
        $lock = $this->createMock(Lock::class);

        $this->configurator->unconfigure($recipe, [
            'BarBundle' => ['dev', 'all'],
        ], $lock);
        $this->assertEquals(<<<EOF
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    OtherBundle::class => ['all' => true],
];

EOF
        , file_get_contents(FLEX_TEST_DIR.'/config/bundles.php'));
    }

    public function testUpdate()
    {
        $recipeUpdate = new RecipeUpdate(
            $this->createMock(Recipe::class),
            $this->createMock(Recipe::class),
            $this->createMock(Lock::class),
            FLEX_TEST_DIR
        );

        $this->saveBundlesFile(<<<EOF
<?php

return [
    BarBundle::class => ['prod' => false, 'all' => true],
    FooBundle::class => ['dev' => true, 'test' => true],
    BazBundle::class => ['all' => true],
];
EOF
        );

        $this->configurator->update(
            $recipeUpdate,
            ['FooBundle' => ['dev', 'test']],
            ['FooBundle' => ['all'], 'NewBundle' => ['all']]
        );

        $this->assertSame(['config/bundles.php' => <<<EOF
<?php

return [
    BarBundle::class => ['prod' => false, 'all' => true],
    FooBundle::class => ['dev' => true, 'test' => true],
    BazBundle::class => ['all' => true],
];

EOF
        ], $recipeUpdate->getOriginalFiles());

        // FooBundle::class => ['dev' => true, 'test' => true]: configured envs should not be overwritten
        $this->assertSame(['config/bundles.php' => <<<EOF
<?php

return [
    BarBundle::class => ['prod' => false, 'all' => true],
    FooBundle::class => ['all' => true],
    BazBundle::class => ['all' => true],
    NewBundle::class => ['all' => true],
];

EOF
        ], $recipeUpdate->getNewFiles());
    }

    private function saveBundlesFile(string $contents)
    {
        $config = FLEX_TEST_DIR.'/config/bundles.php';
        if (!file_exists(\dirname($config))) {
            @mkdir(\dirname($config), 0777, true);
        }
        file_put_contents($config, $contents);
    }
}
