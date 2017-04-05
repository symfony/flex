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
        $data = sprintf("%s###> %s ###%s%s%s###< %s ###%s", PHP_EOL, $recipe->getName(), PHP_EOL, implode(PHP_EOL, $definitions), PHP_EOL, $recipe->getName(), PHP_EOL);
        file_put_contents(getcwd().'/Makefile', ltrim($data, PHP_EOL), FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        if (!file_exists($makefile = getcwd().'/Makefile')) {
            return;
        }

        $contents = preg_replace(sprintf('{%s+###> %s ###.*###< %s ###%s+}s', PHP_EOL, $recipe->getName(), $recipe->getName(), PHP_EOL), PHP_EOL, file_get_contents($makefile), -1, $count);
        if (!$count) {
            return;
        }

        $this->io->write(sprintf('    Removing Makefile entries from %s', $makefile));
        if (!trim($contents)) {
            @unlink($makefile);
        } else {
            file_put_contents($makefile, ltrim($contents, PHP_EOL));
        }
    }
}
