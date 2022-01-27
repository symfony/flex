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

use PHPUnit\Framework\TestCase;
use Symfony\Flex\Update\DiffHelper;

class DiffHelperTest extends TestCase
{
    /**
     * @dataProvider getRemoveFilesFromPatchTests
     */
    public function testRemoveFilesFromPatch(string $patch, array $filesToRemove, string $expectedPatch, array $expectedRemovedPatches)
    {
        $removedPatches = [];
        $this->assertSame($expectedPatch, DiffHelper::removeFilesFromPatch($patch, $filesToRemove, $removedPatches));

        $this->assertSame($expectedRemovedPatches, $removedPatches);
    }

    public function getRemoveFilesFromPatchTests(): iterable
    {
        $patch = <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'
EOF;

        yield 'remove_first_file' => [
            $patch,
            ['.env'],
            <<<EOF
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'

EOF
            , [
                '.env' => <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
EOF
            ],
        ];

        yield 'remove_middle_file' => [
            $patch,
            ['config/packages/doctrine.yaml'],
            <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'

EOF
            , [
                'config/packages/doctrine.yaml' => <<<EOF
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
EOF
            ],
        ];

        yield 'remove_last_file' => [
            $patch,
            ['config/packages/test/doctrine.yaml'],
            <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:

EOF
            , [
                'config/packages/test/doctrine.yaml' => <<<EOF
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'
EOF
            ],
        ];

        yield 'remove_multiple_files' => [
            $patch,
            ['config/packages/test/doctrine.yaml', 'config/packages/doctrine.yaml'],
            <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:

EOF
            , [
                'config/packages/test/doctrine.yaml' => <<<EOF
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'
EOF
                , 'config/packages/doctrine.yaml' => <<<EOF
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
EOF
            ],
        ];

        yield 'remove_everything' => [
            $patch,
            ['config/packages/test/doctrine.yaml', 'config/packages/doctrine.yaml', '.env', 'config/packages/prod/doctrine.yaml'],
            '',
            [
                'config/packages/test/doctrine.yaml' => <<<EOF
diff --git a/config/packages/test/doctrine.yaml b/config/packages/test/doctrine.yaml
new file mode 100644
index 0000000..34c2ebc
--- /dev/null
+++ b/config/packages/test/doctrine.yaml
@@ -0,0 +1,4 @@
+doctrine:
+    dbal:
+        # "TEST_TOKEN" is typically set by ParaTest
+        dbname_suffix: '_test%env(default::TEST_TOKEN)%'
EOF
                , 'config/packages/doctrine.yaml' => <<<EOF
diff --git a/config/packages/doctrine.yaml b/config/packages/doctrine.yaml
index 5e80e77..c319176 100644
--- a/config/packages/doctrine.yaml
+++ b/config/packages/doctrine.yaml
@@ -4,7 +4,7 @@ doctrine:
         # either here or in the DATABASE_URL env var (see .env file)
-        #server_version: '5.7'
+        #server_version: '13'
     orm:
         auto_generate_proxy_classes: true
EOF
                , '.env' => <<<EOF
diff --git a/.env b/.env
index ea34452..daaeb63 100644
--- a/.env
+++ b/.env
@@ -24,8 +24,9 @@ APP_SECRET=cd0019c56963e76bacd77eee67e1b222

-# For an SQLite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
-# For a PostgreSQL database, use: "postgresql://db_user:db_password@127.0.0.1:5432/db_name?serverVersion=11&charset=utf8"
 # IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
EOF
                , 'config/packages/prod/doctrine.yaml' => <<<EOF
diff --git a/config/packages/prod/doctrine.yaml b/config/packages/prod/doctrine.yaml
index 8ae31a3..17299e2 100644
--- a/config/packages/prod/doctrine.yaml
+++ b/config/packages/prod/doctrine.yaml
@@ -1,16 +1,13 @@
         auto_generate_proxy_classes: false
-        metadata_cache_driver:
-            type: pool
-            pool: doctrine.system_cache_pool
         query_cache_driver:
EOF
            ],
        ];
    }
}
