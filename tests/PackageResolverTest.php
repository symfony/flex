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

use Symfony\Flex\PackageResolver;
use PHPUnit\Framework\TestCase;

class PackageResolverTest extends TestCase
{
    /**
     * @dataProvider getPackages
     */
    public function testResolve($packages, $resolved, $versionShouldBeResolved)
    {
        $downloader = $this->getMockBuilder('Symfony\Flex\Downloader')->disableOriginalConstructor()->getMock();

        $resolver = new PackageResolver($downloader);
        $p = new \ReflectionProperty($resolver, 'aliases');
        $p->setAccessible(true);
        $p->setValue($resolver, [
            'cli' => 'symfony/console',
            'console' => 'symfony/console',
            'translation' => 'symfony/translation',
            'validator' => 'symfony/validator',
        ]);
        $p = new \ReflectionProperty($resolver, 'versions');
        $p->setAccessible(true);
        $p->setValue($resolver, [
            'lts' => '3.4',
            'next' => '4.0',
            'splits' => [
                'symfony/console' => ['3.4'],
                'symfony/translation' => ['3.4'],
                'symfony/validator' => ['3.4'],
            ],
        ]);
        $this->assertEquals($resolved, $resolver->resolve($packages));
    }

    public function getPackages()
    {
        return [
            [
                ['cli'],
                ['symfony/console'],
                false,
            ],
            [
                ['console', 'validator', 'translation'],
                ['symfony/console', 'symfony/validator', 'symfony/translation'],
                true,
            ],
            [
                ['cli', 'lts', 'validator', '3.2', 'translation'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation'],
                true,
            ],
            [
                ['cli:lts', 'validator=3.2', 'translation', 'next'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation:^4.0@dev'],
                true,
            ],
        ];
    }
}
