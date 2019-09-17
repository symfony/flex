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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\InformationOperation;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;

class RecipesCommand extends BaseCommand
{
    /** @var \Symfony\Flex\Flex */
    private $flex;

    public function __construct(/* cannot be type-hinted */ $flex)
    {
        $this->flex = $flex;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('symfony:recipes:show')
            ->setAliases(['symfony:recipes'])
            ->setDescription('Shows information about all available recipes.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $locker = $this->getComposer()->getLocker();
        $lockData = $locker->getLockData();

        // Merge all packages installed
        $packages = array_merge($lockData['packages'], $lockData['packages-dev']);

        $installedRepo = $this->getComposer()->getRepositoryManager()->getLocalRepository();

        $operations = [];
        foreach ($packages as $key => $value) {
            if (null === $pkg = $installedRepo->findPackage($value['name'], '*')) {
                $this->getIO()->writeError(sprintf('<error>Package %s is not installed</error>', $value['name']));

                continue;
            }

            $operations[] = new InformationOperation($pkg);
        }

        $recipes = $this->flex->fetchRecipes($operations);
        ksort($recipes);

        if (\count($recipes) <= 0) {
            $this->getIO()->writeError('<error>No recipe found</error>');

            return 1;
        }

        $write = [
            '',
            '<bg=blue;fg=white>                      </>',
            '<bg=blue;fg=white> Recipes available.   </>',
            '<bg=blue;fg=white>                      </>',
            '',
        ];

        $symfonyLock = new Lock(getenv('SYMFONY_LOCKFILE') ?: str_replace('composer.json', 'symfony.lock', Factory::getComposerFile()));

        /** @var Recipe $recipe */
        foreach ($recipes as $name => $recipe) {
            $lockRef = $symfonyLock->get($name)['recipe']['ref'] ?? null;

            $additional = '';
            if ($recipe->isAuto()) {
                $additional = '<comment>(auto recipe)</comment>';
            } elseif (null === $lockRef && null !== $recipe->getRef()) {
                $additional = '<comment>(new recipe available)</comment>';
            } elseif ($recipe->getRef() !== $lockRef) {
                $additional = '<comment>(update available)</comment>';
            }
            $write[] = sprintf(' * %s %s', $name, $additional);
        }

        // TODO : Uncomment the lines following the implemented features
        //$write[] = '';
        //$write[] = '<fg=blue>Run</>:';
        //$write[] = ' * composer symfony:recipes vendor/package to see details about a recipe.';
        //$write[] = ' * composer symfony:recipes:update vendor/package to update that recipe.';
        //$write[] = ' * composer symfony:recipes:blame to see a tree of all of the installed/updated files.';
        $write[] = '';

        $this->getIO()->write($write);

        return 0;
    }
}
