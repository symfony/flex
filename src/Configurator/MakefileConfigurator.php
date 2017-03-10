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
class MakefileConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $definitions)
    {
        $this->io->write('    Adding Makefile entries');
        $data = sprintf("\n###> %s ###\n%s\n###< %s ###\n", $recipe->getName(), implode("\n", $definitions), $recipe->getName());
        file_put_contents(getcwd().'/Makefile', $data, FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        if (!file_exists($makefile = getcwd().'/Makefile')) {
            return;
        }

        $contents = preg_replace(sprintf('{\n+###> %s ###.*###< %s ###\n+}s', $recipe->getName(), $recipe->getName()), "\n", file_get_contents($makefile), -1, $count);
        if (!$count) {
            return;
        }

        $this->io->write(sprintf('    Removing Makefile entries from %s', $file));
        if (!trim($contents)) {
            @unlink($makefile);
        } else {
            file_put_contents($makefile, $contents);
        }
    }
}
