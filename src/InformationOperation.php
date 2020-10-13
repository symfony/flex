<?php

namespace Symfony\Flex;

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;

/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class InformationOperation implements OperationInterface
{
    private $package;

    public function __construct(PackageInterface $package)
    {
        $this->package = $package;
    }

    /**
     * Returns package instance.
     *
     * @return PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * {@inheritdoc}
     */
    public function getJobType()
    {
        return 'information';
    }

    /**
     * {@inheritdoc}
     */
    public function getOperationType()
    {
        return 'information';
    }

    /**
     * {@inheritdoc}
     */
    public function show($lock)
    {
        $pretty = method_exists($this->package, 'getFullPrettyVersion') ? $this->package->getFullPrettyVersion() : $this->formatVersion($this->package);

        return 'Information '.$this->package->getPrettyName().' ('.$pretty.')';
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->show(false);
    }

    /**
     * Compatibility for Composer 1.x, not needed in Composer 2.
     */
    public function getReason()
    {
        return null;
    }
}
