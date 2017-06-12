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

use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class GitignoreConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $vars): void
    {
        $this->write('Adding entries to .gitignore');
        $data = sprintf('%s###> %s ###%s', PHP_EOL, $recipe->getName(), PHP_EOL);
        foreach ($vars as $value) {
            $data .= "$value".PHP_EOL;
        }
        $data .= sprintf('###< %s ###%s', $recipe->getName(), PHP_EOL);
        file_put_contents(getcwd().'/.gitignore', ltrim($data, PHP_EOL), FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars): void
    {
        $file = getcwd().'/.gitignore';
        if (!file_exists($file)) {
            return;
        }

        $contents = preg_replace(sprintf('{%s+###> %s ###.*###< %s ###%s+}s', PHP_EOL, $recipe->getName(), $recipe->getName(), PHP_EOL), PHP_EOL, file_get_contents($file), -1, $count);
        if (!$count) {
            return;
        }

        $this->write('Removing entries in .gitignore');
        file_put_contents($file, ltrim($contents, PHP_EOL));
    }
}
