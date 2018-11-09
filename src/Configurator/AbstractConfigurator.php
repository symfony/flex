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
use Symfony\Flex\Options;
use Symfony\Flex\Path;
use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
abstract class AbstractConfigurator
{
    protected $composer;
    protected $io;
    protected $options;
    protected $path;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
        $this->path = new Path(getcwd());
    }

    abstract public function configure(Recipe $recipe, $config);

    abstract public function unconfigure(Recipe $recipe, $config);

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
}
