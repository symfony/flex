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
use Composer\Package\RootPackage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Configurator\AbstractConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;

abstract class ConfiguratorTest extends TestCase
{
    protected AbstractConfigurator $configurator;

    /** @var Composer&MockObject */
    protected Composer $composer;

    /** @var IOInterface&MockObject */
    protected IOInterface $io;

    protected RootPackage $package;

    protected string $originalEnvComposer;

    abstract protected function createConfigurator(): AbstractConfigurator;

    protected function setUp(): void
    {
        $this->originalEnvComposer = $_SERVER['COMPOSER'] ?? null;
        $_SERVER['COMPOSER'] = FLEX_TEST_DIR.'/composer.json';
        // composer 2.1 and lower support
        putenv('COMPOSER='.FLEX_TEST_DIR.'/composer.json');

        $this->package = new RootPackage('dummy/dummy', '1.0.0', '1.0.0');

        $this->composer = $this->getMockBuilder(Composer::class)->getMock();
        $this->composer->method('getPackage')->willReturn($this->package);

        $this->io = $this->getMockBuilder(IOInterface::class)->getMock();

        $this->configurator = $this->createConfigurator();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->originalEnvComposer) {
            $_SERVER['COMPOSER'] = $this->originalEnvComposer;
        } else {
            unset($_SERVER['COMPOSER']);
        }
        // composer 2.1 and lower support
        putenv('COMPOSER='.$this->originalEnvComposer);
    }

    protected function sampleConfig()
    {
        return 'string-config';
    }

    public function testNotConfiguredIfDisabledByPreference(): void
    {
        $this->package->setExtra(['symfony' => [$this->configurator->configureKey() => false]]);

        self::assertFalse(
            $this->configurator->configure(
                $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock(),
                $this->sampleConfig(),
                $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock()
            )
        );
    }

    /**
     * @dataProvider provideConfiguratorSupportPreference
     */
    public function testPreferenceAskedInteractively(
        string $answer,
        bool $expectedIsConfigured,
        bool $expectedIsComposerJsonUpdated
    ): void {
        if ($this->configurator->isEnabledByDefault()) {
            $this->markTestSkipped('Skipped as configurators enabled by default do not ask for support');
        }

        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';
        file_put_contents($composerJsonPath, json_encode(['name' => 'test/app']));

        $this->package->setExtra(['symfony' => []]);

        $recipeDb = $this->getMockBuilder(Recipe::class)->disableOriginalConstructor()->getMock();
        $recipeDb->method('getJob')->willReturn('install');

        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())->method('askAndValidate')->willReturn($answer);

        self::assertSame(
            $expectedIsConfigured,
            $this->configurator->configure(
                $recipeDb,
                [],
                $this->getMockBuilder(Lock::class)->disableOriginalConstructor()->getMock()
            )
        );

        $composerJsonData = json_decode(file_get_contents($composerJsonPath), true);
        if ($expectedIsComposerJsonUpdated) {
            $this->assertArrayHasKey('extra', $composerJsonData);
            $this->assertSame($expectedIsConfigured, $composerJsonData['extra']['symfony'][$this->configurator->configureKey()]);
        } else {
            $this->assertArrayNotHasKey('extra', $composerJsonData);
        }
    }

    public function provideConfiguratorSupportPreference(): \Generator
    {
        yield 'yes_once' => ['y', true, false];
        yield 'no_once' => ['n', false, false];
        yield 'yes_forever' => ['p', true, true];
        yield 'no_forever' => ['x', false, true];
    }
}
