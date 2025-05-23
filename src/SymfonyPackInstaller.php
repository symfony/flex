<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Installer\MetapackageInstaller;

class SymfonyPackInstaller extends MetapackageInstaller
{
    public function supports($packageType): bool
    {
        return 'symfony-pack' === $packageType;
    }
}
