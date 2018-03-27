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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Factory;
use Composer\Script\Event;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\Lock;

class FixRecipesCommand extends BaseCommand
{
    private $flex;

    public function __construct(/* cannot be type-hinted */ $flex)
    {
        $this->flex = $flex;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('fix-recipes')
            ->setDescription('Install missing recipes.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symfonyLock = new Lock(getenv('SYMFONY_LOCKFILE') ?: str_replace('composer.json', 'symfony.lock', Factory::getComposerFile()));
        $composer = $this->getComposer();
        $locker = $composer->getLocker();
        $lockData = $locker->getLockData();

        $packages = [];
        foreach ($lockData['packages'] as $pkg) {
            if (!$symfonyLock->has($pkg['name'])) {
                $packages[] = $pkg['name'];
            }
        }
        foreach ($lockData['packages-dev'] as $pkg) {
            if (!$symfonyLock->has($pkg['name'])) {
                $packages[] = $pkg['name'];
            }
        }

        if (!$packages) {
            return;
        }

        $composer = $this->getComposer();
        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();

        $operations = [];
        foreach ($packages as $package) {
            if (null === $pkg = $installedRepo->findPackage($package, '*')) {
                $io->writeError(sprintf('<error>Package %s is not installed</>', $package));

                return 1;
            }

            $operations[] = new InstallOperation($pkg);
        }

        $this->flex->update(new EmptyEvent(), $operations);
    }
}

class EmptyEvent extends Event
{
    public function __construct()
    {
    }
}
