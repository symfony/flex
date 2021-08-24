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

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\Installer;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\PackageResolver;
use Symfony\Flex\Unpack\Operation;
use Symfony\Flex\Unpacker;

/**
 * @deprecated since Flex 1.4
 */
class UnpackCommand extends BaseCommand
{
    private $resolver;

    public function __construct(PackageResolver $resolver)
    {
        $this->resolver = $resolver;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('symfony:unpack')
            ->setAliases(['unpack'])
            ->setDescription('[DEPRECATED] Unpacks a Symfony pack.')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Installed packages to unpack.'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages'),
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $packages = $this->resolver->resolve($input->getArgument('packages'), true);
        $io = $this->getIO();
        $lockData = $composer->getLocker()->getLockData();
        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();
        $versionParser = new VersionParser();
        $dryRun = $input->hasOption('dry-run') && $input->getOption('dry-run');

        $io->writeError('<warning>Command "symfony:unpack" is deprecated, Symfony packs are always unpacked now.</>');

        $op = new Operation(true, $input->getOption('sort-packages') || $composer->getConfig()->get('sort-packages'));
        foreach ($versionParser->parseNameVersionPairs($packages) as $package) {
            if (null === $pkg = $installedRepo->findPackage($package['name'], '*')) {
                $io->writeError(sprintf('<error>Package %s is not installed</>', $package['name']));

                return 1;
            }

            $dev = false;
            foreach ($lockData['packages-dev'] as $p) {
                if ($package['name'] === $p['name']) {
                    $dev = true;

                    break;
                }
            }

            $op->addPackage($pkg->getName(), $pkg->getVersion(), $dev);
        }

        $unpacker = new Unpacker($composer, $this->resolver, $dryRun);
        $result = $unpacker->unpack($op);

        // remove the packages themselves
        if (!$result->getUnpacked()) {
            $io->writeError('<info>Nothing to unpack</>');

            return 0;
        }

        $io->writeError('<info>Unpacking Symfony packs</>');
        foreach ($result->getUnpacked() as $pkg) {
            $io->writeError(sprintf('  - Unpacked <info>%s</>', $pkg->getName()));
        }

        $unpacker->updateLock($result, $io);

        if ($input->hasOption('no-install') && $input->getOption('no-install')) {
            return 0;
        }

        $composer = Factory::create($io, null, true);
        $installer = Installer::create($io, $composer);
        $installer
            ->setDryRun($dryRun)
            ->setDevMode(true)
            ->setDumpAutoloader(false)
            ->setIgnorePlatformRequirements(true)
            ->setUpdate(true)
            ->setUpdateAllowList(['php'])
        ;

        if (method_exists($composer->getEventDispatcher(), 'setRunScripts')) {
            $composer->getEventDispatcher()->setRunScripts(false);
        } else {
            $installer->setRunScripts(false);
        }

        if (method_exists($installer, 'setSkipSuggest')) {
            $installer->setSkipSuggest(true);
        }

        return $installer->run();
    }
}
