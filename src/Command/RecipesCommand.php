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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\InformationOperation;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;

/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class RecipesCommand extends BaseCommand
{
    /** @var \Symfony\Flex\Flex */
    private $flex;

    /** @var Lock */
    private $symfonyLock;

    public function __construct(/* cannot be type-hinted */ $flex, Lock $symfonyLock)
    {
        $this->flex = $flex;
        $this->symfonyLock = $symfonyLock;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('symfony:recipes')
            ->setAliases(['recipes'])
            ->setDescription('Shows information about all available recipes.')
            ->setDefinition([
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect, if not provided all packages are.'),
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $installedRepo = $this->getComposer()->getRepositoryManager()->getLocalRepository();

        // Inspect one or all packages
        $package = $input->getArgument('package');
        if (null !== $package) {
            $packages = [0 => ['name' => strtolower($package)]];
        } else {
            $locker = $this->getComposer()->getLocker();
            $lockData = $locker->getLockData();

            // Merge all packages installed
            $packages = array_merge($lockData['packages'], $lockData['packages-dev']);
        }

        $operations = [];
        foreach ($packages as $value) {
            if (null === $pkg = $installedRepo->findPackage($value['name'], '*')) {
                $this->getIO()->writeError(sprintf('<error>Package %s is not installed</error>', $value['name']));

                continue;
            }

            $operations[] = new InformationOperation($pkg);
        }

        $recipes = $this->flex->fetchRecipes($operations);
        ksort($recipes);

        $nbRecipe = \count($recipes);
        if ($nbRecipe <= 0) {
            $this->getIO()->writeError('<error>No recipe found</error>');

            return 1;
        }

        // Display the information about a specific recipe
        if (1 === $nbRecipe) {
            $this->displayPackageInformation(current($recipes));

            return 0;
        }

        // display a resume of all packages
        $write = [
            '',
            '<bg=blue;fg=white>                      </>',
            '<bg=blue;fg=white> Available recipes.   </>',
            '<bg=blue;fg=white>                      </>',
            '',
        ];

        /** @var Recipe $recipe */
        foreach ($recipes as $name => $recipe) {
            $lockRef = $this->symfonyLock->get($name)['recipe']['ref'] ?? null;

            $additional = '';
            if (null === $lockRef && null !== $recipe->getRef()) {
                $additional = '<comment>(recipe not installed)</comment>';
            } elseif ($recipe->getRef() !== $lockRef) {
                $additional = '<comment>(update available)</comment>';
            }
            $write[] = sprintf(' * %s %s', $name, $additional);
        }

        $write[] = '';
        $write[] = 'Run:';
        $write[] = ' * <info>composer recipes vendor/package</info> to see details about a recipe.';
        $write[] = ' * <info>composer recipes:install vendor/package --force -v</info> to update that recipe.';
        $write[] = '';

        $this->getIO()->write($write);

        return 0;
    }

    private function displayPackageInformation(Recipe $recipe)
    {
        $recipeLock = $this->symfonyLock->get($recipe->getName());

        $lockRef = $recipeLock['recipe']['ref'] ?? null;
        $lockFiles = $recipeLock['files'] ?? null;

        $status = '<comment>up to date</comment>';
        if ($recipe->isAuto()) {
            $status = '<comment>auto-generated recipe</comment>';
        } elseif (null === $lockRef && null !== $recipe->getRef()) {
            $status = '<comment>recipe not installed</comment>';
        } elseif ($recipe->getRef() !== $lockRef) {
            $status = '<comment>update available</comment>';
        }

        $io = $this->getIO();
        $io->write('<info>name</info>     : '.$recipe->getName());
        $io->write('<info>version</info>  : '.$recipeLock['version']);
        if (!$recipe->isAuto()) {
            $io->write('<info>repo</info>     : '.sprintf('https://%s/tree/master/%s/%s', $recipeLock['recipe']['repo'], $recipe->getName(), $recipeLock['version']));
        }
        $io->write('<info>status</info>   : '.$status);

        if (null !== $lockFiles) {
            $io->write('<info>files</info>    : ');
            $io->write('');

            $tree = $this->generateFilesTree($lockFiles);

            $this->displayFilesTree($tree);
        }

        $io->write([
            '',
            'Update this recipe by running:',
            sprintf('<info>composer recipes:install %s --force -v</info>', $recipe->getName()),
        ]);
    }

    private function generateFilesTree(array $files): array
    {
        $tree = [];
        foreach ($files as $file) {
            $path = explode('/', $file);

            $tree = array_merge_recursive($tree, $this->addNode($path));
        }

        return $tree;
    }

    private function addNode(array $node): array
    {
        $current = array_shift($node);

        $subTree = [];
        if (null !== $current) {
            $subTree[$current] = $this->addNode($node);
        }

        return $subTree;
    }

    /**
     * Note : We do not display file modification information with Configurator like ComposerScripts, Container, DockerComposer, Dockerfile, Env, Gitignore and Makefile.
     */
    private function displayFilesTree(array $tree)
    {
        $endKey = array_key_last($tree);
        foreach ($tree as $dir => $files) {
            $treeBar = '├';
            $total = \count($files);
            if (0 === $total || $endKey === $dir) {
                $treeBar = '└';
            }

            $info = sprintf(
                '%s──%s',
                $treeBar,
                $dir
            );
            $this->writeTreeLine($info);

            $treeBar = str_replace('└', ' ', $treeBar);

            $this->displayTree($files, $treeBar);
        }
    }

    private function displayTree(array $tree, $previousTreeBar = '├', $level = 1)
    {
        $previousTreeBar = str_replace('├', '│', $previousTreeBar);
        $treeBar = $previousTreeBar.'  ├';

        $i = 0;
        $total = \count($tree);

        foreach ($tree as $dir => $files) {
            ++$i;
            if ($i === $total) {
                $treeBar = $previousTreeBar.'  └';
            }

            $info = sprintf(
                '%s──%s',
                $treeBar,
                $dir
            );
            $this->writeTreeLine($info);

            $treeBar = str_replace('└', ' ', $treeBar);

            $this->displayTree($files, $treeBar, $level + 1);
        }
    }

    private function writeTreeLine($line)
    {
        $io = $this->getIO();
        if (!$io->isDecorated()) {
            $line = str_replace(['└', '├', '──', '│'], ['`-', '|-', '-', '|'], $line);
        }

        $io->write($line);
    }
}
