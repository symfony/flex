<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Configurator;

use Composer\Composer;
use Composer\IO\IOInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

/**
 * Adds services and volumes to docker-compose.yml file.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class DockerComposeConfigurator extends AbstractConfigurator
{
    private $filesystem;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        parent::__construct($composer, $io, $options);

        $this->filesystem = new Filesystem();
    }

    public function configure(Recipe $recipe, $config, Lock $lock, array $options = [])
    {
        $installDocker = $this->composer->getPackage()->getExtra()['symfony']['docker'] ?? false;
        if (!$installDocker) {
            return;
        }

        $rootDir = $this->options->get('root-dir');
        foreach ($this->normalizeConfig($config) as $file => $extra) {
            $dockerComposeFile = $this->findDockerComposeFile($rootDir, $file);
            if (null === $dockerComposeFile) {
                $dockerComposeFileName = preg_replace('/\.yml$/', '.yaml', $file);
                $dockerComposeFile = $rootDir.'/'.$dockerComposeFileName;
                file_put_contents($dockerComposeFile, "version: '3'\n");
                $this->write(sprintf('  Created <fg=green>"%s"</>', $dockerComposeFileName));
            }
            if ($this->isFileMarked($recipe, $dockerComposeFile)) {
                continue;
            }

            $this->write(sprintf('Adding Docker Compose definitions to "%s"', $dockerComposeFile));

            $offset = 2;
            $node = null;
            $endAt = [];
            $lines = [];
            foreach (file($dockerComposeFile) as $i => $line) {
                $lines[] = $line;
                $ltrimedLine = ltrim($line, ' ');

                // Skip blank lines and comments
                if (('' !== $ltrimedLine && 0 === strpos($ltrimedLine, '#')) || '' === trim($line)) {
                    continue;
                }

                // Extract Docker Compose keys (usually "services" and "volumes")
                if (!preg_match('/^[\'"]?([a-zA-Z0-9]+)[\'"]?:\s*$/', $line, $matches)) {
                    // Detect indentation to use
                    $offestLine = \strlen($line) - \strlen($ltrimedLine);
                    if ($offset > $offestLine && 0 !== $offestLine) {
                        $offset = $offestLine;
                    }
                    continue;
                }

                // Keep end in memory (check break line on previous line)
                $endAt[$node] = '' !== trim($lines[$i - 1]) ? $i : $i - 1;
                $node = $matches[1];
            }
            $endAt[$node] = \count($lines) + 1;

            foreach ($extra as $key => $value) {
                if (isset($endAt[$key])) {
                    array_splice($lines, $endAt[$key], 0, $this->markData($recipe, $this->parse(1, $offset, $value)));
                    continue;
                }

                $lines[] = sprintf("\n%s:", $key);
                $lines[] = $this->markData($recipe, $this->parse(1, $offset, $value));
            }

            file_put_contents($dockerComposeFile, implode('', $lines));
        }

        $this->write('Docker Compose definitions have been modified. Please run "docker-compose up --build" again to apply the changes.');
    }

    public function unconfigure(Recipe $recipe, $config, Lock $lock)
    {
        $rootDir = $this->options->get('root-dir');
        foreach ($this->normalizeConfig($config) as $file => $extra) {
            if (null === $dockerComposeFile = $this->findDockerComposeFile($rootDir, $file)) {
                continue;
            }

            $name = $recipe->getName();
            // Remove recipe and add break line
            $contents = preg_replace(sprintf('{%s+###> %s ###.*?###< %s ###%s+}s', "\n", $name, $name, "\n"), \PHP_EOL.\PHP_EOL, file_get_contents($dockerComposeFile), -1, $count);
            if (!$count) {
                return;
            }

            foreach ($extra as $key => $value) {
                if (0 === preg_match(sprintf('{^%s:[ \t\r\n]*([ \t]+\w|#)}m', $key), $contents, $matches)) {
                    $contents = preg_replace(sprintf('{\n?^%s:[ \t\r\n]*}sm', $key), '', $contents, -1, $count);
                }
            }

            $this->write(sprintf('Removing Docker Compose entries from "%s"', $dockerComposeFile));
            file_put_contents($dockerComposeFile, ltrim($contents, "\n"));
        }

        $this->write('Docker Compose definitions have been modified. Please run "docker-compose up" again to apply the changes.');
    }

    /**
     * Normalizes the config and return the name of the main Docker Compose file if applicable.
     */
    private function normalizeConfig(array $config): array
    {
        foreach ($config as $val) {
            // Support for the short syntax recipe syntax that modifies docker-compose.yml only
            return isset($val[0]) ? ['docker-compose.yml' => $config] : $config;
        }

        return $config;
    }

    /**
     * Finds the Docker Compose file according to these rules: https://docs.docker.com/compose/reference/envvars/#compose_file.
     */
    private function findDockerComposeFile(string $rootDir, string $file): ?string
    {
        if (isset($_SERVER['COMPOSE_FILE'])) {
            $separator = $_SERVER['COMPOSE_PATH_SEPARATOR'] ?? ('\\' === \DIRECTORY_SEPARATOR ? ';' : ':');

            $files = explode($separator, $_SERVER['COMPOSE_FILE']);
            foreach ($files as $f) {
                if ($file !== basename($f)) {
                    continue;
                }

                if (!$this->filesystem->isAbsolutePath($f)) {
                    $f = realpath(sprintf('%s/%s', $rootDir, $f));
                }

                if ($this->filesystem->exists($f)) {
                    return $f;
                }
            }
        }

        // COMPOSE_FILE not set, or doesn't contain the file we're looking for
        $dir = $rootDir;
        $previousDir = null;
        do {
            // Test with the ".yaml" extension if the file doesn't end up with ".yml".
            if (
                $this->filesystem->exists($dockerComposeFile = sprintf('%s/%s', $dir, $file)) ||
                $this->filesystem->exists($dockerComposeFile = substr($dockerComposeFile, 0, -2).'aml')
            ) {
                return $dockerComposeFile;
            }

            $previousDir = $dir;
            $dir = \dirname($dir);
        } while ($dir !== $previousDir);

        return null;
    }

    private function parse($level, $indent, $services): string
    {
        $line = '';
        foreach ($services as $key => $value) {
            $line .= str_repeat(' ', $indent * $level);
            if (!\is_array($value)) {
                if (\is_string($key)) {
                    $line .= sprintf('%s:', $key);
                }
                $line .= sprintf("%s\n", $value);
                continue;
            }
            $line .= sprintf("%s:\n", $key).$this->parse($level + 1, $indent, $value);
        }

        return $line;
    }
}
