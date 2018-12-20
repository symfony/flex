<?php

namespace Harmony\Flex;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Harmony\Flex\Installer\{BaseInstaller, Extension, Package, Stack, Theme};

/**
 * Class Installer
 *
 * @package Harmony\Flex
 */
class Installer extends LibraryInstaller
{

    /** Constant */
    const PREFIX = 'harmony-';

    /** @var array $supports */
    protected $supports
        = [
            self::PREFIX . 'extension' => Extension::class,
            self::PREFIX . 'package'   => Package::class,
            self::PREFIX . 'stack'     => Stack::class,
            self::PREFIX . 'theme'     => Theme::class,
        ];

    /**
     * Decides if the installer supports the given type
     *
     * @param  string $packageType
     *
     * @return bool
     */
    public function supports($packageType): bool
    {
        return array_key_exists($packageType, $this->supports);
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     *
     * @return string           path
     */
    public function getInstallPath(PackageInterface $package): string
    {
        $type = $package->getType();
        if (false === $this->supports($type)) {
            throw new \InvalidArgumentException('Sorry the package type of this package is not supported.');
        }

        $class = $this->supports[$type];
        /** @var BaseInstaller $installer */
        $installer = new $class($package, $this->composer, $this->io);

        return $installer->getInstallPath();
    }

    /**
     * Uninstalls specific package.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        parent::uninstall($repo, $package);
        $installPath = $this->getPackageBasePath($package);
        $this->io->write(sprintf('Deleting %s - %s', $installPath,
            !file_exists($installPath) ? '<comment>deleted</comment>' : '<error>not deleted</error>'));
    }

    /**
     * Execute method after package installed
     *
     * @param PackageInterface $package
     */
    public function postInstall(PackageInterface $package): void
    {
        $type = $package->getType();
        if (false === $this->supports($type)) {
            throw new \InvalidArgumentException('Sorry the package type of this package is not supported.');
        }

        $class = $this->supports[$type];
        /** @var BaseInstaller $installer */
        $installer = new $class($package, $this->composer, $this->io);
        $installer->postInstall();
    }
}