<?php

namespace Harmony\Flex\Installer;

/**
 * Class Theme
 *
 * @package Harmony\Flex\Installer
 */
class Theme extends BaseInstaller
{

    /**
     * Returns install locations
     *
     * @return array
     */
    protected function getLocations(): array
    {
        return ['themes/{$name}/'];
    }

    /**
     * Success installed message.
     * Ask user to set as default theme
     */
    public function postInstall(): void
    {
        $prettyName = $this->package->getPrettyName();
        list(, $name) = explode('/', $prettyName);

        $this->io->success('Theme "' . $prettyName . '" successfully installed');

        if ($this->io->confirm('Set as default theme?', false)) {
            $this->configurator->update('theme', $name);
        }
    }
}