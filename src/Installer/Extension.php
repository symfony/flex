<?php

namespace Harmony\Flex\Installer;

/**
 * Class Extension
 *
 * @package Harmony\Flex\Installer
 */
class Extension extends BaseInstaller
{

    /**
     * Returns install locations
     *
     * @return array
     */
    protected function getLocations(): array
    {
        return [
            'block'     => 'extensions/blocks/${name}/',
            'component' => 'extensions/components/${name}/',
            'module'    => 'extensions/modules/${name}/',
            'plugin'    => 'extensions/plugins/${name}/',
            'widget'    => 'extensions/widgets/${name}/'
        ];
    }
}