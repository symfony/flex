<?php

namespace Harmony\Flex\Platform;

use Harmony\Flex\Platform\Project\Database;

/**
 * Class Project
 *
 * @package Harmony\Flex\Platform
 */
class Project
{

    /** @var string $name */
    private $name;

    /** @var string|null $description */
    private $description;

    /** @var string $slug */
    private $slug;

    /** @var string|null $url */
    private $url;

    /** @var bool $isSecured */
    private $isSecured = false;

    /** @var \DateTime|null $createdAt */
    private $createdAt;

    /** @var \DateTime|Null $updatedAt */
    private $updatedAt;

    /** @var Database[] $databases */
    private $databases = [];

    /** @var array $extensions */
    private $extensions = [];

    /** @var array $packages */
    private $packages = [];

    /** @var array $themes */
    private $themes = [];

    /** @var array $translations */
    private $translations = [];

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name): void
    {
        $this->name = $name;
    }

    /**
     * @return mixed
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param mixed $description
     */
    public function setDescription($description): void
    {
        $this->description = $description;
    }

    /**
     * @return mixed
     */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * @param mixed $slug
     */
    public function setSlug($slug): void
    {
        $this->slug = $slug;
    }

    /**
     * @return mixed
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param mixed $url
     */
    public function setUrl($url): void
    {
        $this->url = $url;
    }

    /**
     * @return bool
     */
    public function isSecured(): bool
    {
        return $this->isSecured;
    }

    /**
     * @param bool $isSecured
     */
    public function setIsSecured(bool $isSecured): void
    {
        $this->isSecured = $isSecured;
    }

    /**
     * @return mixed
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @param mixed $createdAt
     */
    public function setCreatedAt($createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * @return mixed
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @param mixed $updatedAt
     */
    public function setUpdatedAt($updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /**
     * @param Database[] $databases
     */
    public function setDatabases(array $databases): void
    {
        $this->databases = $databases;
    }

    /**
     * @param Database $database
     */
    public function addDatabase(Database $database): void
    {
        $this->databases[] = $database;
    }

    /**
     * @return array
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * @param array $extensions
     */
    public function setExtensions(array $extensions): void
    {
        $this->extensions = $extensions;
    }

    /**
     * @return array
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * @param array $packages
     */
    public function setPackages(array $packages): void
    {
        $this->packages = $packages;
    }

    /**
     * @return array
     */
    public function getThemes(): array
    {
        return $this->themes;
    }

    /**
     * @param array $themes
     */
    public function setThemes(array $themes): void
    {
        $this->themes = $themes;
    }

    /**
     * @return array
     */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /**
     * @param array $translations
     */
    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }
}