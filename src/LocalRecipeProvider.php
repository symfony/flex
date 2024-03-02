<?php

namespace Symfony\Flex;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Json\JsonFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LocalRecipeProvider implements RecipeProviderInterface
{
    private bool $enabled = true;
    private Composer $composer;

    private array $versions = [];

    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * {@inheritDoc}
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * {@inheritDoc}
     */
    public function getVersions(): array
    {
        return $this->versions;
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getRecipes(array $operations): array
    {
        $data = [];
        foreach ($operations as $operation) {
            $package = $operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage();

            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            $jsonFile = new JsonFile($installPath.\DIRECTORY_SEPARATOR.'manifest.json');
            if ($jsonFile->exists()) {
                $manifest = $jsonFile->read();
                if (isset($manifest['manifest']['copy-from-recipe'])) {
                    $copyFolders = array_keys($manifest['manifest']['copy-from-recipe']);
                    foreach ($copyFolders as $folder) {
                        $folderPattern = $installPath.\DIRECTORY_SEPARATOR.$folder;
                        $dir_iterator = new RecursiveDirectoryIterator($folderPattern);
                        $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
                        /** @var SplFileInfo $file */
                        foreach ($iterator as $file) {
                            if (is_file($file->getPathname())) {
                                $manifest['files'][str_replace($installPath.\DIRECTORY_SEPARATOR, '', $file->getPathname())] = [
                                    'contents' => file_get_contents($file->getPathname()),
                                    'executable' => is_executable($file->getPathname()), ];
                            }
                        }
                    }
                }
                $manifest['origin'] = $installPath;
                $manifest['is_contrib'] = false;
                $manifest['version'] = $package->getVersion();
                $manifest['package'] = $package->getName();
                $data['manifests'][$package->getName()] = $manifest;
                $data['locks'][$package->getName()]['recipe']['ref'] = $manifest['ref'];
                $data['locks'][$package->getName()]['version'] = $package->getVersion();

                if (!isset($this->versions[$package->getName()])) {
                    $this->versions[$package->getName()] = [];
                }
                $this->versions[$package->getName()][] = $package->getVersion();
            }
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function removeRecipeFromIndex(string $packageName, string $version): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getSessionId(): string
    {
        return 'none';
    }
}
