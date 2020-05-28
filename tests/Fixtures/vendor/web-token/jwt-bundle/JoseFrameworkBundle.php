<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014-2019 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace Jose\Bundle\JoseFramework;

use Jose\Bundle\JoseFramework\DependencyInjection\Compiler\SymfonySerializerCompilerPass;
use Jose\Bundle\JoseFramework\DependencyInjection\JoseFrameworkExtension;
use Jose\Bundle\JoseFramework\DependencyInjection\Source;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class JoseFrameworkBundle extends Bundle
{
    /**
     * @var Source\Source[]
     */
    private $sources = [];

    public function __construct()
    {
        foreach ($this->getSources() as $source) {
            $this->sources[$source->name()] = $source;
        }
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new JoseFrameworkExtension('jose', $this->sources);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach ($this->sources as $source) {
            if ($source instanceof Source\SourceWithCompilerPasses) {
                $compilerPasses = $source->getCompilerPasses();
                foreach ($compilerPasses as $compilerPass) {
                    $container->addCompilerPass($compilerPass);
                }
            }
        }

        $container->addCompilerPass(new SymfonySerializerCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * @return Source\Source[]
     */
    private function getSources(): iterable
    {
        return [
            new Source\Core\CoreSource(),
            new Source\Checker\CheckerSource(),
            new Source\Console\ConsoleSource(),
            new Source\Signature\SignatureSource(),
            new Source\Encryption\EncryptionSource(),
            new Source\NestedToken\NestedToken(),
            new Source\KeyManagement\KeyManagementSource(),
        ];
    }
}
