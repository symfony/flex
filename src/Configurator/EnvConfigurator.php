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
    public function configure(Recipe $recipe, $vars)
    {
        $this->write('Added environment variable defaults');

        $this->configureEnvDist($recipe, $vars);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        $this->unconfigureEnvFiles($recipe, $vars);
    }

    private function configureEnvDist(Recipe $recipe, $vars)
    {
        $distenv = getcwd().'/.env.dist';
        if (!is_file($distenv) || $this->isFileMarked($recipe, $distenv)) {
            return;
        }

        $data = '';
        foreach ($vars as $key => $value) {
            if ('%generate(secret)%' === $value) {
                $value = bin2hex(random_bytes(16));
            }
            if ('#' === $key[0] && is_numeric(substr($key, 1))) {
                $data .= '# '.$value."\n";

                continue;
            }

            $value = $this->options->expandTargetDir($value);
            if (false !== strpbrk($value, " \t\n&!\"")) {
                $value = '"'.str_replace(['\\', '"', "\t", "\n"], ['\\\\', '\\"', '\t', '\n'], $value).'"';
            }
            $data .= "$key=$value\n";
        }
        if (!file_exists(getcwd().'/.env')) {
            copy($distenv, getcwd().'/.env');
        }
        $data = $this->markData($recipe, $data);
        file_put_contents($distenv, $data, FILE_APPEND);
        file_put_contents(getcwd().'/.env', $data, FILE_APPEND);
    }

    private function unconfigureEnvFiles(Recipe $recipe, $vars)
    {
        foreach (['.env', '.env.dist'] as $file) {
            $env = getcwd().'/'.$file;
            if (!file_exists($env)) {
                continue;
            }

            $contents = preg_replace(sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->getName(), $recipe->getName(), "\n"), "\n", file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }

            $this->write(sprintf('Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }
}
