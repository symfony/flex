<?php

namespace Symfony\Flex\Configurator;

use Composer\IO\IOInterface;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 * @author Ryan Weaver <ryan@symfonycasts.com>
 */
class AddLinesConfigurator extends AbstractConfigurator
{
    private const POSITION_TOP = 'top';
    private const POSITION_BOTTOM = 'bottom';
    private const POSITION_AFTER_TARGET = 'after_target';

    private const VALID_POSITIONS = [
        self::POSITION_TOP,
        self::POSITION_BOTTOM,
        self::POSITION_AFTER_TARGET,
    ];

    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        foreach ($config as $patch) {
            if (!isset($patch['file'])) {
                $this->write(sprintf('The "file" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->getName()));

                continue;
            }

            if (isset($patch['requires']) && !$this->isPackageInstalled($patch['requires'])) {
                continue;
            }

            if (!isset($patch['content'])) {
                $this->write(sprintf('The "content" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->getName()));

                continue;
            }
            $content = $patch['content'];

            $file = $this->path->concatenate([$this->options->get('root-dir'), $patch['file']]);
            $warnIfMissing = isset($patch['warn_if_missing']) && $patch['warn_if_missing'];
            if (!is_file($file)) {
                $this->write([
                    sprintf('Could not add lines to file <info>%s</info> as it does not exist. Missing lines:', $patch['file']),
                    '<comment>"""</comment>',
                    $content,
                    '<comment>"""</comment>',
                    '',
                ], $warnIfMissing ? IOInterface::NORMAL : IOInterface::VERBOSE);

                continue;
            }

            $this->write(sprintf('Patching file "%s"', $patch['file']));

            if (!isset($patch['position'])) {
                $this->write(sprintf('The "position" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->getName()));

                continue;
            }
            $position = $patch['position'];
            if (!\in_array($position, self::VALID_POSITIONS, true)) {
                $this->write(sprintf('The "position" key must be one of "%s" for the "add-lines" configurator for recipe "%s". Skipping', implode('", "', self::VALID_POSITIONS), $recipe->getName()));

                continue;
            }

            if (self::POSITION_AFTER_TARGET === $position && !isset($patch['target'])) {
                $this->write(sprintf('The "target" key is required when "position" is "%s" for the "add-lines" configurator for recipe "%s". Skipping', self::POSITION_AFTER_TARGET, $recipe->getName()));

                continue;
            }
            $target = isset($patch['target']) ? $patch['target'] : null;

            $this->patchFile($file, $content, $position, $target, $warnIfMissing);
        }
    }

    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        foreach ($config as $patch) {
            if (!isset($patch['file'])) {
                $this->write(sprintf('The "file" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->getName()));

                continue;
            }

            // Ignore "requires": the target packages may have just become uninstalled.
            // Checking for a "content" match is enough.

            $file = $this->path->concatenate([$this->options->get('root-dir'), $patch['file']]);
            if (!is_file($file)) {
                continue;
            }

            if (!isset($patch['content'])) {
                $this->write(sprintf('The "content" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->getName()));

                continue;
            }
            $value = $patch['content'];

            $this->unPatchFile($file, $value);
        }
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        $originalConfig = array_filter($originalConfig, function ($item) {
            return !isset($item['requires']) || $this->isPackageInstalled($item['requires']);
        });
        $newConfig = array_filter($newConfig, function ($item) {
            return !isset($item['requires']) || $this->isPackageInstalled($item['requires']);
        });

        $filterDuplicates = function (array $sourceConfig, array $comparisonConfig) {
            $filtered = [];
            foreach ($sourceConfig as $sourceItem) {
                $found = false;
                foreach ($comparisonConfig as $comparisonItem) {
                    if ($sourceItem['file'] === $comparisonItem['file'] && $sourceItem['content'] === $comparisonItem['content']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $filtered[] = $sourceItem;
                }
            }

            return $filtered;
        };

        // remove any config where the file+value is the same before & after
        $filteredOriginalConfig = $filterDuplicates($originalConfig, $newConfig);
        $filteredNewConfig = $filterDuplicates($newConfig, $originalConfig);

        $this->unconfigure($recipeUpdate->getOriginalRecipe(), $filteredOriginalConfig, $recipeUpdate->getLock());
        $this->configure($recipeUpdate->getNewRecipe(), $filteredNewConfig, $recipeUpdate->getLock());
    }

    private function patchFile(string $file, string $value, string $position, ?string $target, bool $warnIfMissing)
    {
        $fileContents = file_get_contents($file);

        if (false !== strpos($fileContents, $value)) {
            return; // already includes value, skip
        }

        switch ($position) {
            case self::POSITION_BOTTOM:
                $fileContents .= "\n".$value;

                break;
            case self::POSITION_TOP:
                $fileContents = $value."\n".$fileContents;

                break;
            case self::POSITION_AFTER_TARGET:
                $lines = explode("\n", $fileContents);
                $targetFound = false;
                foreach ($lines as $key => $line) {
                    if (false !== strpos($line, $target)) {
                        array_splice($lines, $key + 1, 0, $value);
                        $targetFound = true;

                        break;
                    }
                }
                $fileContents = implode("\n", $lines);

                if (!$targetFound) {
                    $this->write([
                        sprintf('Could not add lines after "%s" as no such string was found in "%s". Missing lines:', $target, $file),
                        '<comment>"""</comment>',
                        $value,
                        '<comment>"""</comment>',
                        '',
                    ], $warnIfMissing ? IOInterface::NORMAL : IOInterface::VERBOSE);
                }

                break;
        }

        file_put_contents($file, $fileContents);
    }

    private function unPatchFile(string $file, $value)
    {
        $fileContents = file_get_contents($file);

        if (false === strpos($fileContents, $value)) {
            return; // value already gone!
        }

        if (false !== strpos($fileContents, "\n".$value)) {
            $value = "\n".$value;
        } elseif (false !== strpos($fileContents, $value."\n")) {
            $value = $value."\n";
        }

        $position = strpos($fileContents, $value);
        $fileContents = substr_replace($fileContents, '', $position, \strlen($value));

        file_put_contents($file, $fileContents);
    }

    private function isPackageInstalled($packages): bool
    {
        if (\is_string($packages)) {
            $packages = [$packages];
        }

        $installedRepo = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($packages as $packageName) {
            if (null === $installedRepo->findPackage($packageName, '*')) {
                return false;
            }
        }

        return true;
    }
}
