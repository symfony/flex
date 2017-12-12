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
use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\PackageResolver;

class RequireCommand extends BaseRequireCommand
{
    private $resolver;
    private $composer;
    private $manipulator;

    public function __construct(PackageResolver $resolver)
    {
        $this->resolver = $resolver;

        parent::__construct();
    }

    protected function configure()
    {
        parent::configure();
        $this->addOption('unpack', null, InputOption::VALUE_NONE, 'Unpack Symfony packs in composer.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $packages = $this->resolver->resolve($input->getArgument('packages'), true);
        $packages = $this->unpack($packages, $input->getOption('unpack'), $input->getOption('sort-packages'), $input->getOption('dev'));
        if (!$packages) {
            // we need at least one package for the command to work properly
            $packages = ['symfony/flex'];
        }

        $input->setArgument('packages', $packages);

        if ($input->hasOption('no-suggest')) {
            $input->setOption('no-suggest', true);
        }

        return parent::execute($input, $output);
    }

    private function unpack(array $packages, bool $unpack, bool $sortPackages, bool $dev): array
    {
        $versionParser = new VersionParser();
        $this->composer = $this->getComposer();
        $json = new JsonFile(Factory::getComposerFile());
        $this->manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        $sortPackages = $sortPackages || $this->composer->getConfig()->get('sort-packages');
        $pkgs = [];

        foreach ($versionParser->parseNameVersionPairs($packages) as $package) {
            if (!$this->addDep($package['name'], $package['version'] ?? '*', $unpack, $sortPackages, $dev)) {
                $pkgs[] = $package['name'].(isset($package['version']) ? ':'.$package['version'] : '');
            }
        }

        file_put_contents($json->getPath(), $this->manipulator->getContents());

        return $pkgs;
    }

    private function addDep(string $name, string $version, bool $unpack, bool $sortPackages, bool $dev)
    {
        $pkg = $this->composer->getRepositoryManager()->findPackage($name, $version ?? '*');
        if ('symfony-profile' !== $pkg->getType() && ($pkg->getType() !== 'symfony-pack' || !$unpack)) {
            return false;
        }
        if (0 === count($pkg->getRequires()) + count($pkg->getDevRequires())) {
            // don't unpack empty packs, they are markers we need to keep
            return false;
        }

        foreach ($pkg->getRequires() as $link) {
            if ('php' === $link->getTarget()) {
                continue;
            }
            if (!$this->addDep($link->getTarget(), '*', true, $sortPackages, $dev)) {
                if (!$this->manipulator->addLink($dev ? 'require-dev' : 'require', $link->getTarget(), $link->getPrettyConstraint(), $sortPackages)) {
                    throw new \RuntimeException(sprintf('Unable to unpack package "%s".', $link->getTarget()));
                }
            }
        }
        if ('symfony-profile' === $pkg->getType()) {
            foreach ($pkg->getDevRequires() as $link) {
                if (!$this->addDep($link->getTarget(), '*', true, $sortPackages, true)) {
                    if (!$this->manipulator->addLink('require-dev', $link->getTarget(), $link->getPrettyConstraint(), $sortPackages)) {
                        throw new \RuntimeException(sprintf('Unable to unpack package "%s".', $link->getTarget()));
                    }
                }
            }
        }

        return true;
    }
}
