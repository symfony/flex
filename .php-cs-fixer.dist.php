<?php

$finder = PhpCsFixer\Finder::create()->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'fopen_flags' => false,
        'protected_to_private' => false,
    ])
    ->setRiskyAllowed(true)
;
