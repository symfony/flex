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

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Seld\JsonLint\ParsingException;

/**
 * Synchronize package.json files detected in installed PHP packages with
 * the current application.
 */
class PackageJsonSynchronizer
{
    private $rootDir;
    private $vendorDir;
    private $scriptExecutor;
    private $io;
    private $versionParser;

    public function __construct(string $rootDir, string $vendorDir, ScriptExecutor $scriptExecutor, IOInterface $io)
    {
        $this->rootDir = $rootDir;
        $this->vendorDir = $vendorDir;
        $this->scriptExecutor = $scriptExecutor;
        $this->io = $io;
        $this->versionParser = new VersionParser();
    }

    public function shouldSynchronize(): bool
    {
        return $this->rootDir && (file_exists($this->rootDir.'/package.json') || file_exists($this->rootDir.'/importmap.php'));
    }

    public function synchronize(array $phpPackages): bool
    {
        if (file_exists($this->rootDir.'/importmap.php')) {
            $this->synchronizeForAssetMapper($phpPackages);

            return false;
        }

        try {
            JsonFile::parseJson(file_get_contents($this->rootDir.'/package.json'));
        } catch (ParsingException $e) {
            // if package.json is invalid (possible during a recipe upgrade), we can't update the file
            return false;
        }

        $didChangePackageJson = $this->removeObsoletePackageJsonLinks();

        $dependencies = [];

        $phpPackages = $this->normalizePhpPackages($phpPackages);
        foreach ($phpPackages as $phpPackage) {
            foreach ($this->resolvePackageJsonDependencies($phpPackage) as $dependency => $constraint) {
                $dependencies[$dependency][$phpPackage['name']] = $constraint;
            }
        }

        $didChangePackageJson = $this->registerDependenciesInPackageJson($dependencies) || $didChangePackageJson;

        // Register controllers and entrypoints in controllers.json
        $this->updateControllersJsonFile($phpPackages);

        return $didChangePackageJson;
    }

    private function synchronizeForAssetMapper(array $phpPackages): void
    {
        $importMapEntries = [];
        $phpPackages = $this->normalizePhpPackages($phpPackages);
        foreach ($phpPackages as $phpPackage) {
            foreach ($this->resolveImportMapPackages($phpPackage) as $name => $dependencyConfig) {
                $importMapEntries[$name] = $dependencyConfig;
            }
        }

        $this->updateImportMap($importMapEntries);
        $this->updateControllersJsonFile($phpPackages);
    }

    private function removeObsoletePackageJsonLinks(): bool
    {
        $didChangePackageJson = false;

        $manipulator = new JsonManipulator(file_get_contents($this->rootDir.'/package.json'));
        $content = json_decode($manipulator->getContents(), true);

        $jsDependencies = $content['dependencies'] ?? [];
        $jsDevDependencies = $content['devDependencies'] ?? [];

        foreach (['dependencies' => $jsDependencies, 'devDependencies' => $jsDevDependencies] as $key => $packages) {
            foreach ($packages as $name => $version) {
                if ('@' !== $name[0] || !str_starts_with($version, 'file:'.$this->vendorDir.'/') || !str_contains($version, '/assets')) {
                    continue;
                }
                if (file_exists($this->rootDir.'/'.substr($version, 5).'/package.json')) {
                    continue;
                }

                $manipulator->removeSubNode($key, $name);
                $didChangePackageJson = true;
            }
        }

        file_put_contents($this->rootDir.'/package.json', $manipulator->getContents());

        return $didChangePackageJson;
    }

    private function resolvePackageJsonDependencies($phpPackage): array
    {
        $dependencies = [];

        if (!$packageJson = $this->resolvePackageJson($phpPackage)) {
            return $dependencies;
        }

        if ($packageJson->read()['symfony']['needsPackageAsADependency'] ?? true) {
            $dependencies['@'.$phpPackage['name']] = 'file:'.substr($packageJson->getPath(), 1 + \strlen($this->rootDir), -13);
        }

        foreach ($packageJson->read()['peerDependencies'] ?? [] as $peerDependency => $constraint) {
            $dependencies[$peerDependency] = $constraint;
        }

        return $dependencies;
    }

