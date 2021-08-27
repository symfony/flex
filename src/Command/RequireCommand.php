<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Command;

use Composer\Command\RequireCommand as BaseRequireCommand;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\PackageResolver;

class RequireCommand extends BaseRequireCommand
{
    private $resolver;
    private $updateComposerLock;

    public function __construct(PackageResolver $resolver, \Closure $updateComposerLock = null)
    {
        $this->resolver = $resolver;
        $this->updateComposerLock = $updateComposerLock;

        parent::__construct();
    }

    protected function configure()
    {
        parent::configure();
        $this->addOption('no-unpack', null, InputOption::VALUE_NONE, '[DEPRECATED] Disable unpacking Symfony packs in composer.json.');
        $this->addOption('unpack', null, InputOption::VALUE_NONE, '[DEPRECATED] Unpacking is now enabled by default.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('no-unpack')) {
            $this->getIO()->writeError('<warning>The "--unpack" command line option is deprecated; unpacking is now enabled by default.</warning>');
        }

        if ($input->getOption('unpack')) {
            $this->getIO()->writeError('<warning>The "--unpack" command line option is deprecated; unpacking is now enabled by default.</warning>');
        }

        $packages = $this->resolver->resolve($input->getArgument('packages'), true);
        if ($packages) {
            $input->setArgument('packages', $this->resolver->resolve($input->getArgument('packages'), true));
        }

        if (version_compare('2.0.0', PluginInterface::PLUGIN_API_VERSION, '>') && $input->hasOption('no-suggest')) {
            $input->setOption('no-suggest', true);
        }

        $file = Factory::getComposerFile();
        $contents = file_get_contents($file);
        $json = JsonFile::parseJson($contents);

        if (\array_key_exists('require-dev', $json) && !$json['require-dev'] && (new JsonManipulator($contents))->removeMainKey('require-dev')) {
            $manipulator = new JsonManipulator($contents);
            $manipulator->addLink('require-dev', 'php', '*');
            file_put_contents($file, $manipulator->getContents());
        } else {
            $file = null;
        }
        unset($contents, $json, $manipulator);

        try {
            return parent::execute($input, $output) ?? 0;
        } finally {
            if (null !== $file) {
                $manipulator = new JsonManipulator(file_get_contents($file));
                $manipulator->removeSubNode('require-dev', 'php');
                file_put_contents($file, $manipulator->getContents());

                if ($this->updateComposerLock) {
                    ($this->updateComposerLock)();
                }
            }
        }
    }
}
