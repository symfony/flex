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
    public function testResolve($packages, $resolved)
    {
        $this->assertEquals($resolved, $this->getResolver()->resolve($packages));
    }

    public function getPackages()
    {
        return [
            [
                ['cli'],
                ['symfony/console']
            ],
            [
                ['console', 'validator', 'translation'],
                ['symfony/console', 'symfony/validator', 'symfony/translation']
            ],
            [
                ['cli', 'lts', 'validator', '3.2', 'translation'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation']
            ],
            [
                ['cli:lts', 'validator=3.2', 'translation', 'next'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation:^4.0@dev']
            ],
            [
                ['php'],
                ['php']
            ],
            [
                ['ext-mongodb'],
                ['ext-mongodb']
            ],
        ];
    }

    /**
     * @dataProvider getWrongPackages
     */
    public function testResolveWithErrors($packages, $error)
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($error);
        $this->getResolver()->resolve($packages);
    }

    public function getWrongPackages()
    {
        return [
            [
                ['consale'],
                "\"consale\" is not a valid alias. Did you mean this:\n  \"symfony/console\", supported aliases: \"console\"",
            ],
            [
                ['cli', 'consale'],
                "\"consale\" is not a valid alias. Did you mean this:\n  \"symfony/console\", supported aliases: \"console\"",
            ],
            [
                ['cli', '2.3', 'consale'],
                "\"consale\" is not a valid alias. Did you mean this:\n  \"symfony/console\", supported aliases: \"console\"",
            ],

            [
                ['qwerty'],
                "\"qwerty\" is not a valid alias.",
            ],
        ];
    }

    private function getResolver()
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

        return $resolver;
    }
}
