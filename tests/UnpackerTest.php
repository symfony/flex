<?php

namespace Symfony\Flex\Tests;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Semver\Constraint\MatchAllConstraint;
use PHPUnit\Framework\TestCase;
use Symfony\Flex\PackageResolver;
use Symfony\Flex\Unpack\Operation;
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
    public function testDoNotDuplicateEntry(): void
    {
        // Setup project

        $composerJsonPath = FLEX_TEST_DIR.'/composer.json';

        @mkdir(FLEX_TEST_DIR);
        @unlink($composerJsonPath);
        file_put_contents($composerJsonPath, '{}');

        $originalEnvComposer = $_SERVER['COMPOSER'];
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

        $repManager = $this->getMockBuilder(RepositoryManager::class)->disableOriginalConstructor()->getMock();
        $repManager->expects($this->any())->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));

        $composer = new Composer();
        $composer->setRepositoryManager($repManager);

        // Unpack

        $resolver = $this->getMockBuilder(PackageResolver::class)->disableOriginalConstructor()->getMock();

        $unpacker = new Unpacker($composer, $resolver, false);

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
}
