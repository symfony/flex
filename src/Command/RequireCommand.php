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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\Downloader;
use Symfony\Flex\Package\Package;
use Symfony\Flex\Package\PackageResolver;

class RequireCommand extends BaseRequireCommand
{
    private $downloader;

    public function __construct(Downloader $downloader)
    {
        $this->downloader = $downloader;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();
        $resolver = new PackageResolver($this->downloader);
        $packages = $resolver->resolve($input->getArgument('packages'));
        foreach ($packages as $package) {
            if (!$package instanceof Package) {
                continue;
            }

            if ($package->isDev() && !$input->getOption('dev')) {
                $io->writeError(sprintf('<warning>Package "%s" should be installed with the "--dev" option.</>', $package->getName()));
            }

            if (!$package->isDev() && $input->getOption('dev')) {
                $io->writeError(sprintf('<warning>Package "%s" should be installed without the "--dev" option.</>', $package->getName()));
            }
        }

        $input->setArgument('packages', array_unique(array_map('strval', $packages)));
        $input->setOption('no-suggest', true);

        return parent::execute($input, $output);
    }
}
