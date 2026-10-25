<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure\Keycloak;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * HTTP client replaced by a script: answers with the queued responses in order and remembers every request it received.
 */
final class ScriptedHttp
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];
    /** @var list<MockResponse> */
    private array $queue;

    public function __construct(MockResponse ...$responses)
    {
        $this->queue = array_values($responses);
    }

    /**
     * @param list<string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json', ...$headers]]);
    }

    /**
     * @param list<string> $headers
     */
    public static function status(int $status, array $headers = []): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => $headers]);
    }

    public static function transportFailure(): MockResponse
    {
        return new MockResponse('', ['error' => 'connection refused']);
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $this->headerLines($options['headers']), 'body' => \is_string($body) ? $body : ''];

            return array_shift($this->queue) ?? throw new \LogicException(\sprintf('Unexpected request %s %s.', $method, $url));
        }, 'http://keycloak:8080');
    }

    /**
     * @return list<array{method: string, url: string, headers: list<string>, body: string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return \count($this->requests);
    }

    public function exhausted(): bool
    {
        return [] === $this->queue;
    }

    /**
     * @return list<string>
     */
    private function headerLines(mixed $headers): array
    {
        return \is_array($headers) ? array_values(array_filter($headers, \is_string(...))) : [];
    }
}
