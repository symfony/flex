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

        $this->configureEnv($recipe, $vars);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        $this->unconfigureEnvFiles($recipe);
    }

    private function configureEnv(Recipe $recipe, $vars)
    {
        $envfile = getcwd().'/.env';
        if (!is_file($envfile) || $this->isFileMarked($recipe, $envfile)) {
            return;
        }

        $data = '';
        foreach ($vars as $key => $value) {
            $value = $this->evaluateValue($value);
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
        $data = $this->markData($recipe, $data);
        file_put_contents($envfile, $data, FILE_APPEND);
    }

    private function unconfigureEnvFiles(Recipe $recipe)
    {
        $envfile = getcwd().'/.env';
        if (!file_exists($envfile)) {
            return;
        }

        $contents = preg_replace(sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->getName(), $recipe->getName(), "\n"), "\n", file_get_contents($envfile), -1, $count);
        if (!$count) {
            return;
        }

        $this->write(sprintf('Removing environment variables from %s', $envfile));
        file_put_contents($envfile, $contents);
    }

    private function evaluateValue($value)
    {
        if ('%generate(secret)%' === $value) {
            return $this->generateRandomBytes();
        }
        if (preg_match('~^%generate\(secret,\s*([0-9]+)\)%$~', $value, $matches)) {
            return $this->generateRandomBytes($matches[1]);
        }

        return $value;
    }

    private function generateRandomBytes($length = 16)
    {
        return bin2hex(random_bytes($length));
    }
}
