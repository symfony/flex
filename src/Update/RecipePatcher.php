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

class RecipePatcher
{
    private $rootDir;
    private $filesystem;
    private $io;
    private $processExecutor;

    public function __construct(string $rootDir, IOInterface $io)
    {
        $this->rootDir = $rootDir;
        $this->filesystem = new Filesystem();
        $this->io = $io;
        $this->processExecutor = new ProcessExecutor($io);
    }

    /**
     * Applies the patch. If it fails unexpectedly, an exception will be thrown.
     *
     * @return bool returns true if fully successful, false if conflicts were encountered
     */
    public function applyPatch(RecipePatch $patch): bool
    {
        if (!$patch->getPatch()) {
            // nothing to do!
            return true;
        }

        $addedBlobs = $this->addMissingBlobs($patch->getBlobs());

        $patchPath = $this->rootDir.'/_flex_recipe_update.patch';
        file_put_contents($patchPath, $patch->getPatch());

        try {
            $this->execute('git update-index --refresh', $this->rootDir);

            $output = '';
            $statusCode = $this->processExecutor->execute('git apply "_flex_recipe_update.patch" -3', $output, $this->rootDir);

            if (0 === $statusCode) {
                // successful with no conflicts
                return true;
            }

            if (false !== strpos($this->processExecutor->getErrorOutput(), 'with conflicts')) {
                // successful with conflicts
                return false;
            }

            throw new \LogicException('Error applying the patch: '.$this->processExecutor->getErrorOutput());
        } finally {
            unlink($patchPath);
            // clean up any temporary blobs
            foreach ($addedBlobs as $filename) {
                unlink($filename);
            }
        }
    }

    public function generatePatch(array $originalFiles, array $newFiles): RecipePatch
    {
        // null implies "file does not exist"
        $originalFiles = array_filter($originalFiles, function ($file) {
            return null !== $file;
        });
        $newFiles = array_filter($newFiles, function ($file) {
            return null !== $file;
        });

        // find removed files and add them so they will be deleted
        foreach ($originalFiles as $file => $contents) {
            if (!isset($newFiles[$file])) {
                $newFiles[$file] = null;
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

        $tmpPath = sys_get_temp_dir().'/_flex_recipe_update'.uniqid(mt_rand(), true);
        $this->filesystem->mkdir($tmpPath);

        try {
            $this->execute('git init', $tmpPath);
            $this->execute('git config commit.gpgsign false', $tmpPath);
            $this->execute('git config user.name "Flex Updater"', $tmpPath);
            $this->execute('git config user.email ""', $tmpPath);

            $blobs = [];
            if (\count($originalFiles) > 0) {
                $this->writeFiles($originalFiles, $tmpPath);
                $this->execute('git add -A', $tmpPath);
                $this->execute('git commit -m "original files"', $tmpPath);

                $blobs = $this->generateBlobs($originalFiles, $tmpPath);
            }

            $this->writeFiles($newFiles, $tmpPath);
            $this->execute('git add -A', $tmpPath);

            $patchString = $this->execute('git diff --cached', $tmpPath);
            $removedPatches = [];
            $patchString = DiffHelper::removeFilesFromPatch($patchString, $deletedModifiedFiles, $removedPatches);

            return new RecipePatch(
                $patchString,
                $blobs,
                $removedPatches
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
            throw new \LogicException(sprintf('Command "%s" failed: "%s". Output: "%s".', $command, $this->processExecutor->getErrorOutput(), $output));
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
     */
    private function addMissingBlobs(array $blobs): array
    {
        $addedBlobs = [];
        foreach ($blobs as $hash => $contents) {
            $blobPath = $this->rootDir.'/'.$this->getBlobPath($hash);
            if (file_exists($blobPath)) {
                continue;
            }

            $addedBlobs[] = $blobPath;
            if (!file_exists(\dirname($blobPath))) {
                $this->filesystem->mkdir(\dirname($blobPath));
            }
            file_put_contents($blobPath, $contents);
        }

        return $addedBlobs;
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
            $addedBlobs[$hash] = file_get_contents($originalFilesRoot.'/'.$this->getBlobPath($hash));
        }

        return $addedBlobs;
    }

    private function getBlobPath(string $hash): string
    {
        $hashStart = substr($hash, 0, 2);
        $hashEnd = substr($hash, 2);

        return '.git/objects/'.$hashStart.'/'.$hashEnd;
    }
}
