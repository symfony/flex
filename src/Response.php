<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Response implements \JsonSerializable
{
    private $body;
    private $headers;

    /**
     * @param mixed $body The response as JSON
     */
    public function __construct($body, iterable $headers = [])
    {
        $this->body = $body;
        $this->headers = $this->parseHeaders($headers);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody()
    {
        return $this->body;
    }

    public static function fromJson(array $json): Response
    {
        if (!isset($json['body']) || !isset($json['headers'])) {
            throw new \LogicException('Old Symfony Flex cache detected. Clear the Composer cache under "~/.composer/cache/repo/https---flex.symfony.com/".');
        }

        $response = new Response($json['body']);
        $response->headers = $json['headers'];

        return $response;
    }

    public function jsonSerialize()
    {
        return ['body' => $this->body, 'headers' => $this->headers];
    }

    private function parseHeaders(iterable $headers): array
    {
        $values = [];
        foreach (array_reverse($headers) as $header) {
            if (preg_match('{^([^\:]+):\s*(.+?)\s*$}i', $header, $match)) {
                $values[strtolower($match[1])] = $match[2];
            } elseif (preg_match('{^HTTP/}i', $header)) {
                break;
            }
        }

        return $values;
    }
}
