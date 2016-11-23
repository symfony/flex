<?php

namespace Symfony\Flex\Configurator;

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Symfony\Flex\Recipe;

class ComposerScriptsConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $scripts)
    {
        $json = new JsonFile(Factory::getComposerFile());

        $jsonContents = $json->read();
        $autoScripts = isset($jsonContents['scripts']['auto-scripts']) ? $jsonContents['scripts']['auto-scripts'] : array();
        $autoScripts = array_merge($autoScripts, $scripts);

        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('scripts', 'auto-scripts', $autoScripts);

        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    public function unconfigure(Recipe $recipe, $scripts)
    {
        $json = new JsonFile(Factory::getComposerFile());

        $jsonContents = $json->read();
        $autoScripts = isset($jsonContents['scripts']['auto-scripts']) ? $jsonContents['scripts']['auto-scripts'] : array();
        foreach (array_keys($scripts) as $cmd) {
            unset($autoScripts[$cmd]);
        }

        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $manipulator->addSubNode('scripts', 'auto-scripts', $autoScripts);

        file_put_contents($json->getPath(), $manipulator->getContents());
    }
}
