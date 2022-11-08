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

class PlainRecipePatcher extends GitRecipePatcher
{
    /**
     * Applies the patch using the unix patch command.
     */
    public function applyPatch(RecipePatch $patch): bool
    {
        $withConflicts = $this->_applyPatchFile($patch);

        foreach ($patch->getDeletedFiles() as $deletedFile) {
            if ($this->filesystem->exists($this->rootDir.'/'.$deletedFile)) {
                $this->filesystem->remove($this->rootDir.'/'.$deletedFile);
            }
        }

        return $withConflicts;
    }

    private function _applyPatchFile(RecipePatch $patch)
    {
        if (!$patch->getPatch()) {
            // nothing to do!
            return true;
        }

        $patchPath = $this->rootDir.'/_flex_recipe_update.patch';
        file_put_contents($patchPath, $patch->getPatch());

        try {
            $output = '';
            $statusCode = $this->processExecutor->execute('patch -p1 -i "_flex_recipe_update.patch" -f', $output, $this->rootDir);

            if (0 === $statusCode) {
                // successful with no conflicts
                return true;
            }

            throw new \LogicException('Error applying the patch: '.$output);
        } finally {
            unlink($patchPath);
        }
    }

    protected function getIgnoredFiles(array $fileNames): array
    {
        return [];
    }
}
