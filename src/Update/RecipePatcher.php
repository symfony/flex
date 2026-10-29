<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Update;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Lock;

class RecipePatcher
{
    private $rootDir;
    private $filesystem;
    private $io;
    private $processExecutor;
    private $symfonyLock;

    public function __construct(string $rootDir, IOInterface $io, Lock $symfonyLock)
    {
        $this->rootDir = $rootDir;
        $this->filesystem = new Filesystem();
        $this->io = $io;
        $this->symfonyLock = $symfonyLock;
        $this->processExecutor = new ProcessExecutor($io);
    }

    /**
     * Applies the patch. If it fails unexpectedly, an exception will be thrown.
     *
     * @return bool returns true if fully successful, false if conflicts were encountered
     */
    public function applyPatch(RecipePatch $patch, ?string $packageName = null): bool
    {
        $withConflicts = $this->_applyPatchFile($patch);
        $lockedFiles = $packageName ? array_count_values(array_merge(...array_column(array_filter($this->symfonyLock->all(), fn ($package) => $package !== $packageName, \ARRAY_FILTER_USE_KEY), 'files'))) : [];

        $nonRemovableFiles = [];
        foreach ($patch->getDeletedFiles() as $deletedFile) {
            if (isset($lockedFiles[$deletedFile])) {
                $nonRemovableFiles[] = $deletedFile;

                continue;
            }

            $this->execute(\sprintf('git rm %s', ProcessExecutor::escape($deletedFile)), $this->rootDir);
        }

        if ($nonRemovableFiles) {
            $this->io->writeError('  <warning>The following files were removed in the recipe, but are still referenced by other recipes. You might need to adjust them manually:</warning>');
            foreach ($nonRemovableFiles as $file) {
                $this->io->writeError('      - '.$file);
            }

            $this->io->writeError('');
        }

        if ($patch->getKeptFiles()) {
            $this->io->writeError('  <warning>The following files were removed in the recipe, but they were kept because you modified them. You might need to remove them manually:</warning>');
            foreach ($patch->getKeptFiles() as $file) {
                $this->io->writeError('      - '.$file);
            }

            $this->io->writeError('');
        }

        return $withConflicts;
    }

    public function generatePatch(array $originalFiles, array $newFiles): RecipePatch
    {
        $ignoredFiles = $this->getIgnoredFiles(array_keys($originalFiles) + array_keys($newFiles));

        // null implies "file does not exist"
        $originalFiles = array_filter($originalFiles, function ($file, $fileName) use ($ignoredFiles) {
            return null !== $file && !\in_array($fileName, $ignoredFiles);
        }, \ARRAY_FILTER_USE_BOTH);

        $newFiles = array_filter($newFiles, function ($file, $fileName) use ($ignoredFiles) {
            return null !== $file && !\in_array($fileName, $ignoredFiles);
        }, \ARRAY_FILTER_USE_BOTH);

        $deletedFiles = [];
        $keptFiles = [];
        // find removed files & record that they are deleted, unless they were modified in the project
        // unset them from originalFiles to avoid unnecessary blobs being added
        foreach ($originalFiles as $file => $contents) {
            if (isset($newFiles[$file])) {
                continue;
            }

            unset($originalFiles[$file]);

            $path = $this->rootDir.'/'.$file;
            if (!file_exists($path)) {
                continue;
            }

            if (str_replace("\r\n", "\n", file_get_contents($path)) === str_replace("\r\n", "\n", $contents)) {
                $deletedFiles[] = $file;
            } else {
                $keptFiles[] = $file;
            }
        }

        // If a file is being modified, but does not exist in the current project,
        // it cannot be patched. We generate the diff for these, but then remove
        // it from the patch (and optionally report this diff to the user).
        $modifiedFiles = array_intersect_key(array_keys($originalFiles), array_keys($newFiles));
        $deletedModifiedFiles = [];
        foreach ($modifiedFiles as $modifiedFile) {
            if (!file_exists($this->rootDir.'/'.$modifiedFile) && $originalFiles[$modifiedFile] !== $newFiles[$modifiedFile]) {
                $deletedModifiedFiles[] = $modifiedFile;
            }
        }

        // Use git binary to get project path from repository root
        $prefix = trim($this->execute('git rev-parse --show-prefix', $this->rootDir));
        $objectFormat = trim($this->execute('git rev-parse --show-object-format', $this->rootDir));
        $tmpPath = sys_get_temp_dir().'/_flex_recipe_update'.uniqid(mt_rand(), true);
        $this->filesystem->mkdir($tmpPath);

        try {
            $this->execute('git init', $tmpPath);
            // the blobs must be hashed with the algorithm of the app, which can differ from the default one
            if ($objectFormat !== trim($this->execute('git rev-parse --show-object-format', $tmpPath))) {
                $this->filesystem->remove($tmpPath.'/.git');
                $this->execute('git init --object-format='.ProcessExecutor::escape($objectFormat), $tmpPath);
            }
            $this->execute('git config commit.gpgsign false', $tmpPath);
            $this->execute('git config user.name "Flex Updater"', $tmpPath);
            $this->execute('git config user.email ""', $tmpPath);

            $blobs = [];
            if (\count($originalFiles) > 0) {
                $this->writeFiles($originalFiles, $tmpPath);
                $this->execute('git add -A', $tmpPath);
                $this->execute('git commit -n -m "original files"', $tmpPath);

                $blobs = $this->generateBlobs($originalFiles, $tmpPath);
            }

            $this->writeFiles($newFiles, $tmpPath);
            $this->execute('git add -A', $tmpPath);

            $patchString = $this->execute(\sprintf('git diff --cached --src-prefix "a/%s" --dst-prefix "b/%s"', $prefix, $prefix), $tmpPath);
            $removedPatches = [];
            $patchString = DiffHelper::removeFilesFromPatch($patchString, $deletedModifiedFiles, $removedPatches, $prefix);

            return new RecipePatch(
                $patchString,
                $blobs,
                $deletedFiles,
                $removedPatches,
                $keptFiles
            );
        } finally {
            try {
                $this->filesystem->remove($tmpPath);
            } catch (IOException $e) {
                // this can sometimes fail due to git file permissions
                // if that happens, just leave it: we're in the temp directory anyways
            }
        }
    }

