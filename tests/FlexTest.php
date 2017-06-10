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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\NullIO;
use Composer\Package\Package;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\Command\RequireCommand;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Response;

class FlexTest extends TestCase
{
    public function testConfigurePackageExpandsTargetDirsInPostInstallOutput()
    {
        $package = new Package('dummy/dummy', '1.0.0', '1.0.0');

        $event = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn(new InstallOperation($package));

        $configurator = $this
            ->getMockBuilder(Configurator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $configurator
            ->expects($this->once())
            ->method('install')
            ->with($this->equalTo(new Recipe($package, 'dummy/dummy', $data = ['manifest' => ['post-install-output' => ['line 1 %ETC_DIR%', 'line 2 %VAR_DIR%']]], '')));

        $downloader = $this
            ->getMockBuilder(Downloader::class)
            ->disableOriginalConstructor()
            ->getMock();
        $downloader
            ->expects($this->once())
            ->method('get')
            ->with('/m/dummy/dummy/1.0.0', ['Package-Operation: install'])
            ->willReturn(new Response($data));

        $flex = \Closure::bind(function () use ($configurator, $downloader) {
            $flex = new Flex();
            $flex->composer = new Composer();
            $flex->io = new NullIO();
            $flex->configurator = $configurator;
            $flex->downloader = $downloader;
            $flex->runningCommand = function() {};
            $flex->options = new Options(['etc-dir' => 'etc', 'var-dir' => 'var']);

            return $flex;
        }, null, Flex::class)->__invoke();
        $flex->configurePackage($event);
        $postInstallOutput = \Closure::bind(function () { return $this->postInstallOutput; }, $flex, Flex::class)->__invoke();

        $this->assertSame(['', 'line 1 etc', 'line 2 var', ''], $postInstallOutput);
    }
}
