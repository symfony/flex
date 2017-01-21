<?php

namespace Symfony\Flex;

use Composer\Composer;
use Composer\Factory;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Flex implements PluginInterface, EventSubscriberInterface
{
    private $composer;
    private $io;
    private $options;
    private $map;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();
        $map = array(
            'bundles' => Configurator\BundlesConfigurator::class,
            'composer-scripts' => Configurator\ComposerScriptsConfigurator::class,
            'copy-from-recipe' => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env' => Configurator\EnvConfigurator::class,
            'parameters' => Configurator\ParametersConfigurator::class,
        );
        foreach ($map as $key => $class) {
            $this->map[$key] = new $class($composer, $io, $this->options);
        }
    }

    public function configurePackage(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        foreach ($this->filterPackageNames($package) as $name => $data) {
            $this->io->write(sprintf('    Detected auto-configuration settings for "%s"', $name));
            $this->install(new Recipe($package, $name, $data));
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
            $this->uninstall(new Recipe($package, $name, $data));
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

    private function install(Recipe $recipe)
    {
        $manifest = $recipe->getData()['manifest'];
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->configure($recipe, $config);
        }
    }

    private function uninstall(Recipe $recipe)
    {
        $manifest = $recipe->getData()['manifest'];
        foreach ($manifest as $key => $config) {
            if (!isset($this->map[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown key "%s" in package "%s" manifest.', $key, $name));
            }

            $this->map[$key]->unconfigure($recipe, $config);
        }
    }

    private function filterPackageNames(Package $package)
    {
// FIXME: getNames() can return n names
        $name = $package->getNames()[0];
        if ($body = $this->getRemoteContent($name)) {
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

    // FIXME: we can probably reuse the RemoteFilesystem class of Composer
    // For that, we should probably not return 404 but an empty array when we don't have any configuration for a package
    private function getRemoteContent($name)
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'ignore_errors' => true,
                'follow_location' => true,
                'max_redirects' => 3,
                'timeout' => 10,
            ),
//            'ssl' => array(
//                'cafile' => $certFile,
//                'verify_peer' => 1,
//                'verify_host' => 2,
//            ),
        ));

        $level = error_reporting(0);
        $body = file_get_contents('https://flex.symfony.com/packages/'.$name, 0, $context);
        error_reporting($level);
        if (false === $body) {
            $error = error_get_last();

            throw new \RuntimeException(sprintf('An error occurred: %s.', $error['message']));
        }

        // status code
        if (!preg_match('{HTTP/\d\.\d (\d+) }i', $http_response_header[0], $match)) {
            throw new \RuntimeException('An unknown error occurred.');
        }

        $statusCode = $match[1];
        if (400 == $statusCode) {
            $data = json_decode($body, true);

            throw new \RuntimeException($data['error']);
        }

        if (404 == $statusCode) {
            return;
        }

        if (200 != $statusCode) {
            throw new \RuntimeException(sprintf('The web service failed for an unknown reason (HTTP %s).', $statusCode));
        }

        return json_decode($body, true);
    }

    private function getRecipesDir()
    {
        return __DIR__.'/../recipes';
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
