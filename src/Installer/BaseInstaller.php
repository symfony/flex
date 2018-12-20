<?php

namespace Harmony\Flex\Installer;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Harmony\Flex\Configurator;
use Harmony\Flex\IO\ConsoleIO;

/**
 * Class BaseInstaller
 *
 * @package Harmony\Flex\Installer
 */
abstract class BaseInstaller
{

    /** @var array $locations */
    protected $locations = [];

    /** @var PackageInterface $package */
    protected $package;

    /** @var Composer $composer */
    protected $composer;

    /** @var ConsoleIO $io */
    protected $io;

    /** @var Configurator $configurator */
    protected $configurator;

    /**
     * BaseInstaller constructor.
     *
     * @param PackageInterface $package
     * @param Composer         $composer
     * @param ConsoleIO        $io
     */
    public function __construct(PackageInterface $package, Composer $composer, ConsoleIO $io)
    {
        $this->package      = $package;
        $this->composer     = $composer;
        $this->io           = $io;
        $this->configurator = new Configurator($composer);
    }

    /**
     * Returns install locations
     *
     * @return array
     */
    abstract protected function getLocations(): array;

    /**
     * Return the install path based on package type.
     *
     * @return string
     */
    public function getInstallPath(): string
    {
        $type = $this->package->getType();

        $vendor = '';
        $name   = $prettyName = $this->package->getPrettyName();
        if (strpos($prettyName, '/') !== false) {
            list($vendor, $name) = explode('/', $prettyName);
        }

        $availableVars = $this->inflectPackageVars(compact('name', 'vendor', 'type'));

        $extra = $this->package->getExtra();
        if (!empty($extra['installer-name'])) {
            $availableVars['name'] = $extra['installer-name'];
        }

        // TODO: Update code, installing extensions will not works properly
        return $this->templatePath($this->getLocations()[0], $availableVars);
    }

    /**
     * For an installer to override to modify the vars per installer.
     *
     * @param  array $vars
     *
     * @return array
     */
    public function inflectPackageVars(array $vars): array
    {
        return $vars;
    }

    /**
     * Execute method after package installed
     */
    public function postInstall(): void
    {
    }

    /**
     * Replace vars in a path
     *
     * @param  string $path
     * @param  array  $vars
     *
     * @return string
     */
    protected function templatePath($path, array $vars = [])
    {
        if (strpos($path, '{') !== false) {
            extract($vars);
            preg_match_all('@\{\$([A-Za-z0-9_]*)\}@i', $path, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $var) {
                    $path = str_replace('{$' . $var . '}', $$var, $path);
                }
            }
        }

        return $path;
    }
}