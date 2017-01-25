<?php

namespace Symfony\Flex;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Flex implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;
    private $configurator;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
        $this->configurator = new Configurator($composer, $io, $this->options);
    }

    public function configurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name => $data) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $this->configurator->install(new Recipe($package, $name, $data));
        }
    }

    public function reconfigurePackage(PackageEvent $event)
    {
    }

    public function unconfigurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name => $data) {
            $this->io->write(sprintf('    Auto-unconfiguring "%s"', $name));
            $this->configurator->unconfigure(new Recipe($package, $name, $data));
        }
    }

    public function postInstall(Event $event)
    {
        $this->postUpdate($event);
    }

    public function postUpdate(Event $event)
    {
        if (!file_exists(getcwd().'/.env')) {
            copy(getcwd().'/.env.dist', getcwd().'/.env');
        }
    }

    public function executeAutoScripts(Event $event)
    {
        $event->stopPropagation();

        // force reloading scripts as we might have added and removed during this run
        $json = new JsonFile(Factory::getComposerFile());
        $jsonContents = $json->read();

        $executor = new ScriptExecutor($this->composer, $this->io, $this->options);
        foreach ($jsonContents['scripts']['auto-scripts'] as $cmd => $type) {
            $executor->execute($type, $cmd);
        }
    }

    private function filterPackageNames(PackageInterface $package)
    {
// FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        if ($body = $this->getRemoteContent($package, $name)) {
            yield $name => $body;
        }
    }

    private function initOptions()
    {
        $options = array_merge(array(
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'etc-dir' => 'etc',
            'src-dir' => 'src',
            'web-dir' => 'web',
//            'cache-warmup' => true,
        ), $this->composer->getPackage()->getExtra());

//        $options['cache-warmup'] = getenv('CACHE_WARMUP') ?: $options['cache-warmup'];

        return new Options($options);
    }

    private function getRemoteContent(PackageInterface $package, $name)
    {
        $version = $package->getFullPrettyVersion(false);
        if (false !== strpos($version, ' ')) {
            list($version, $ref) = explode(' ', $version);
            $url = sprintf("https://flex.symfony.com/packages/%s?v=%s&r=%s", $name, urlencode($version), urlencode($ref));
        } else {
            $url = sprintf("https://flex.symfony.com/packages/%s?v=%s", $name, urlencode($version));
        }

        $config = $this->composer->getConfig();
        $config->merge(array('config' => array('secure-http' => false)));
        $config->prohibitUrlByConfig('http://flex.symfony.com', new NullIO());
        $rfs = Factory::createRemoteFilesystem($this->io, $config);

        try {
            return json_decode($rfs->getContents('flex.symfony.com', $url, false), true);
        } catch (TransportException $e) {
            if (0 !== $e->getCode() && 404 == $e->getCode()) {
                return;
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'configurePackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'reconfigurePackage',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'unconfigurePackage',
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postUpdate',
            'auto-scripts' => 'executeAutoScripts',
        );
    }
}
