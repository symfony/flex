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
    public function getOperationType()
    {
        return 'information';
    }

    /**
     * {@inheritdoc}
     */
    public function show($lock)
    {
        return 'Information '.$this->package->getPrettyName().' ('.$this->package->getFullPrettyVersion().')';
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->show(false);
    }
}