    private function resolveImportMapPackages($phpPackage): array
    {
        if (!$packageJson = $this->resolvePackageJson($phpPackage)) {
            return [];
        }

        $dependencies = [];

        foreach ($packageJson->read()['symfony']['importmap'] ?? [] as $importMapName => $constraintConfig) {
            if (\is_string($constraintConfig)) {
                // Matches string constraint, like "^3.0" or "path:%PACKAGE%/script.js"
                $constraint = $constraintConfig;
                $package = $importMapName;
                $entrypoint = false;
            } elseif (\is_array($constraintConfig)) {
                // Matches array constraint, like {"version":"^3.0"} or {"version":"path:%PACKAGE%/script.js","entrypoint":true}
                // Note that non-path assets can't be entrypoint
                $constraint = $constraintConfig['version'] ?? '';
                $package = $constraintConfig['package'] ?? $importMapName;
                $entrypoint = $constraintConfig['entrypoint'] ?? false;
            } else {
                throw new \InvalidArgumentException(\sprintf('Invalid constraint config for key "%s": "%s" given, array or string expected.', $importMapName, var_export($constraintConfig, true)));
            }

            // When "$constraintConfig" matches one of the following cases:
            // - "entrypoint:%PACKAGE%/script.js"
            // - {"version": "entrypoint:%PACKAGE%/script.js"}
            if (str_starts_with($constraint, 'entrypoint:')) {
                $entrypoint = true;
                $constraint = substr_replace($constraint, 'path:', 0, \strlen('entrypoint:'));
            }

            if (str_starts_with($constraint, 'path:')) {
                $path = substr($constraint, 5);
                $path = str_replace('%PACKAGE%', \dirname($packageJson->getPath()), $path);

                $dependencies[$importMapName] = [
                    'path' => $path,
                    'entrypoint' => $entrypoint,
                ];

                continue;
            }

            $dependencies[$importMapName] = [
                'version' => $constraint,
                'package' => $package,
            ];
        }

        return $dependencies;
    }

    private function registerDependenciesInPackageJson(array $flexDependencies): bool
    {
        $didChangePackageJson = false;

        $manipulator = new JsonManipulator(file_get_contents($this->rootDir.'/package.json'));
        $content = json_decode($manipulator->getContents(), true);

        foreach ($flexDependencies as $dependency => $constraints) {
            if (1 !== \count($constraints) && 1 !== \count(array_count_values($constraints))) {
                // If the flex packages have a colliding peer dependency, leave the resolution to the user
                continue;
            }

            $constraint = array_shift($constraints);

            $parentNode = isset($content['dependencies'][$dependency]) ? 'dependencies' : 'devDependencies';
            if (!isset($content[$parentNode][$dependency])) {
                $content['devDependencies'][$dependency] = $constraint;
                $didChangePackageJson = true;
            } elseif ($constraint !== $content[$parentNode][$dependency]) {
                if ($this->shouldUpdateConstraint($content[$parentNode][$dependency], $constraint)) {
                    $content[$parentNode][$dependency] = $constraint;
                    $didChangePackageJson = true;
                }
            }
        }

        if ($didChangePackageJson) {
            if (isset($content['dependencies'])) {
                $manipulator->addMainKey('dependencies', $content['dependencies']);
            }

            if (isset($content['devDependencies'])) {
                $devDependencies = $content['devDependencies'];
                uksort($devDependencies, 'strnatcmp');
                $manipulator->addMainKey('devDependencies', $devDependencies);
            }

            $newContents = $manipulator->getContents();
            if ($newContents === file_get_contents($this->rootDir.'/package.json')) {
                return false;
            }

            file_put_contents($this->rootDir.'/package.json', $manipulator->getContents());
        }

        return $didChangePackageJson;
    }

    private function shouldUpdateConstraint(string $existingConstraint, string $constraint)
    {
        try {
            $existingConstraint = $this->versionParser->parseConstraints($existingConstraint);
            $constraint = $this->versionParser->parseConstraints($constraint);

            return !$existingConstraint->matches($constraint);
        } catch (\UnexpectedValueException $e) {
            return true;
        }
    }

