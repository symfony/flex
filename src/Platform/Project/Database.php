<?php

namespace Harmony\Flex\Platform\Project;

/**
 * Class Database
 *
 * @package Harmony\Flex\Platform\Project
 */
class Database
{

    /** @var string $scheme */
    private $scheme;

    /** @var null|string $host */
    private $host;

    /** @var null|string $name */
    private $name;

    /** @var null|string $user */
    private $user;

    /** @var null|string $pass */
    private $pass;

    /** @var null|int $port */
    private $port;

    /** @var null|string $path */
    private $path;

    /** @var bool $memory */
    private $memory = false;

    /** @var array $query */
    private $query = [];

    /** @var string $url */
    private $url;

    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * @param string $scheme
     */
    public function setScheme(string $scheme): void
    {
        $this->scheme = $scheme;
    }

    /**
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * @param string|null $host
     */
    public function setHost(?string $host): void
    {
        $this->host = $host;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|null $name
     */
    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string|null
     */
    public function getUser(): ?string
    {
        return $this->user;
    }

    /**
     * @param string|null $user
     */
    public function setUser(?string $user): void
    {
        $this->user = $user;
    }

    /**
     * @return string|null
     */
    public function getPass(): ?string
    {
        return $this->pass;
    }

    /**
     * @param string|null $pass
     */
    public function setPass(?string $pass): void
    {
        $this->pass = $pass;
    }

    /**
     * @return int|null
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @param int|null $port
     */
    public function setPort(?int $port): void
    {
        $this->port = $port;
    }

    /**
     * @return string|null
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * @param string|null $path
     */
    public function setPath(?string $path): void
    {
        $this->path = $path;
    }

    /**
     * @return bool
     */
    public function isMemory(): bool
    {
        return $this->memory;
    }

    /**
     * @param bool $memory
     */
    public function setMemory(bool $memory): void
    {
        $this->memory = $memory;
    }

    /**
     * @return array
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * @param array $query
     */
    public function setQuery(array $query): void
    {
        $this->query = $query;
    }

    /**
     * Build database url formatted like:
     * <type>://[user[:password]@][host][:port][/db][?param_1=value_1&param_2=value_2...]
     *
     * @example mysql://user:password@127.0.0.1/db_name/?unix_socket=/path/to/socket
     * @return string
     */
    private function buildDatabaseUrl(): string
    {
        return // scheme
            ($this->getScheme() ? $this->getScheme() . "://" : '//') . // host
            ($this->getHost() ?
                (($this->getUser() ? $this->getUser() . ($this->getPass() ? ":" . $this->getPass() : '') . '@' : '') .
                    $this->getHost() . ($this->getPort() ? ":" . $this->getPort() : '')) : '') . // path
            ($this->getPath() ? '/' . $this->getPath() : '') . // memory
            (true === $this->isMemory() ? '/:memory:' : '') . // db_name
            ($this->getName() ? '/' . $this->getName() : '') . // query
            (!empty($this->getQuery()) ? '?' . http_build_query($this->getQuery(), '', '&') : '');
    }
}