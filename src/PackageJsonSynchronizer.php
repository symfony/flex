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

use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * Synchronize package.json files detected in installed PHP packages with
 * the current application.
 */
class PackageJsonSynchronizer
{
    private $rootDir;

    public function __construct(?string $rootDir)
    {
        $this->rootDir = $rootDir;
    }

    public function shouldSynchronize(): bool
    {
        return $this->rootDir && file_exists($this->rootDir.'/package.json');
    }

    public function synchronize(array $packagesNames)
    {
        // Remove all links and add again only the existing packages
        $this->removePackageJsonLinks((new JsonFile($this->rootDir.'/package.json'))->read());

        foreach ($packagesNames as $packageName) {
            $this->addPackageJsonLink($packageName);
        }

        $this->registerPeerDependencies($packagesNames);

        // Register controllers and entrypoints in controllers.json
        $this->registerWebpackResources($packagesNames);
    }

    private function removePackageJsonLinks(array $packageJson)
    {
        $jsDependencies = $packageJson['dependencies'] ?? [];
        $jsDevDependencies = $packageJson['devDependencies'] ?? [];

        foreach (['dependencies' => $jsDependencies, 'devDependencies' => $jsDevDependencies] as $key => $packages) {
            foreach ($packages as $name => $version) {
                if ('@' !== $name[0] || 0 !== strpos($version, 'file:')) {
                    continue;
                }

                $manipulator = new JsonManipulator(file_get_contents($this->rootDir.'/package.json'));
                $manipulator->removeSubNode('devDependencies', $name);
                file_put_contents($this->rootDir.'/package.json', $manipulator->getContents());
            }
        }
    }

    private function addPackageJsonLink(string $phpPackage)
    {
        if (!$assetsDir = $this->resolveAssetsDir($phpPackage)) {
            return;
        }

        $manipulator = new JsonManipulator(file_get_contents($this->rootDir.'/package.json'));
        $manipulator->addSubNode('devDependencies', '@'.$phpPackage, 'file:vendor/'.$phpPackage.$assetsDir);

        $content = json_decode($manipulator->getContents(), true);

        $devDependencies = $content['devDependencies'];
        uksort($devDependencies, 'strnatcmp');
        $manipulator->addMainKey('devDependencies', $devDependencies);

        file_put_contents($this->rootDir.'/package.json', $manipulator->getContents());
    }

    private function registerWebpackResources(array $phpPackages)
    {
        if (!file_exists($controllersJsonPath = $this->rootDir.'/assets/controllers.json')) {
            return;
        }

        $previousControllersJson = (new JsonFile($controllersJsonPath))->read();
        $newControllersJson = [
            'controllers' => [],
            'entrypoints' => $previousControllersJson['entrypoints'],
        ];

        foreach ($phpPackages as $phpPackage) {
            if (!$assetsDir = $this->resolveAssetsDir($phpPackage)) {
                continue;
            }

            // Register in config
            $packageJsonPath = $this->rootDir.'/vendor/'.$phpPackage.$assetsDir.'/package.json';
            $packageJson = (new JsonFile($packageJsonPath))->read();

            foreach ($packageJson['symfony']['controllers'] ?? [] as $controllerName => $defaultConfig) {
                // If the package has just been added (no config), add the default config provided by the package
                if (!isset($previousControllersJson['controllers']['@'.$phpPackage][$controllerName])) {
                    $config = [];
                    $config['enabled'] = $defaultConfig['enabled'];
                    $config['fetch'] = $defaultConfig['fetch'] ?? 'eager';

                    if (isset($defaultConfig['autoimport'])) {
                        $config['autoimport'] = $defaultConfig['autoimport'];
                    }

                    $newControllersJson['controllers']['@'.$phpPackage][$controllerName] = $config;

                    continue;
                }

                // Otherwise, the package exists: merge new config with uer config
                $previousConfig = $previousControllersJson['controllers']['@'.$phpPackage][$controllerName];

                $config = [];
                $config['enabled'] = $previousConfig['enabled'];
                $config['fetch'] = $previousConfig['fetch'] ?? 'eager';

                if (isset($defaultConfig['autoimport'])) {
                    $config['autoimport'] = [];

                    // Use for each autoimport either the previous config if one existed or the default config otherwise
                    foreach ($defaultConfig['autoimport'] as $autoimport => $enabled) {
                        $config['autoimport'][$autoimport] = $previousConfig['autoimport'][$autoimport] ?? $enabled;
                    }
                }

                $newControllersJson['controllers']['@'.$phpPackage][$controllerName] = $config;
            }

            foreach ($packageJson['symfony']['entrypoints'] ?? [] as $entrypoint => $filename) {
                if (!isset($newControllersJson['entrypoints'][$entrypoint])) {
                    $newControllersJson['entrypoints'][$entrypoint] = $filename;
                }
            }
        }

        file_put_contents($controllersJsonPath, json_encode($newControllersJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    public function registerPeerDependencies(array $phpPackages)
    {
        $peerDependencies = [];

        foreach ($phpPackages as $phpPackage) {
            if (!$assetsDir = $this->resolveAssetsDir($phpPackage)) {
                continue;
            }

            $packageJsonPath = $this->rootDir.'/vendor/'.$phpPackage.$assetsDir.'/package.json';
            $packageJson = (new JsonFile($packageJsonPath))->read();
            $versionParser = new VersionParser();

            foreach ($packageJson['peerDependencies'] ?? [] as $peerDependency => $constraint) {
                $peerDependencies[$peerDependency][$constraint] = $versionParser->parseConstraints($constraint);
            }
        }

        if (!$peerDependencies) {
            return;
        }

        $manipulator = new JsonManipulator(file_get_contents($this->rootDir.'/package.json'));
        $content = json_decode($manipulator->getContents(), true);
        $devDependencies = $content['devDependencies'] ?? [];

        foreach ($peerDependencies as $peerDependency => $constraints) {
            $devDependencies[$peerDependency] = $this->compactConstraints($constraints);
        }
        uksort($devDependencies, 'strnatcmp');
        $manipulator->addMainKey('devDependencies', $devDependencies);

        file_put_contents($this->rootDir.'/package.json', $manipulator->getContents());
    }

    private function resolveAssetsDir(string $phpPackage)
    {
        foreach (['/assets', '/Resources/assets'] as $subdir) {
            if (file_exists($this->rootDir.'/vendor/'.$phpPackage.$subdir.'/package.json')) {
                return $subdir;
            }
        }

        return null;
    }

    /**
     * @param ConstraintInterface[] $constraints
     */
    private function compactConstraints(array $constraints): string
    {
        if (method_exists(Intervals::class, 'isSubsetOf')) {
            foreach ($constraints as $k1 => $constraint1) {
                foreach ($constraints as $k2 => $constraint2) {
                    if ($k1 !== $k2 && Intervals::isSubsetOf($constraint1, $constraint2)) {
                        unset($constraints[$k2]);
                    }
                }
            }
        }

        uksort($constraints, 'strnatcmp');

        foreach ($constraints as $k => $constraint) {
            $constraints[$k] = \count($constraints) > 1 && false !== strpos($k, '|') ? '('.$k.')' : $k;
        }

        return implode(',', $constraints);
    }
}
