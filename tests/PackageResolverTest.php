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
        static $downloader;

        if (null === $downloader) {
            $downloader = $this->getMockBuilder('Symfony\Flex\Downloader')->disableOriginalConstructor()->getMock();
            $downloader->expects($this->at(0))->method('getContents')->with('/aliases.json')->will($this->returnValue([
                'cli' => 'console',
                'console' => 'symfony/console',
                'translation' => 'symfony/translation',
                'validator' => 'symfony/validator',
            ]));
            $downloader->expects($this->at(1))->method('getContents')->with('/versions.json')->will($this->returnValue([
                'lts' => '3.4',
                'next' => '4.0',
                'splits' => [
                    'symfony/console' => ['3.4'],
                    'symfony/translation' => ['3.4'],
                    'symfony/validator' => ['3.4'],
                ],
            ]));
        }

        $resolver = new PackageResolver($downloader);
        $this->assertEquals($resolved, $resolver->resolve($packages));
    }

    public function getPackages()
    {
        return [
            [
                ['cli'],
                ['symfony/console'],
            ],
            [
                ['cli', 'validator', 'translation'],
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
        ];
    }
}
