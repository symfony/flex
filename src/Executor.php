<?php

namespace Symfony\Start;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;

class Executor
{
    public function __construct()
    {
        
    }

    public function execute($type, $cmd)
    {
        switch ($type) {
            case 'symfony-cmd':
                return $this->executeSymfonyCmd($cmd);
            case 'php-script':
                return $this->executePhpScript($cmd);
            case 'script':
                return $this->executeScript($cmd);
            default:
                throw new InvalidArgumentException(sprintf('Command type "%s" is not valid.', $type));
        }
    }

    private function executeSymfonyCmd($cmd)
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        if (!$repo->findPackage('symfony/console', new EmptyConstraint())) {
// FIXME: we need a way to disable the automatic run of cache:clear and assets:install
//        via the composer extra configuration
            $this->io->writeError('<warning>The symfony/console package is required if you want to automatically clear the cache and install assets.</warning>');

            return;
        }

        $console = escapeshellarg($this->options['bin-dir'].'/console');
        if ($this->io->isDecorated()) {
            $console .= ' --ansi';
        }

        $this->executePhpScript($console.' '.$cmd);
    }

    private function executePhpScript($cmd)
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

        $this->executeScript($php.($phpArgs ? ' '.$phpArgs : '').' '.$cmd);
    }

    private function executeScript($cmd)
    {
        $process = new Process($cmd, null, null, null, $this->composer->getConfig()->get('process-timeout'));
        $io = $this->io;
        $process->run(function ($type, $buffer) use ($io) { $io->write($buffer, false); });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf("An error occurred when executing the \"%s\" command:\n\n%s\n\n%s.", escapeshellarg($cmd), $process->getOutput(), $process->getErrorOutput()));
        }
    }
}
