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
use Symfony\Flex\PackageResolver;

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
        $input->setArgument('packages', $resolver->resolve($input->getArgument('packages')));
        $input->setOption('no-suggest', true);

        return parent::execute($input, $output);
    }
}
