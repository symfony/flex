<?php

namespace Harmony\Flex\Installer;

/**
 * Class Package
 *
 * @package Harmony\Flex\Installer
 */
class Package extends BaseInstaller
{

    /**
     * Returns install locations
     *
     * @return array
     */
    protected function getLocations(): array
    {
        return ['vendor/{$name}/'];
    }
}