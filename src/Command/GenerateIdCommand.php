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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateIdCommand extends Command
{
    public function __construct()
    {
        // No-op to support downgrading to v1.12.x
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('symfony:generate-id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);
        $ui->error('This command is a noop and should not be used anymore.');

        return 1;
    }
}
