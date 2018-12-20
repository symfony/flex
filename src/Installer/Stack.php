<?php

namespace Harmony\Flex\Installer;

/**
 * Class Stack
 *
 * @package Harmony\Flex\Installer
 */
class Stack extends BaseInstaller
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