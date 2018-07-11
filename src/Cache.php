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

use Composer\Cache as BaseCache;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Cache extends BaseCache
{
    private static $lowestTags = [
        'symfony/symfony' => 'v3.4.0',
    ];

    public function read($file)
    {
        $content = parent::read($file);

        if (0 === strpos($file, 'provider-symfony$')) {
            $content = json_encode($this->removeLegacyTags(json_decode($content, true)));
        }

        return $content;
    }

    public function removeLegacyTags(array $data): array
    {
        foreach (self::$lowestTags as $package => $lowestVersion) {
            if (!isset($data['packages'][$package][$lowestVersion])) {
                continue;
            }
            foreach ($data['packages'] as $package => $versions) {
                foreach ($versions as $version => $composerJson) {
                    if (version_compare($version, $lowestVersion, '<')) {
                        unset($data['packages'][$package][$version]);
                    }
                }
            }
            break;
        }

        return $data;
    }
}
