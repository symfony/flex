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
class EnvConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $vars): void
    {
        $this->write('Adding environment variable defaults');
        $data = sprintf('%s###> %s ###%s', PHP_EOL, $recipe->getName(), PHP_EOL);
        foreach ($vars as $key => $value) {
            if ('%generate(secret)%' === $value) {
                $value = bin2hex(random_bytes(16));
            }
            if ('#' === $key[0]) {
                $data .= '# '.$value.PHP_EOL;
            } else {
                $value = $this->options->expandTargetDir($value);
                $data .= "$key=$value".PHP_EOL;
            }
        }
        $data .= sprintf('###< %s ###%s', $recipe->getName(), PHP_EOL);
        if (!file_exists(getcwd().'/.env')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
        file_put_contents(getcwd().'/.env.dist', $data, FILE_APPEND);
        file_put_contents(getcwd().'/.env', $data, FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars): void
    {
        foreach (['.env', '.env.dist'] as $file) {
            $env = getcwd().'/'.$file;
            if (!file_exists($env)) {
                continue;
            }

            $contents = preg_replace(sprintf('{%s+###> %s ###.*###< %s ###%s+}s', PHP_EOL, $recipe->getName(), $recipe->getName(), PHP_EOL), PHP_EOL, file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }

            $this->write(sprintf('Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }
}
