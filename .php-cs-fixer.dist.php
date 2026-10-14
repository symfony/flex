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
    ->setRuleCustomisationPolicy(new class implements PhpCsFixer\Config\RuleCustomisationPolicyInterface {
        public function getPolicyVersionForCache(): string
        {
            return hash_file('xxh128', __FILE__);
        }

        public function getRuleCustomisers(): array
        {
            return [
                // test methods must not have a return type
                'void_return' => static fn (SplFileInfo $file) => !str_starts_with($file->getPathname(), __DIR__.'/tests/'),
            ];
        }
    })
    ->setRiskyAllowed(true)
;
