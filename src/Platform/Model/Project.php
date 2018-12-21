<?php

namespace Harmony\Flex\Platform\Model;

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

    /** @var ProjectDatabase[] $databases */
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
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return Project
     */
    public function setName(string $name): Project
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     *
     * @return Project
     */
    public function setDescription(?string $description): Project
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * @param string $slug
     *
     * @return Project
     */
    public function setSlug(string $slug): Project
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @param string|null $url
     *
     * @return Project
     */
    public function setUrl(?string $url): Project
    {
        $this->url = $url;

        return $this;
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
     *
     * @return Project
     */
    public function setIsSecured(bool $isSecured): Project
    {
        $this->isSecured = $isSecured;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    /**
     * @param \DateTime|null $createdAt
     *
     * @return Project
     */
    public function setCreatedAt(?\DateTime $createdAt): Project
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return \DateTime|Null
     */
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    /**
     * @param \DateTime|Null $updatedAt
     *
     * @return Project
     */
    public function setUpdatedAt(?\DateTime $updatedAt): Project
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return ProjectDatabase[]
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /**
     * @param ProjectDatabase $database
     *
     * @return Project
     */
    public function addDatabase(ProjectDatabase $database): Project
    {
        $this->databases[] = $database;

        return $this;
    }

    /**
     * @return bool
     */
    public function hasDatabases(): bool
    {
        return !empty($this->databases);
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
     *
     * @return Project
     */
    public function setExtensions(array $extensions): Project
    {
        $this->extensions = $extensions;

        return $this;
    }

    /**
     * @return bool
     */
    public function hasExtensions(): bool
    {
        return !empty($this->extensions);
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
     *
     * @return Project
     */
    public function setPackages(array $packages): Project
    {
        $this->packages = $packages;

        return $this;
    }

    /**
     * @return bool
     */
    public function hasPackages(): bool
    {
        return !empty($this->packages);
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
     *
     * @return Project
     */
    public function setThemes(array $themes): Project
    {
        $this->themes = $themes;

        return $this;
    }

    /**
     * @return bool
     */
    public function hasThemes(): bool
    {
        return !empty($this->themes);
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
     *
     * @return Project
     */
    public function setTranslations(array $translations): Project
    {
        $this->translations = $translations;

        return $this;
    }

    /**
     * @return bool
     */
    public function hasTranslations(): bool
    {
        return !empty($this->translations);
    }
}