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

    /** @var array $env */
    private $env = [];

    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * @param string $scheme
     *
     * @return Database
     */
    public function setScheme(string $scheme): Database
    {
        $this->scheme = $scheme;

        return $this;
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
     *
     * @return Database
     */
    public function setHost(?string $host): Database
    {
        $this->host = $host;

        return $this;
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
     *
     * @return Database
     */
    public function setName(?string $name): Database
    {
        $this->name = $name;

        return $this;
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
     *
     * @return Database
     */
    public function setUser(?string $user): Database
    {
        $this->user = $user;

        return $this;
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
     *
     * @return Database
     */
    public function setPass(?string $pass): Database
    {
        $this->pass = $pass;

        return $this;
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
     *
     * @return Database
     */
    public function setPort(?int $port): Database
    {
        $this->port = $port;

        return $this;
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
     *
     * @return Database
     */
    public function setPath(?string $path): Database
    {
        $this->path = $path;

        return $this;
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
     *
     * @return Database
     */
    public function setMemory(bool $memory): Database
    {
        $this->memory = $memory;

        return $this;
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
     *
     * @return Database
     */
    public function setQuery(array $query): Database
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $url
     *
     * @return Database
     */
    public function setUrl(string $url): Database
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return array
     */
    public function getEnv(): array
    {
        return $this->env;
    }

    /**
     * @param array $env
     *
     * @return Database
     */
    public function setEnv(array $env): Database
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Build database url formatted like:
     * <type>://[user[:password]@][host][:port][/db][?param_1=value_1&param_2=value_2...]
     *
     * @example mysql://user:password@127.0.0.1/db_name/?unix_socket=/path/to/socket
     * @return void
     */
    public function buildDatabaseUrl(): void
    {
        $this->url = ($this->getScheme() ? $this->getScheme() . "://" : '//') .// scheme
            ($this->getHost() ?
                (($this->getUser() ? $this->getUser() . ($this->getPass() ? ":" . $this->getPass() : '') . '@' : '') .
                    $this->getHost() . ($this->getPort() ? ":" . $this->getPort() : '')) : '') . // host
            ($this->getPath() ? '/' . $this->getPath() : '') . // path
            (true === $this->isMemory() ? '/:memory:' : '') . // memory
            (($this->getName() && 'mongodb' !== $this->getScheme()) ? '/' . $this->getName() : '') . // db_name
            (!empty($this->getQuery()) ? '?' . http_build_query($this->getQuery(), '', '&') : ''); // query
    }

}