<?php

namespace Symfony\Flex\Configurator;

function getcwd()
{
    return \dirname(__DIR__).'/build';
}

namespace Symfony\Flex\Tests\Configurator;

function getcwd()
{
    return \dirname(__DIR__).'/build';
}

require __DIR__.'/../vendor/autoload.php';

if (is_dir($buildDir = getcwd())) {
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($buildDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
    }

    rmdir($buildDir);
}
