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
    public function configure(Recipe $recipe, $vars)
    {
        $this->io->write('    Adding entries to .gitignore');
        $data = sprintf("\n###> %s ###\n", $recipe->getName());
        foreach ($vars as $value) {
            $data .= "$value\n";
        }
        $data .= sprintf("###< %s ###\n", $recipe->getName());
        file_put_contents(getcwd().'/.gitignore', $data, FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        $file = getcwd().'/.gitignore';
        if (!file_exists($file)) {
            return;
        }

        $contents = preg_replace(sprintf('{\n+###> %s ###.*###< %s ###\n+}s', $recipe->getName(), $recipe->getName()), "\n", file_get_contents($file), -1, $count);
        if (!$count) {
            return;
        }

        $this->io->write('    Removing entries in .gitignore');
        file_put_contents($file, $contents);
    }
}
