<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Tests\Update;

use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Flex\Update\RecipePatch;
use Symfony\Flex\Update\RecipePatcher;

class RecipePatcherTest extends TestCase
{
    private $filesystem;

    protected function setUp(): void
    {
        $this->getFilesystem()->remove(FLEX_TEST_DIR);
        $this->getFilesystem()->mkdir(FLEX_TEST_DIR);
    }

    /**
     * @dataProvider getGeneratePatchTests
     */
    public function testGeneratePatch(array $originalFiles, array $newFiles, string $expectedPatch, array $expectedDeletedFiles = [])
    {
        $this->getFilesystem()->remove(FLEX_TEST_DIR);
        $this->getFilesystem()->mkdir(FLEX_TEST_DIR);
        // original files need to be present to avoid patcher thinking they were deleting and skipping patch
        foreach ($originalFiles as $file => $contents) {
            touch(FLEX_TEST_DIR.'/'.$file);
            if ('.gitignore' === $file) {
                file_put_contents(FLEX_TEST_DIR.'/'.$file, $contents);
            }
        }

        // make sure the test directory is a git repo
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', '"Flex Updater"'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', '""'], FLEX_TEST_DIR))->mustRun();
        if (0 !== \count($originalFiles)) {
            (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
            (new Process(['git', 'commit', '-m', '"original files"'], FLEX_TEST_DIR))->mustRun();
        }

        $patcher = new RecipePatcher(FLEX_TEST_DIR, $this->createMock(IOInterface::class));

        $patch = $patcher->generatePatch($originalFiles, $newFiles);
        $this->assertSame($expectedPatch, rtrim($patch->getPatch(), "\n"));
        $this->assertSame($expectedDeletedFiles, $patch->getDeletedFiles());

        // when testing ignored files the patch is empty
        if ('' === $expectedPatch) {
            return;
        }

        // find all "index 7d30dc7.." in patch
        $matches = [];
        preg_match_all('/index\ ([0-9|a-z]+)\.\./', $patch->getPatch(), $matches);
        $expectedBlobs = $matches[1];
        // new files (0000000) do not need a blob
        $expectedBlobs = array_values(array_filter($expectedBlobs, function ($blob) {
            return '0000000' !== $blob;
        }));

        $actualShortenedBlobs = array_map(function ($blob) {
            return substr($blob, 0, 7);
        }, array_keys($patch->getBlobs()));

        $this->assertSame($expectedBlobs, $actualShortenedBlobs);
    }

    public function getGeneratePatchTests(): iterable
    {
        yield 'updated_file' => [
            ['file1.txt' => 'Original contents', 'file2.txt' => 'Original file2'],
            ['file1.txt' => 'Updated contents', 'file2.txt' => 'Updated file2'],
            <<<EOF
diff --git a/file1.txt b/file1.txt
index 7d30dc7..1a78767 100644
--- a/file1.txt
+++ b/file1.txt
@@ -1 +1 @@
-Original contents
\ No newline at end of file
+Updated contents
\ No newline at end of file
diff --git a/file2.txt b/file2.txt
index b3b20af..4e66429 100644
--- a/file2.txt
+++ b/file2.txt
@@ -1 +1 @@
-Original file2
\ No newline at end of file
+Updated file2
\ No newline at end of file
EOF
        ];

        yield 'file_created_in_update_because_missing' => [
            [],
            ['file1.txt' => 'New file'],
            <<<EOF
diff --git a/file1.txt b/file1.txt
new file mode 100644
index 0000000..b78ca63
--- /dev/null
+++ b/file1.txt
@@ -0,0 +1 @@
+New file
\ No newline at end of file
EOF
        ];

        yield 'file_created_in_update_because_null' => [
            ['file1.txt' => null],
            ['file1.txt' => 'New file'],
            <<<EOF
diff --git a/file1.txt b/file1.txt
new file mode 100644
index 0000000..b78ca63
--- /dev/null
+++ b/file1.txt
@@ -0,0 +1 @@
+New file
\ No newline at end of file
EOF
        ];

        yield 'file_deleted_in_update_because_missing' => [
            ['file1.txt' => 'New file'],
            [],
            '',
            ['file1.txt'],
        ];

        yield 'file_deleted_in_update_because_null' => [
            ['file1.txt' => 'New file'],
            ['file1.txt' => null],
            '',
            ['file1.txt'],
        ];

        yield 'mixture_of_added_updated_removed' => [
            ['file1.txt' => 'Original file1', 'will_be_deleted.txt' => 'file to delete'],
            ['file1.txt' => 'Updated file1', 'will_be_created.text' => 'file to create'],
            <<<EOF
diff --git a/file1.txt b/file1.txt
index aed3283..cdbcdc0 100644
--- a/file1.txt
+++ b/file1.txt
@@ -1 +1 @@
-Original file1
\ No newline at end of file
+Updated file1
\ No newline at end of file
diff --git a/will_be_created.text b/will_be_created.text
new file mode 100644
index 0000000..f5074b6
--- /dev/null
+++ b/will_be_created.text
@@ -0,0 +1 @@
+file to create
\ No newline at end of file
EOF
        ,
            ['will_be_deleted.txt'],
        ];

        yield 'ignored_file' => [
            ['file1.txt' => 'Original contents', '.gitignore' => 'file1.txt'],
            ['file1.txt' => 'Updated contents', '.gitignore' => 'file1.txt'],
            '',
        ];
    }

    public function testGeneratePatchOnDeletedFile()
    {
        // make sure the target directory is empty
        $this->getFilesystem()->remove(FLEX_TEST_DIR);
        $this->getFilesystem()->mkdir(FLEX_TEST_DIR);

        $patcher = new RecipePatcher(FLEX_TEST_DIR, $this->createMock(IOInterface::class));

        // try to update a file that does not exist in the project
        $patch = $patcher->generatePatch(['.env' => 'original contents'], ['.env' => 'new contents']);
        $this->assertSame('', $patch->getPatch());
    }

    /**
     * @dataProvider getApplyPatchTests
     */
    public function testApplyPatch(array $filesCurrentlyInApp, RecipePatch $recipePatch, array $expectedFiles, bool $expectedConflicts)
    {
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], FLEX_TEST_DIR))->mustRun();

        foreach ($filesCurrentlyInApp as $file => $contents) {
            $path = FLEX_TEST_DIR.'/'.$file;
            if (!file_exists(\dirname($path))) {
                @mkdir(\dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
        if (\count($filesCurrentlyInApp) > 0) {
            (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
            (new Process(['git', 'commit', '-m', 'Committing original files'], FLEX_TEST_DIR))->mustRun();
        }

        $patcher = new RecipePatcher(FLEX_TEST_DIR, $this->createMock(IOInterface::class));
        $hadConflicts = !$patcher->applyPatch($recipePatch);

        foreach ($expectedFiles as $file => $expectedContents) {
            if (null === $expectedContents) {
                $this->assertFileDoesNotExist(FLEX_TEST_DIR.'/'.$file);

                continue;
            }
            $this->assertFileExists(FLEX_TEST_DIR.'/'.$file);
            $this->assertSame($expectedContents, file_get_contents(FLEX_TEST_DIR.'/'.$file));
        }

        $this->assertSame($expectedConflicts, $hadConflicts);
    }

    /**
     * @dataProvider getApplyPatchTests
     */
    public function testApplyPatchOnSubfolder(array $filesCurrentlyInApp, RecipePatch $recipePatch, array $expectedFiles, bool $expectedConflicts)
    {
        $mainProjectPath = FLEX_TEST_DIR;
        $subProjectPath = FLEX_TEST_DIR.'/ProjectA';

        (new Process(['git', 'init'], $mainProjectPath))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], $mainProjectPath))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], $mainProjectPath))->mustRun();

        if (!file_exists($subProjectPath)) {
            mkdir($subProjectPath, 0777, true);
        }

        foreach ($filesCurrentlyInApp as $file => $contents) {
            $path = $subProjectPath.'/'.$file;
            if (!file_exists(\dirname($path))) {
                @mkdir(\dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
        if (\count($filesCurrentlyInApp) > 0) {
            (new Process(['git', 'add', '-A'], $subProjectPath))->mustRun();
            (new Process(['git', 'commit', '-m', 'Committing original files'], $subProjectPath))->mustRun();
        }

        $patcher = new RecipePatcher($subProjectPath, $this->createMock(IOInterface::class));
        $hadConflicts = !$patcher->applyPatch($recipePatch);

        foreach ($expectedFiles as $file => $expectedContents) {
            if (null === $expectedContents) {
                $this->assertFileDoesNotExist($subProjectPath.'/'.$file);

                continue;
            }
            $this->assertFileExists($subProjectPath.'/'.$file);
            $this->assertSame($expectedContents, file_get_contents($subProjectPath.'/'.$file));
        }

        $this->assertSame($expectedConflicts, $hadConflicts);
    }

    public function getApplyPatchTests(string $testMethodName): iterable
    {
        $projectRootPath = ('testApplyPatchOnSubfolder' === $testMethodName) ? 'ProjectA/' : '';
        $files = $this->getFilesForPatching($projectRootPath);
        $dotEnvClean = $files['dot_env_clean'];
        $packageJsonConflict = $files['package_json_conflict'];
        $webpackEncoreAdded = $files['webpack_encore_added'];
        $securityRemoved = $files['security_removed'];

        yield 'cleanly_patch_one_file' => [
            ['.env' => $dotEnvClean['in_app']],
            new RecipePatch(
                $dotEnvClean['patch'],
                [$dotEnvClean['hash'] => $dotEnvClean['blob']],
                []
            ),
            ['.env' => $dotEnvClean['expected']],
            false,
        ];

        yield 'conflict_on_one_file' => [
            ['package.json' => $packageJsonConflict['in_app']],
            new RecipePatch(
                $packageJsonConflict['patch'],
                [$packageJsonConflict['hash'] => $packageJsonConflict['blob']],
                []
            ),
            ['package.json' => $packageJsonConflict['expected']],
            true,
        ];

        yield 'add_one_new_file' => [
            // file is not currently in the app
            [],
            new RecipePatch(
                $webpackEncoreAdded['patch'],
                // no blobs needed for a new file
                [],
                []
            ),
            ['config/packages/webpack_encore.yaml' => $webpackEncoreAdded['expected']],
            false,
        ];

        yield 'removed_one_file' => [
            ['config/packages/security.yaml' => $securityRemoved['in_app']],
            new RecipePatch(
                '',
                [$securityRemoved['hash'] => $securityRemoved['blob']],
                ['config/packages/security.yaml']
            ),
            // expected to be deleted
            ['config/packages/security.yaml' => null],
            false,
        ];

        yield 'complex_mixture' => [
            [
                '.env' => $dotEnvClean['in_app'],
                'package.json' => $packageJsonConflict['in_app'],
                // webpack_encore.yaml not in starting project
                'config/packages/security.yaml' => $securityRemoved['in_app'],
            ],
            new RecipePatch(
                $dotEnvClean['patch']."\n".$packageJsonConflict['patch']."\n".$webpackEncoreAdded['patch'],
                [
                    $dotEnvClean['hash'] => $dotEnvClean['blob'],
                    $packageJsonConflict['hash'] => $packageJsonConflict['blob'],
                    $webpackEncoreAdded['hash'] => $webpackEncoreAdded['blob'],
                    $securityRemoved['hash'] => $securityRemoved['blob'],
                ],
                [
                    'config/packages/security.yaml',
                ]
            ),
            [
                '.env' => $dotEnvClean['expected'],
                'package.json' => $packageJsonConflict['expected'],
                'config/packages/webpack_encore.yaml' => $webpackEncoreAdded['expected'],
                'config/packages/security.yaml' => null,
            ],
            true,
        ];
    }

    /**
     * @dataProvider getIntegrationTests
     */
    public function testIntegration(bool $useNullForMissingFiles)
    {
        $files = $this->getFilesForPatching();
        (new Process(['git', 'init'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], FLEX_TEST_DIR))->mustRun();

        $startingFiles = [
            '.env' => $files['dot_env_clean']['in_app'],
            'package.json' => $files['package_json_conflict']['in_app'],
            // no webpack_encore.yaml in app
            'config/packages/security.yaml' => $files['security_removed']['in_app'],
            // no cache.yaml in app - the update patch will be skipped
        ];
        foreach ($startingFiles as $file => $contents) {
            if (!file_exists(\dirname(FLEX_TEST_DIR.'/'.$file))) {
                @mkdir(\dirname(FLEX_TEST_DIR.'/'.$file), 0777, true);
            }

            file_put_contents(FLEX_TEST_DIR.'/'.$file, $contents);
        }
        // commit the files in the app
        (new Process(['git', 'add', '-A'], FLEX_TEST_DIR))->mustRun();
        (new Process(['git', 'commit', '-m', 'committing in app start files'], FLEX_TEST_DIR))->mustRun();

        $patcher = new RecipePatcher(FLEX_TEST_DIR, $this->createMock(IOInterface::class));
        $originalFiles = [
            '.env' => $files['dot_env_clean']['original_recipe'],
            'package.json' => $files['package_json_conflict']['original_recipe'],
            'config/packages/security.yaml' => $files['security_removed']['original_recipe'],
            'config/packages/cache.yaml' => 'original cache.yaml',
        ];
        if ($useNullForMissingFiles) {
            $originalFiles['config/packages/webpack_encore.yaml'] = null;
        }

        $updatedFiles = [
            '.env' => $files['dot_env_clean']['updated_recipe'],
            'package.json' => $files['package_json_conflict']['updated_recipe'],
            'config/packages/webpack_encore.yaml' => $files['webpack_encore_added']['updated_recipe'],
            'config/packages/cache.yaml' => 'updated cache.yaml',
        ];
        if ($useNullForMissingFiles) {
            $updatedFiles['config/packages/security.yaml'] = null;
        }

        $recipePatch = $patcher->generatePatch($originalFiles, $updatedFiles);
        $appliedCleanly = $patcher->applyPatch($recipePatch);

        $this->assertFalse($appliedCleanly);
        $this->assertSame($files['dot_env_clean']['expected'], file_get_contents(FLEX_TEST_DIR.'/.env'));
        $this->assertSame($files['package_json_conflict']['expected'], file_get_contents(FLEX_TEST_DIR.'/package.json'));
        $this->assertSame($files['webpack_encore_added']['expected'], file_get_contents(FLEX_TEST_DIR.'/config/packages/webpack_encore.yaml'));
        $this->assertFileDoesNotExist(FLEX_TEST_DIR.'/security.yaml');
    }

    public function getIntegrationTests(): iterable
    {
        yield 'missing_files_set_to_null' => [true];
        yield 'missing_files_not_in_array' => [false];
    }

    /**
     * Returns files with keys:
     *      * filename
     *      * in_app:   Contents the file currently has in the app
     *      * patch     The diff/patch to apply to the file
     *      * expected  The expected final contents
     *      * hash      hash-object used for the blob address
     *      * blob      The raw file blob of the original recipe contents
     *      * original_recipe
     *      * updated_recipe.
     */
    private function getFilesForPatching(string $projectPath = ''): array
    {
        $files = [
            // .env
            'dot_env_clean' => [
                'filename' => '.env',
                'original_recipe' => <<<EOF
###> symfony/framework-bundle ###
APP_ENV=dev
APP_SECRET=cd0019c56963e76bacd77eee67e1b222
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
# Format described at https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db
###< doctrine/doctrine-bundle ###
EOF
                , 'updated_recipe' => <<<EOF
###> symfony/framework-bundle ###
APP_ENV=dev
APP_SECRET=cd0019c56963e76bacd77eee67e1b222
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
# Format described at https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
# For an SQL-HEAVY database, use: "sqlheavy:///%kernel.project_dir%/var/data.db"
DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db
###< doctrine/doctrine-bundle ###
EOF
                , 'in_app' => <<<EOF
###> symfony/framework-bundle ###
APP_ENV=CHANGED_TO_PROD_ENVIRONMENT
APP_SECRET=cd0019c56963e76bacd77eee67e1b222
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
# Format described at https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db
###< doctrine/doctrine-bundle ###
EOF
                , 'expected' => <<<EOF
###> symfony/framework-bundle ###
APP_ENV=CHANGED_TO_PROD_ENVIRONMENT
APP_SECRET=cd0019c56963e76bacd77eee67e1b222
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
# Format described at https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
# For an SQL-HEAVY database, use: "sqlheavy:///%kernel.project_dir%/var/data.db"
DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db
###< doctrine/doctrine-bundle ###
EOF
            ],

            // package.json
            'package_json_conflict' => [
                'filename' => 'package.json',
                'original_recipe' => <<<EOF
{
    "devDependencies": {
        "@hotwired/stimulus": "^2.0.0",
        "@symfony/stimulus-bridge": "^3.0.0",
        "@symfony/webpack-encore": "^1.4.0"
    }
}
EOF
                , 'updated_recipe' => <<<EOF
{
    "devDependencies": {
        "@hotwired/stimulus": "^3.0.0",
        "@symfony/stimulus-bridge": "^3.0.0",
        "@symfony/webpack-encore": "^1.7.0"
    }
}
EOF
                , 'in_app' => <<<EOF
{
    "devDependencies": {
        "@hotwired/stimulus": "^2.1.0",
        "@symfony/stimulus-bridge": "^3.0.0",
        "@symfony/webpack-encore": "^1.4.0"
    }
}
EOF
                , 'expected' => <<<EOF
{
    "devDependencies": {
<<<<<<< ours
        "@hotwired/stimulus": "^2.1.0",
=======
        "@hotwired/stimulus": "^3.0.0",
>>>>>>> theirs
        "@symfony/stimulus-bridge": "^3.0.0",
        "@symfony/webpack-encore": "^1.7.0"
    }
}
EOF
            ],

            // config/packages/webpack_encore.yaml
            'webpack_encore_added' => [
                'filename' => 'config/packages/webpack_encore.yaml',
                'original_recipe' => null,
                'updated_recipe' => <<<EOF
webpack_encore:
    # The path where Encore is building the assets - i.e. Encore.setOutputPath()
    output_path: '%kernel.project_dir%/public/build'
    # If multiple builds are defined (as shown below), you can disable the default build:
    # output_path: false
EOF
                , 'in_app' => null,
                'expected' => <<<EOF
webpack_encore:
    # The path where Encore is building the assets - i.e. Encore.setOutputPath()
    output_path: '%kernel.project_dir%/public/build'
    # If multiple builds are defined (as shown below), you can disable the default build:
    # output_path: false
EOF
            ],

            // config/packages/security.yaml
            'security_removed' => [
                'filename' => 'config/packages/security.yaml',
                'original_recipe' => <<<EOF
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
EOF
                , 'updated_recipe' => null,
                'in_app' => <<<EOF
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
EOF
                , 'expected' => null,
            ],
        ];

        // calculate the patch, hash & blob from the files for convenience
        // (it's easier than storing those in the test directly)
        foreach ($files as $key => $data) {
            $files[$key] = array_merge(
                $data,
                $this->generatePatchData($projectPath.$data['filename'], $data['original_recipe'], $data['updated_recipe'])
            );
        }

        return $files;
    }

    private function generatePatchData(string $filename, ?string $start, ?string $end): array
    {
        $dir = sys_get_temp_dir().'/_flex_diff';
        if (file_exists($dir)) {
            $this->getFilesystem()->remove($dir);
        }
        @mkdir($dir);
        (new Process(['git', 'init'], $dir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Unit test'], $dir))->mustRun();
        (new Process(['git', 'config', 'user.email', ''], $dir))->mustRun();

        if (!file_exists(\dirname($dir.'/'.$filename))) {
            @mkdir(\dirname($dir.'/'.$filename), 0777, true);
        }

        $hash = null;
        $blob = null;
        if (null !== $start) {
            file_put_contents($dir.'/'.$filename, $start);
            (new Process(['git', 'add', '-A'], $dir))->mustRun();
            (new Process(['git', 'commit', '-m', 'adding file'], $dir))->mustRun();

            $process = (new Process(['git', 'hash-object', $filename], $dir))->mustRun();
            $hash = trim($process->getOutput());

            $hashStart = substr($hash, 0, 2);
            $hashEnd = substr($hash, 2);
            $blob = file_get_contents($dir.'/.git/objects/'.$hashStart.'/'.$hashEnd);
        }

        if (null === $end) {
            unlink($dir.'/'.$filename);
        } else {
            file_put_contents($dir.'/'.$filename, $end);
        }
        (new Process(['git', 'add', '-A'], $dir))->mustRun();
        $process = (new Process(['git', 'diff', '--cached'], $dir))->mustRun();
        $diff = $process->getOutput();

        return [
            'patch' => $diff,
            'hash' => $hash,
            'blob' => $blob,
        ];
    }

    private function getFilesystem(): Filesystem
    {
        if (null === $this->filesystem) {
            $this->filesystem = new Filesystem();
        }

        return $this->filesystem;
    }
}
