<?php

namespace Symfony\Flex\Tests;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\NullIO;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Semver\Constraint\MatchAllConstraint;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\PackageResolver;
use Symfony\Flex\Unpack\Operation;
use Symfony\Flex\Unpack\Result;
use Symfony\Flex\Unpacker;

class UnpackerTest extends TestCase
{
    /**
     * Shared dependencies must be present once in the composer.json file.
     *
     * Context:
     *
     *   - There are two packs: "pack_foo" and "pack_bar"
     *   - Both points to a package named "real"
     *   - "pack_foo" is present in the "require" section
     *   - "pack_bar" is present in the "require-dev" section
     *
     * Expected result:
     *
     *   - "real" package MUST be present ONLY in "require" section
     */
    public function testDoNotDuplicateEntry()
    {
        // Setup project

        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';

        @mkdir(FLEX_TEST_DIR);
        @unlink($composerJsonPath);
        file_put_contents($composerJsonPath, '{}');

        $originalEnvComposer = $_SERVER['COMPOSER'] ?? null;
        $_SERVER['COMPOSER'] = $composerJsonPath;
        // composer 2.1 and lower support
        putenv('COMPOSER='.$composerJsonPath);

        // Setup packages

        $realPkg = new Package('real', '1.0.0', '1.0.0');
        $realPkgLink = new Link('lorem', 'real', class_exists(MatchAllConstraint::class) ? new MatchAllConstraint() : null, 'wraps', '1.0.0');

        $virtualPkgFoo = new Package('pack_foo', '1.0.0', '1.0.0');
        $virtualPkgFoo->setType('symfony-pack');
        $virtualPkgFoo->setRequires(['real' => $realPkgLink]);

        $virtualPkgBar = new Package('pack_bar', '1.0.0', '1.0.0');
        $virtualPkgBar->setType('symfony-pack');
        $virtualPkgBar->setRequires(['real' => $realPkgLink]);

        $packages = [$realPkg, $virtualPkgFoo, $virtualPkgBar];

        // Setup Composer

        $repManager = $this->createStub(RepositoryManager::class);
        $repManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));

        $composer = new Composer();
        $composer->setRepositoryManager($repManager);

        // Unpack

        $resolver = $this->createStub(PackageResolver::class);

        $unpacker = new Unpacker($composer, $resolver);

        $operation = new Operation(true, false);
        $operation->addPackage('pack_foo', '*', false);
        $operation->addPackage('pack_bar', '*', true);

        $unpacker->unpack($operation);

        // Check

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);

        $this->assertArrayHasKey('require', $composerJson);
        $this->assertArrayHasKey('real', $composerJson['require']);
        $this->assertArrayNotHasKey('require-dev', $composerJson);

        // Restore

        if ($originalEnvComposer) {
            $_SERVER['COMPOSER'] = $originalEnvComposer;
        } else {
            unset($_SERVER['COMPOSER']);
        }
        // composer 2.1 and lower support
        putenv('COMPOSER='.$originalEnvComposer);
        @unlink($composerJsonPath);
    }

    public function testUpdateLockKeepsEmptyRequireDev()
    {
        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';
        $composerLockPath = FLEX_TEST_DIR.'/composer.lock';

        @mkdir(FLEX_TEST_DIR);
        file_put_contents($composerJsonPath, <<<'JSON'
            {
                "require": {
                    "pack": "*"
                },
                "require-dev": {
                },
                "config": {
                    "sort-packages": true
                }
            }

            JSON);
        file_put_contents($composerLockPath, "{}\n");

        $originalEnvComposer = $_SERVER['COMPOSER'] ?? null;
        $_SERVER['COMPOSER'] = $composerJsonPath;
        // composer 2.1 and lower support
        putenv('COMPOSER='.$composerJsonPath);

        $realPkg = new Package('real', '1.0.0', '1.0.0');
        $pack = new Package('pack', '1.0.0', '1.0.0');
        $pack->setType('symfony-pack');
        $pack->setRequires(['real' => new Link('pack', 'real', new MatchAllConstraint(), 'requires', '^1.0')]);

        $repManager = $this->createStub(RepositoryManager::class);
        $repManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository([$realPkg, $pack]));

        $locker = $this->createStub(Locker::class);
        $locker->method('getLockData')->willReturn([
            'packages' => [['name' => 'pack', 'version' => '1.0.0'], ['name' => 'real', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]);

        $composer = new Composer();
        $composer->setRepositoryManager($repManager);
        $composer->setLocker($locker);
        $composer->setInstallationManager($this->createStub(InstallationManager::class));

        $unpacker = new Unpacker($composer, $this->createStub(PackageResolver::class));

        $operation = new Operation(true, false);
        $operation->addPackage('pack', '*', false);

        $result = $unpacker->unpack($operation);
        $unpacker->updateLock($result, new NullIO());

        $this->assertSame(<<<'JSON'
            {
                "require": {
                    "real": "^1.0"
                },
                "require-dev": {
                },
                "config": {
                    "sort-packages": true
                }
            }

            JSON, file_get_contents($composerJsonPath));

        if ($originalEnvComposer) {
            $_SERVER['COMPOSER'] = $originalEnvComposer;
        } else {
            unset($_SERVER['COMPOSER']);
        }
        // composer 2.1 and lower support
        putenv('COMPOSER='.$originalEnvComposer);
        @unlink($composerJsonPath);
        @unlink($composerLockPath);
    }

    public function testUpdateLockKeepsEmptyObjectsInLockFile()
    {
        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';
        $composerLockPath = FLEX_TEST_DIR.'/composer.lock';

        @mkdir(FLEX_TEST_DIR);
        file_put_contents($composerJsonPath, "{}\n");
        file_put_contents($composerLockPath, "{}\n");

        $originalEnvComposer = $_SERVER['COMPOSER'] ?? null;
        $_SERVER['COMPOSER'] = $composerJsonPath;
        // composer 2.1 and lower support
        putenv('COMPOSER='.$composerJsonPath);

        $repManager = $this->createStub(RepositoryManager::class);
        $repManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository());

        $locker = $this->createStub(Locker::class);
        $locker->method('getLockData')->willReturn([
            'packages' => [],
            'packages-dev' => [],
            'stability-flags' => [],
            'platform' => [],
            'platform-dev' => [],
        ]);

        $composer = new Composer();
        $composer->setRepositoryManager($repManager);
        $composer->setLocker($locker);
        $composer->setInstallationManager($this->createStub(InstallationManager::class));

        $unpacker = new Unpacker($composer, $this->createStub(PackageResolver::class));
        $unpacker->updateLock(new Result(), new NullIO());

        $lockData = json_decode(file_get_contents($composerLockPath));

        $this->assertEquals(new \stdClass(), $lockData->{'stability-flags'});
        $this->assertEquals(new \stdClass(), $lockData->platform);
        $this->assertEquals(new \stdClass(), $lockData->{'platform-dev'});

        if ($originalEnvComposer) {
            $_SERVER['COMPOSER'] = $originalEnvComposer;
        } else {
            unset($_SERVER['COMPOSER']);
        }
        // composer 2.1 and lower support
        putenv('COMPOSER='.$originalEnvComposer);
        @unlink($composerJsonPath);
        @unlink($composerLockPath);
    }
}
