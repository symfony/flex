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

use Composer\Command\RemoveCommand as BaseRemoveCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\Downloader;
use Symfony\Flex\Package\Package;
use Symfony\Flex\Package\PackageResolver;

class RemoveCommand extends BaseRemoveCommand
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
        $lookup = new PackageResolver($this->downloader);
        $packages = $lookup->resolve($input->getArgument('packages'));
        $input->setArgument('packages', array_unique(array_map(function ($package) {
            return $package instanceof Package ? $package->getName() : $package;
        }, $packages)));

        return parent::execute($input, $output);
    }
}