    /**
     * @param array<string, array{path?: string, package?: string, version?: string, entrypoint?: bool}> $importMapEntries
     */
    private function updateImportMap(array $importMapEntries): void
    {
        if (!$importMapEntries) {
            return;
        }

        $importMapData = include $this->rootDir.'/importmap.php';

        foreach ($importMapEntries as $name => $importMapEntry) {
            if (isset($importMapData[$name])) {
                if (!isset($importMapData[$name]['version'])) {
                    // AssetMapper 6.3
                    continue;
                }

                $version = $importMapData[$name]['version'];
                $versionConstraint = $importMapEntry['version'] ?? null;

                // if the version constraint is satisfied, skip - else, update the package
                if (Semver::satisfies($version, $versionConstraint)) {
                    continue;
                }

                $this->io->writeError(\sprintf('Updating package <comment>%s</> from <info>%s</> to <info>%s</>.', $name, $version, $versionConstraint));
            }

            if (isset($importMapEntry['path'])) {
                $arguments = [$name, '--path='.$importMapEntry['path']];
                if (isset($importMapEntry['entrypoint']) && true === $importMapEntry['entrypoint']) {
                    $arguments[] = '--entrypoint';
                }

                $this->scriptExecutor->execute(
                    'symfony-cmd',
                    'importmap:require',
                    $arguments
                );

                continue;
            }

            if (isset($importMapEntry['version'])) {
                $packageName = $importMapEntry['package'].'@'.$importMapEntry['version'];
                if ($importMapEntry['package'] !== $name) {
                    $packageName .= '='.$name;
                }
                $arguments = [$packageName];
                $this->scriptExecutor->execute(
                    'symfony-cmd',
                    'importmap:require',
                    $arguments
                );

                continue;
            }

            throw new \InvalidArgumentException(\sprintf('Invalid importmap entry: "%s".', var_export($importMapEntry, true)));
        }
    }

    private function updateControllersJsonFile(array $phpPackages)
    {
        if (!file_exists($controllersJsonPath = $this->rootDir.'/assets/controllers.json')) {
            return;
        }

        try {
            $previousControllersJson = (new JsonFile($controllersJsonPath))->read();
        } catch (ParsingException $e) {
            // if controllers.json is invalid (possible during a recipe upgrade), we can't update the file
            return;
        }
        $newControllersJson = [
            'controllers' => [],
            'entrypoints' => $previousControllersJson['entrypoints'],
        ];

        foreach ($phpPackages as $phpPackage) {
            if (!$packageJson = $this->resolvePackageJson($phpPackage)) {
                continue;
            }
            $name = '@'.$phpPackage['name'];

            foreach ($packageJson->read()['symfony']['controllers'] ?? [] as $controllerName => $defaultConfig) {
                // If the package has just been added (no config), add the default config provided by the package
                if (!isset($previousControllersJson['controllers'][$name][$controllerName])) {
                    $config = [];
                    $config['enabled'] = $defaultConfig['enabled'];
                    $config['fetch'] = $defaultConfig['fetch'] ?? 'eager';

                    if (isset($defaultConfig['autoimport'])) {
                        $config['autoimport'] = $defaultConfig['autoimport'];
                    }

                    $newControllersJson['controllers'][$name][$controllerName] = $config;

                    continue;
                }

                // Otherwise, the package exists: merge new config with user config
                $previousConfig = $previousControllersJson['controllers'][$name][$controllerName];

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

                $newControllersJson['controllers'][$name][$controllerName] = $config;
            }

            foreach ($packageJson->read()['symfony']['entrypoints'] ?? [] as $entrypoint => $filename) {
                if (!isset($newControllersJson['entrypoints'][$entrypoint])) {
                    $newControllersJson['entrypoints'][$entrypoint] = $filename;
                }
            }
        }

        file_put_contents($controllersJsonPath, json_encode($newControllersJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    private function resolvePackageJson(array $phpPackage): ?JsonFile
    {
        $packageDir = $this->rootDir.'/'.$this->vendorDir.'/'.$phpPackage['name'];

        if (!\in_array('symfony-ux', $phpPackage['keywords'] ?? [], true)) {
            return null;
        }

        foreach (['/assets', '/Resources/assets', '/src/Resources/assets'] as $subdir) {
            $packageJsonPath = $packageDir.$subdir.'/package.json';

            if (!file_exists($packageJsonPath)) {
                continue;
            }

            return new JsonFile($packageJsonPath);
        }

        return null;
    }

    private function normalizePhpPackages(array $phpPackages): array
    {
        foreach ($phpPackages as $k => $phpPackage) {
            if (\is_string($phpPackage)) {
                // support for smooth upgrades from older flex versions
                $phpPackages[$k] = $phpPackage = [
                    'name' => $phpPackage,
                    'keywords' => ['symfony-ux'],
                ];
            }
        }

        return $phpPackages;
    }
}
