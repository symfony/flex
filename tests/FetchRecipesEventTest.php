<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests;

use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\FetchRecipesEvent;

class FetchRecipesEventTest extends TestCase
{
    public function testAddManifest()
    {
        $event = new FetchRecipesEvent('event.name', [], []);
        $this->assertSame([], $event->getOperations());
        $this->assertFalse($event->hasManifest('foo/bar'));
        $this->assertSame([], $event->getManifest('foo/bar'));

        $package = $this->getMockBuilder(PackageInterface::class)->getMock();
        $package->expects($this->once())->method('getName')->willReturn('foo/bar');
        $package->expects($this->once())->method('getPrettyVersion')->willReturn('1.0.0');

        $event->addManifest($package, ['manifest' => ['configurator' => 'action']]);
        $this->assertTrue($event->hasManifest('foo/bar'));

        $expected = [
            'manifest' => ['configurator' => 'action'],
            'is_contrib' => true,
            'origin' => 'foo/bar:1.0.0@composer-plugin recipe',
        ];
        $this->assertSame($expected, $event->getManifest('foo/bar'));
        $this->assertSame(['foo/bar' => $expected], $event->getManifests());
    }
}
