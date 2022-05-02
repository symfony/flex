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
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Path;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
abstract class AbstractConfigurator
{
    protected $composer;
    protected $io;
    protected $options;
    protected $path;

    protected ?bool $shouldConfigure = null;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
        $this->path = new Path($options->get('root-dir'));
    }

    /**
     * @return bool True if configured
     */
    abstract public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): bool;

    abstract public function unconfigure(Recipe $recipe, $config, Lock $lock);

    abstract public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void;

    abstract public function configureKey(): string;

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function shouldConfigure(Composer $composer, IOInterface $io, Recipe $recipe): bool
    {
        if (null !== $this->shouldConfigure) {
            return $this->shouldConfigure;
        }

        if (null !== $preference = $composer->getPackage()->getExtra()['symfony'][$this->configureKey()] ?? null) {
            return $this->shouldConfigure = $preference;
        }

        if ($this->isEnabledByDefault()) {
            return true;
        }

        if ('install' !== $recipe->getJob()) {
            return false;
        }

        $answer = $this->askSupport($io, $recipe);
        if ('n' === $answer) {
            return $this->shouldConfigure = false;
        }

        if ('y' === $answer) {
            return $this->shouldConfigure = true;
        }

        $this->shouldConfigure = 'p' === $answer;

        $this->persistPermanentChoice();

        return $this->shouldConfigure;
    }

    protected function askSupport(IOInterface $io, Recipe $recipe): string
    {
        $io->writeError(sprintf(
            '  - <warning> %s </> %s',
            $io->isInteractive() ? 'WARNING' : 'IGNORING',
            $recipe->getFormattedOrigin()
        ));

        return $io->askAndValidate(
            $this->supportQuestion(),
            function ($value) {
                if (null === $value) {
                    return 'y';
                }
                $value = strtolower($value[0]);
                if (!\in_array($value, ['y', 'n', 'p', 'x'], true)) {
                    throw new \InvalidArgumentException('Invalid choice.');
                }

                return $value;
            },
            null,
            'y'
        );
    }

    protected function supportQuestion(): string
    {
        $configuratorClass = substr(static::class, strrpos(static::class, '\\') + 1);

        return '    The recipe for this package would like to run '.$configuratorClass.'.

    Do you want to include this configuration from recipes?
    [<comment>y</>] Yes
    [<comment>n</>] No
    [<comment>p</>] Yes permanently, never ask again for this project
    [<comment>x</>] No permanently, never ask again for this project
    (defaults to <comment>y</>): ';
    }

    protected function persistPermanentChoice(): void
    {
        $json = new JsonFile(Factory::getComposerFile());
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('extra', sprintf('symfony.%s', $this->configureKey()), $this->shouldConfigure);
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    protected function write($messages)
    {
        if (!\is_array($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $i => $message) {
            $messages[$i] = '    '.$message;
        }
        $this->io->writeError($messages, true, IOInterface::VERBOSE);
    }

    protected function isFileMarked(Recipe $recipe, string $file): bool
    {
        return is_file($file) && false !== strpos(file_get_contents($file), sprintf('###> %s ###', $recipe->getName()));
    }

    protected function markData(Recipe $recipe, string $data): string
    {
        return "\n".sprintf('###> %s ###%s%s%s###< %s ###%s', $recipe->getName(), "\n", rtrim($data, "\r\n"), "\n", $recipe->getName(), "\n");
    }

    protected function isFileXmlMarked(Recipe $recipe, string $file): bool
    {
        return is_file($file) && false !== strpos(file_get_contents($file), sprintf('###+ %s ###', $recipe->getName()));
    }

    protected function markXmlData(Recipe $recipe, string $data): string
    {
        return "\n".sprintf('        <!-- ###+ %s ### -->%s%s%s        <!-- ###- %s ### -->%s', $recipe->getName(), "\n", rtrim($data, "\r\n"), "\n", $recipe->getName(), "\n");
    }

    /**
     * @return bool True if section was found and replaced
     */
    protected function updateData(string $file, string $data): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        $contents = file_get_contents($file);

        $newContents = $this->updateDataString($contents, $data);
        if (null === $newContents) {
            return false;
        }

        file_put_contents($file, $newContents);

        return true;
    }

    /**
     * @return string|null returns the updated content if the section was found, null if not found
     */
    protected function updateDataString(string $contents, string $data): ?string
    {
        $pieces = explode("\n", trim($data));
        $startMark = trim(reset($pieces));
        $endMark = trim(end($pieces));

        if (false === strpos($contents, $startMark) || false === strpos($contents, $endMark)) {
            return null;
        }

        $pattern = '/'.preg_quote($startMark, '/').'.*?'.preg_quote($endMark, '/').'/s';

        return preg_replace($pattern, trim($data), $contents);
    }

    protected function extractSection(Recipe $recipe, string $contents): ?string
    {
        $section = $this->markData($recipe, '----');

        $pieces = explode("\n", trim($section));
        $startMark = trim(reset($pieces));
        $endMark = trim(end($pieces));

        $pattern = '/'.preg_quote($startMark, '/').'.*?'.preg_quote($endMark, '/').'/s';

        $matches = [];
        preg_match($pattern, $contents, $matches);

        return $matches[0] ?? null;
    }
}
