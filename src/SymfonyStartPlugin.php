<?php

namespace Symfony\Start;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Start\PackageConfigurator;

class SymfonyStartPlugin implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->initOptions();
    }

    public function installConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->configure($package, $name, $this->getRecipesDir().'/'.$name);
            $this->io->write('');
        }
    }

    public function updateConfig(PackageEvent $event)
    {
    }

    public function removeConfig(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $configurator = $this->getConfigurator($name);
            $configurator->unconfigure($package, $name, $this->getRecipesDir().'/'.$name);
        }
    }

    public function postInstall(Event $event)
    {
        $this->postUpdate($event);
    }

    public function postUpdate(Event $event)
    {
    }

    public function executeAutoScripts(Event $event)
    {
        $process = new ProcessExecutor($this->io);

        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        if (isset($jsonContents['scripts']['auto-scripts'])) {
            $io = $this->io;
            $outputHandler = function ($type, $buffer) use ($io) {
                if (!$io->isVerbose()) {
                    return;
                }

                $method = 'err' === $type ? 'writeError' : 'write';
                $io->$method($buffer);
            };

            foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
                if (null !== $expandedCmd = $this->expandCmd($type, $this->expandTargetDir($cmd))) {
                    $this->io->writeError(sprintf('Executing script %s', $cmd), $this->io->isVerbose());
                    $exitCode = $process->execute($expandedCmd, $outputHandler);
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
                        $this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code %s</error>', $cmd, $event->getName(), $exitCode));

                        throw new ScriptExecutionException(sprintf('Error Output: %s', $process->getErrorOutput()), $exitCode);
                    }
                }
            }
        }

        $event->stopPropagation();
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
                throw new InvalidArgumentException(sprintf('Command type "%s" is not valid.', $type));
        }
    }

    private function expandSymfonyCmd($cmd)
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

    private function filterPackageNames(Package $package)
    {
        foreach ($package->getNames() as $name) {
            if (!is_dir($this->getRecipesDir().'/'.$name)) {
                continue;
            }

            yield $name;
        }
    }

    private function getConfigurator($name)
    {
        return new PackageConfigurator($this->composer, $this->io, $this->options);
    }

    private function initOptions()
    {
        $this->options = array_merge(array(
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'web-dir' => 'web',
//            'cache-warmup' => true,
        ), $this->composer->getPackage()->getExtra());

//        $this->options['cache-warmup'] = getenv('CACHE_WARMUP') ?: $this->options['cache-warmup'];
    }

// FIXME: duplocated in PackageConfigurator
    private function expandTargetDir($target)
    {
        $options = $this->options;

        return preg_replace_callback('{%(.+?)%}', function ($matches) use ($options) {
// FIXME: we should have a validator checking recipes when they are merged into the repo
// so that exceptions here are just not possible
            $option = str_replace('_', '-', strtolower($matches[1]));
            if (!isset($options[$option])) {
                throw new \InvalidArgumentException(sprintf('Placeholder "%s" does not exist.', $matches[1]));
            }

            return $options[$option];
        }, $target);
    }

    private function getRecipesDir()
    {
        return __DIR__.'/../recipes';
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'installConfig',
            PackageEvents::POST_PACKAGE_UPDATE => 'updateConfig',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'removeConfig',
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
            'auto-scripts' => 'executeAutoScripts',
        );
    }
}
