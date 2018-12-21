<?php

namespace Harmony\Flex\Configurator;

use DotEnvWriter\DotEnvWriter;
use Harmony\Flex\Platform\Project;

/**
 * Class EnvProjectConfigurator
 *
 * @package Harmony\Flex\Configurator
 */
class EnvProjectConfigurator extends AbstractConfigurator
{

    /**
     * @param Project $project
     * @param array   $vars
     * @param array   $options
     *
     * @throws \Exception
     */
    public function configure($project, $vars, array $options = [])
    {
        $this->write('Added environment variable defaults');
        $this->configureDatabaseEnv($project);
    }

    /**
     * @param Project $project
     * @param         $config
     */
    public function unconfigure($project, $config)
    {
        // TODO: Implement unconfigure() method.
    }

    /**
     * @param Project $project
     */
    private function configureDatabaseEnv(Project $project)
    {
        foreach (['.env.dist', '.env'] as $file) {
            $env = $this->options->get('root-dir') . '/' . $file;
            var_dump($env);exit;
            if (!is_file($env)) {
                continue;
            }
            $envWriter = new DotEnvWriter($env);

            foreach ($project->getDatabases() as $database) {
                var_dump($database);
                exit;
            }

            $data = '';
            foreach ($vars as $key => $value) {
                $value = $this->evaluateValue($value);
                if ('#' === $key[0] && is_numeric(substr($key, 1))) {
                    $data .= '# ' . $value . "\n";

                    continue;
                }

                $value = $this->options->expandTargetDir($value);
                if (false !== strpbrk($value, " \t\n&!\"")) {
                    $value = '"' . str_replace(['\\', '"', "\t", "\n"], ['\\\\', '\\"', '\t', '\n'], $value) . '"';
                }
                $data .= "$key=$value\n";
            }
            file_put_contents($env, $data, FILE_APPEND);
        }
    }
}