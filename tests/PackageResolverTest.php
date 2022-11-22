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

use PHPUnit\Framework\TestCase;
use Symfony\Flex\Downloader;
use Symfony\Flex\PackageResolver;

class PackageResolverTest extends TestCase
{
    /**
     * @dataProvider getPackages
     */
    public function testResolve($packages, $resolved, bool $isRequire = false)
    {
        $this->assertEquals($resolved, $this->getResolver()->resolve($packages, $isRequire));
    }

    public function getPackages()
    {
        return [
            [
                ['cli'],
                ['symfony/console'],
            ],
            [
                ['lock'],
                ['lock'],
                false,
            ],
            [
                ['lock'],
                ['symfony/lock'],
                true,
            ],
            [
                ['cli', 'symfony/workflow'],
                ['symfony/console', 'symfony/workflow'],
            ],
            [
                ['console', 'validator', 'translation'],
                ['symfony/console', 'symfony/validator', 'symfony/translation'],
            ],
            [
                ['cli', 'lts', 'validator', '3.2', 'translation'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation'],
            ],
            [
                ['cli:lts', 'validator=3.2', 'translation', 'next'],
                ['symfony/console:^3.4', 'symfony/validator:3.2', 'symfony/translation:^4.0@dev'],
            ],
            [
                ['cli:dev-feature/abc'],
                ['symfony/console:dev-feature/abc'],
            ],
            [
                ['php'],
                ['php'],
            ],
            [
                ['ext-mongodb'],
                ['ext-mongodb'],
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
                '"qwerty" is not a valid alias.',
            ],
        ];
    }

    private function getResolver()
    {
        $downloader = $this->getMockBuilder(Downloader::class)->disableOriginalConstructor()->getMock();
        $downloader->expects($this->any())
            ->method('getVersions')
            ->willReturn([
                'lts' => '3.4',
                'next' => '4.0',
                'splits' => [
                    'symfony/console' => ['3.4'],
                    'symfony/translation' => ['3.4'],
                    'symfony/validator' => ['3.4'],
                ],
            ]);
        $downloader->expects($this->any())
            ->method('getAliases')
            ->willReturn([
                'cli' => 'symfony/console',
                'console' => 'symfony/console',
                'translation' => 'symfony/translation',
                'validator' => 'symfony/validator',
                'lock' => 'symfony/lock',
            ]);

        return new PackageResolver($downloader);
    }
}