    private function writeFiles(array $files, string $directory): void
    {
        foreach ($files as $filename => $contents) {
            $path = $directory.'/'.$filename;
            if (null === $contents) {
                if (file_exists($path)) {
                    unlink($path);
                }

                continue;
            }

            if (!file_exists(\dirname($path))) {
                $this->filesystem->mkdir(\dirname($path));
            }
            file_put_contents($path, $contents);
        }
    }

    private function execute(string $command, string $cwd): string
    {
        $output = '';
        $statusCode = $this->processExecutor->execute($command, $output, $cwd);

        if (0 !== $statusCode) {
            throw new \LogicException(\sprintf('Command "%s" failed: "%s". Output: "%s".', $command, $this->processExecutor->getErrorOutput(), $output));
        }

        return $output;
    }

    /**
     * Adds git blobs for each original file.
     *
     * For patching to work, each original file & contents needs to be
     * available to git as a blob. This is because the patch contains
     * the ref to the original blob, and git uses that to find the
     * original file (which is needed for the 3-way merge).
     *
     * The blobs are not removed afterwards. The index can still use them, for
     * example as the base of a conflict, and "git gc" prunes them once unused.
     */
    private function addBlobs(array $blobs): void
    {
        if (!$blobs) {
            return;
        }

        $tmpPath = sys_get_temp_dir().'/_flex_recipe_blobs'.uniqid(mt_rand(), true);
        $this->filesystem->mkdir($tmpPath);

        try {
            $files = [];
            foreach (array_values($blobs) as $i => $contents) {
                file_put_contents($files[] = $tmpPath.'/'.$i, $contents);
            }

            $this->execute('git hash-object -w --no-filters -- '.implode(' ', array_map([ProcessExecutor::class, 'escape'], $files)), $this->rootDir);
        } finally {
            $this->filesystem->remove($tmpPath);
        }
    }

    private function generateBlobs(array $originalFiles, string $originalFilesRoot): array
    {
        $addedBlobs = [];
        foreach ($originalFiles as $filename => $contents) {
            // if the file didn't originally exist, no blob needed
            if (!file_exists($originalFilesRoot.'/'.$filename)) {
                continue;
            }

            $hash = trim($this->execute('git hash-object '.ProcessExecutor::escape($filename), $originalFilesRoot));
            $addedBlobs[$hash] = $this->execute('git cat-file blob '.$hash, $originalFilesRoot);
        }

        return $addedBlobs;
    }

    private function _applyPatchFile(RecipePatch $patch)
    {
        if (!$patch->getPatch()) {
            // nothing to do!
            return true;
        }

        $this->addBlobs($patch->getBlobs());

        $patchPath = $this->rootDir.'/_flex_recipe_update.patch';
        file_put_contents($patchPath, $patch->getPatch());

        try {
            $this->execute('git update-index --refresh', $this->rootDir);

            if ($untrackedFiles = $this->getUntrackedFiles()) {
                throw new UntrackedFilesException(\sprintf('The recipe update cannot be applied because the following files are not tracked by git: "%s". Commit them or move them away, then try again.', implode('", "', $untrackedFiles)));
            }

            $output = '';
            $statusCode = $this->processExecutor->execute('git apply "_flex_recipe_update.patch" -3', $output, $this->rootDir);

            if (0 === $statusCode) {
                // successful with no conflicts
                return true;
            }

            // git applies all or nothing, so unmerged entries mean that the patch was applied with conflicts
            $errorOutput = $this->processExecutor->getErrorOutput();
            if ('' !== trim($this->execute('git ls-files --unmerged', $this->rootDir))) {
                return false;
            }

            throw new \LogicException('Error applying the patch: '.$errorOutput);
        } finally {
            unlink($patchPath);
        }
    }

    /**
     * Returns the files of the patch that exist in the app but are not in the git index.
     *
     * "git apply -3" cannot patch or create these files, and then applies nothing.
     */
    private function getUntrackedFiles(): array
    {
        $pathspecs = [];
        $numstat = $this->execute('git apply --numstat -z "_flex_recipe_update.patch"', $this->rootDir);
        foreach (explode("\0", $numstat) as $line) {
            // paths are relative to the root of the repository
            if (preg_match('/^[-\d]+\t[-\d]+\t(.+)/s', $line, $m)) {
                $pathspecs[] = ProcessExecutor::escape(':(top,literal)'.$m[1]);
            }
        }

        if (!$pathspecs) {
            return [];
        }

        return array_values(array_filter(explode("\0", $this->execute('git ls-files --others -z -- '.implode(' ', $pathspecs), $this->rootDir))));
    }

    private function getIgnoredFiles(array $fileNames): array
    {
        $args = implode(' ', array_map([ProcessExecutor::class, 'escape'], $fileNames));
        $output = '';
        $this->processExecutor->execute(\sprintf('git check-ignore %s', $args), $output, $this->rootDir);

        return $this->processExecutor->splitLines($output);
    }
}
