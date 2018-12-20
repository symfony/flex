<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Harmony\Flex\Command;

use Composer\Command\RemoveCommand as BaseRemoveCommand;
use Harmony\Flex\PackageResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends BaseRemoveCommand
{

    private $resolver;

    public function __construct(PackageResolver $resolver)
    {
        $this->resolver = $resolver;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $input->setArgument('packages', $this->resolver->resolve($input->getArgument('packages')));

        return parent::execute($input, $output);
    }
}
