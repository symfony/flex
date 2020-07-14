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
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\PackageResolver;

class RequireCommand extends BaseRequireCommand
{
    private $resolver;

    public function __construct(PackageResolver $resolver)
    {
        $this->resolver = $resolver;

        parent::__construct();
    }

    protected function configure()
    {
        parent::configure();
        $this->addOption('no-unpack', null, InputOption::VALUE_NONE, 'Disable unpacking Symfony packs in composer.json.');
        $this->addOption('unpack', null, InputOption::VALUE_NONE, '[DEPRECATED] Unpacking is now enabled by default.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        $ret = parent::execute($input, $output) ?? 0;

        if (0 !== $ret || $input->getOption('no-unpack') || $input->getOption('no-update')) {
            return $ret;
        }

        $unpackCommand = new UnpackCommand($this->resolver);
        $unpackCommand->setApplication($this->getApplication());

        return $unpackCommand->execute($input, $output);
    }
}
