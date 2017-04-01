<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Composer;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\IOInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class ScriptExecutor
{
    private $composer;
    private $io;
    private $options;
    private $executor;

    public function __construct(Composer $composer, IOInterface $io, Options $options, ProcessExecutor $executor = null)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $options;
        $this->executor = $executor ?: new ProcessExecutor();
    }

    public function execute($type, $cmd)
    {
        if (null === $expandedCmd = $this->expandCmd($type, $this->options->expandTargetDir($cmd))) {
            return;
        }

        $cmdOutput = new StreamOutput(fopen('php://memory', 'rw'), OutputInterface::VERBOSITY_VERBOSE, $this->io->isDecorated());
        $outputHandler = function ($type, $buffer) use ($cmdOutput) {
            $cmdOutput->write($buffer, OutputInterface::OUTPUT_RAW);
        };

        $this->io->writeError(sprintf('Executing script %s', $cmd), $this->io->isVerbose());
        $exitCode = $this->executor->execute($expandedCmd, $outputHandler);
        if (0 === $exitCode) {
            $code = ' <info>[OK]</info>';
        } else {
            $code = ' <error>[KO]</error>';
        }

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('Executed script %s %s', $cmd, $code));
        } else {
            $this->io->writeError($code);
        }

        if (0 !== $exitCode) {
            $this->io->writeError(' <error>[KO]</error>');
            $this->io->writeError(sprintf('<error>Script %s returned with error code %s</error>', $cmd, $exitCode));
            fseek($cmdOutput->getStream(), 0);
            foreach (explode(PHP_EOL, stream_get_contents($cmdOutput->getStream())) as $line) {
                $this->io->writeError('!!  '.$line);
            }

            throw new ScriptExecutionException($cmd, $exitCode);
        }
    }

    private function expandCmd($type, $cmd)
    {
        switch ($type) {
            case 'symfony-cmd':
                return $this->expandSymfonyCmd($cmd);
            case 'php-script':
                return $this->expandPhpScript($cmd);
            case 'script':
                return $cmd;
            default:
                throw new \InvalidArgumentException(sprintf('Command type "%s" is not valid.', $type));
        }
    }

    private function expandSymfonyCmd($cmd)
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        if (!$repo->findPackage('symfony/console', new EmptyConstraint())) {
            $this->io->writeError(sprintf('<warning>Skipping "%s" (needs symfony/console to run).</warning>', $cmd));

            return;
        }

        $console = escapeshellarg($this->options->get('bin-dir').'/console');
        if ($this->io->isDecorated()) {
            $console .= ' --ansi';
        }

        return $this->expandPhpScript($console.' '.$cmd);
    }

    private function expandPhpScript($cmd)
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$php = $phpFinder->find(false)) {
            throw new \RuntimeException('The PHP executable could not be found, add it to your PATH and try again.');
        }

        $arguments = $phpFinder->findArguments();
        if (false !== $ini = php_ini_loaded_file()) {
            $arguments[] = '--php-ini='.$ini;
        }
        $phpArgs = implode(' ', array_map('escapeshellarg', $arguments));

        return $php.($phpArgs ? ' '.$phpArgs : '').' '.$cmd;
    }
}
