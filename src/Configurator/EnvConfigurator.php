<?php

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

class EnvConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $vars)
    {
        $this->io->write('    Adding environment variable defaults');
        $data = sprintf("\n###> %s ###\n", $recipe->getName());
        foreach ($vars as $key => $value) {
            $data .= "$key=$value\n";
        }
        $data .= sprintf("###< %s ###\n", $recipe->getName());
        if (!file_exists(getcwd().'/.env')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
        file_put_contents(getcwd().'/.env.dist', $data, FILE_APPEND);
        file_put_contents(getcwd().'/.env', $data, FILE_APPEND);
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        foreach (array('.env', '.env.dist') as $file) {
            $env = getcwd().'/'.$file;
            if (!file_exists($env)) {
                continue;
            }

            $contents = preg_replace(sprintf('{\n+###> %s ###.*###< %s ###\n+}s', $recipe->getName(), $recipe->getName()), "\n", file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }

            $this->io->write(sprintf('    Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }
}
