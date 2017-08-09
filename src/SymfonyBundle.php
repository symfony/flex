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

use Composer\Composer;
use Composer\Package\PackageInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class SymfonyBundle
{
    private $package;
    private $operation;
    private $installationManager;

    public function __construct(Composer $composer, PackageInterface $package, string $operation)
    {
        $this->package = $package;
        $this->operation = $operation;
        $this->installationManager = $composer->getInstallationManager();
    }

    public function getClassNames(): array
    {
        $all = 'uninstall' === $this->operation;
        $classes = [];
        $autoload = $this->package->getAutoload();
        foreach (['psr-4' => true, 'psr-0' => false] as $psr => $isPsr4) {
            if (!isset($autoload[$psr])) {
                continue;
            }

            foreach ($autoload[$psr] as $namespace => $path) {
                foreach ($this->extractClassNames($namespace) as $class) {
                    if (!$all && $this->checkClassExists($class, $path, $isPsr4)) {
                        return [$class];
                    }

                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    private function extractClassNames(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        $class = $namespace.'\\';
        $parts = explode('\\', $namespace);
        $suffix = $parts[count($parts) - 1];
        $classes = array($class.$suffix);
        $acc = '';
        foreach (array_slice($parts, 0, -1) as $part) {
            if ('Bundle' === $part) {
                continue;
            }
            $classes[] = $class.$part.$suffix;
            $acc .= $part;
            $classes[] = $class.$acc.$suffix;
        }

        return $classes;
    }

    private function checkClassExists(string $class, string $path, bool $isPsr4): bool
    {
        $classPath = $this->installationManager->getInstallPath($this->package).'/'.$path.'/';
        $parts = explode('\\', $class);
        $class = $parts[count($parts) - 1];
        if (!$isPsr4) {
            $classPath .= str_replace('\\', '', implode('/', array_slice($parts, 0, -1))).'/';
        }
        $classPath .= str_replace('\\', '/', $class).'.php';

        return file_exists($classPath);
    }
}
