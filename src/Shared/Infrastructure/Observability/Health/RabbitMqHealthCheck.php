<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens an AMQP connection to the broker of the messenger DSN, with short timeouts. Nothing of the DSN is reported.
 */
final readonly class RabbitMqHealthCheck implements HealthCheckInterface
{
    private const float TIMEOUT_SECONDS = 1.0;

    /** @var \Closure(array<string, mixed>): void */
    private \Closure $connector;

    /**
     * @param (\Closure(array<string, mixed>): void)|null $connector opens the connection from AMQP parameters and throws on failure
     */
    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN)%')]
        private string $dsn,
        ?\Closure $connector = null,
    ) {
        $this->connector = $connector ?? static function (array $parameters): void {
            new \AMQPConnection($parameters)->connect();
        };
    }

    public function name(): string
    {
        return 'rabbitmq';
    }

    public function check(): HealthStatus
    {
        try {
            ($this->connector)($this->parameters());
        } catch (\Throwable) {
            return new HealthStatus(false, 'Broker unreachable.');
        }

        return new HealthStatus(true, 'Broker reachable.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        $parts = parse_url($this->dsn);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid broker DSN.');
        }

        $vhost = explode('/', ltrim($parts['path'] ?? '', '/'))[0];

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 5672,
            'login' => rawurldecode($parts['user'] ?? 'guest'),
            'password' => rawurldecode($parts['pass'] ?? 'guest'),
            'vhost' => '' === $vhost ? '/' : rawurldecode($vhost),
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'read_timeout' => self::TIMEOUT_SECONDS,
            'write_timeout' => self::TIMEOUT_SECONDS,
        ];
    }
}
