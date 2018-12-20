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

use Harmony\Flex\Flex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateIdCommand
 *
 * @package Harmony\Flex\Command
 */
class GenerateIdCommand extends Command
{

    /** @var Flex $flex */
    private $flex;

    /**
     * GenerateIdCommand constructor.
     *
     * @param Flex $flex cannot be type-hinted
     */
    public function __construct($flex)
    {
        $this->flex = $flex;
        parent::__construct();
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setName('symfony:generate-id')->setDescription('Generates a unique ID for this project.');
    }

    /**
     * Executes the current command.
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void null or 0 if everything went fine, or an error code
     * @throws \Http\Client\Exception
     * @throws \Throwable
     * @see setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->flex->generateFlexId();
    }
}
