<?php

namespace Symfony\Start;

use Composer\Package\Version\VersionParser;

class PackageRequirement
{
    private $package;
    private $constraint;
    private $dev;

    public function __construct($package, $constraint, $dev)
    {
        $this->package = $package;
        $this->constraint = $constraint;
        $this->dev = (bool) $dev;

        $versionParser = new VersionParser();
        $versionParser->parseConstraints($constraint);
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function isDev()
    {
        return $this->dev;
    }

    public function getRemoveKey()
    {
        return $this->dev ? 'require' : 'require-dev';
    }

    public function getRequireKey()
    {
        return $this->dev ? 'require-dev' : 'require';
    }
}
