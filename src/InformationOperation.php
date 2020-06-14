<?php

namespace Symfony\Flex;

use Composer\DependencyResolver\Operation\SolverOperation;
use Composer\Package\PackageInterface;

/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class InformationOperation extends SolverOperation
{
    private $package;

    public function __construct(PackageInterface $package, $reason = null)
    {
        parent::__construct($reason);

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
}
