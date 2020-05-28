<?php

/*
 * This file is part of the Symfony CMF package.
 *
 * (c) Symfony CMF
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Cmf\Bundle\RoutingBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Doctrine\Bundle\PHPCRBundle\DependencyInjection\Compiler\DoctrinePhpcrMappingsPass;
use Doctrine\Common\Persistence\Mapping\Driver\DefaultFileLocator;
use Doctrine\ODM\PHPCR\Mapping\Driver\XmlDriver as PHPCRXmlDriver;
use Doctrine\ODM\PHPCR\Version as PHPCRVersion;
use Doctrine\ORM\Mapping\Driver\XmlDriver as ORMXmlDriver;
use Doctrine\ORM\Version as ORMVersion;
use Symfony\Cmf\Bundle\RoutingBundle\DependencyInjection\Compiler\SetRouterPass;
use Symfony\Cmf\Bundle\RoutingBundle\DependencyInjection\Compiler\TemplatingValidatorPass;
use Symfony\Cmf\Bundle\RoutingBundle\DependencyInjection\Compiler\ValidationPass;
use Symfony\Cmf\Component\Routing\DependencyInjection\Compiler\RegisterRouteEnhancersPass;
use Symfony\Cmf\Component\Routing\DependencyInjection\Compiler\RegisterRoutersPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle class.
 */
class CmfRoutingBundle extends Bundle
{
    /**
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new RegisterRoutersPass());
        $container->addCompilerPass(new RegisterRouteEnhancersPass());
        $container->addCompilerPass(new SetRouterPass());
        $container->addCompilerPass(new ValidationPass());
        $container->addCompilerPass(new TemplatingValidatorPass());

        $this->buildPhpcrCompilerPass($container);
        $this->buildOrmCompilerPass($container);
    }

    /**
     * Creates and registers compiler passes for PHPCR-ODM mapping if both the
     * phpcr-odm and the phpcr-bundle are present.
     *
     * @param ContainerBuilder $container
     */
    private function buildPhpcrCompilerPass(ContainerBuilder $container)
    {
        if (!class_exists(PHPCRVersion::class)) {
            return;
        }

        $container->addCompilerPass(
            $this->buildBaseCompilerPass(DoctrinePhpcrMappingsPass::class, PHPCRXmlDriver::class, 'phpcr')
        );
        $container->addCompilerPass(
            DoctrinePhpcrMappingsPass::createXmlMappingDriver(
                [
                    realpath(__DIR__.'/Resources/config/doctrine-model') => 'Symfony\Cmf\Bundle\RoutingBundle\Model',
                    realpath(__DIR__.'/Resources/config/doctrine-phpcr') => 'Symfony\Cmf\Bundle\RoutingBundle\Doctrine\Phpcr',
                ],
                ['cmf_routing.dynamic.persistence.phpcr.manager_name'],
                'cmf_routing.backend_type_phpcr',
                ['CmfRoutingBundle' => 'Symfony\Cmf\Bundle\RoutingBundle\Doctrine\Phpcr']
            )
        );
    }

    /**
     * Creates and registers compiler passes for ORM mappings if both doctrine
     * ORM and a suitable compiler pass implementation are available.
     *
     * @param ContainerBuilder $container
     */
    private function buildOrmCompilerPass(ContainerBuilder $container)
    {
        if (!class_exists(ORMVersion::class)) {
            return;
        }

        $container->addCompilerPass(
            $this->buildBaseCompilerPass(DoctrineOrmMappingsPass::class, ORMXmlDriver::class, 'orm')
        );
        $container->addCompilerPass(
            DoctrineOrmMappingsPass::createXmlMappingDriver(
                [
                    realpath(__DIR__.'/Resources/config/doctrine-model') => 'Symfony\Cmf\Bundle\RoutingBundle\Model',
                    realpath(__DIR__.'/Resources/config/doctrine-orm') => 'Symfony\Cmf\Bundle\RoutingBundle\Doctrine\Orm',
                ],
                ['cmf_routing.dynamic.persistence.orm.manager_name'],
                'cmf_routing.backend_type_orm_default',
                ['CmfRoutingBundle' => 'Symfony\Cmf\Bundle\RoutingBundle\Doctrine\Orm']
            )
        );

        $container->addCompilerPass(
            DoctrineOrmMappingsPass::createXmlMappingDriver(
                [
                    realpath(__DIR__.'/Resources/config/doctrine-model') => 'Symfony\Cmf\Bundle\RoutingBundle\Model',
                ],
                ['cmf_routing.dynamic.persistence.orm.manager_name'],
                'cmf_routing.backend_type_orm_custom',
                []
            )
        );
    }

    /**
     * Builds the compiler pass for the symfony core routing component. The
     * compiler pass factory method uses the SymfonyFileLocator which does
     * magic with the namespace and thus does not work here.
     *
     * @param string $compilerClass the compiler class to instantiate
     * @param string $driverClass   the xml driver class for this backend
     * @param string $type          the backend type name
     *
     * @return CompilerPassInterface
     */
    private function buildBaseCompilerPass($compilerClass, $driverClass, $type)
    {
        $arguments = [[realpath(__DIR__.'/Resources/config/doctrine-base')], sprintf('.%s.xml', $type)];
        $locator = new Definition(DefaultFileLocator::class, $arguments);
        $driver = new Definition($driverClass, [$locator]);

        return new $compilerClass(
            $driver,
            ['Symfony\Component\Routing'],
            [sprintf('cmf_routing.dynamic.persistence.%s.manager_name', $type)],
            sprintf('cmf_routing.backend_type_%s', $type)
        );
    }
}
