<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Health;

use App\Shared\Infrastructure\Observability\Health\RabbitMqHealthCheck;
use PHPUnit\Framework\TestCase;

final class RabbitMqHealthCheckTest extends TestCase
{
    public function testItIsNamedRabbitmq(): void
    {
        self::assertSame('rabbitmq', new RabbitMqHealthCheck('amqp://localhost')->name());
    }

    public function testRabbitMqCheckReportsUnreachableBroker(): void
    {
        $status = new RabbitMqHealthCheck('amqp://user:s3cret@127.0.0.1:1/%2f/messages')->check();

        self::assertFalse($status->healthy);
        self::assertStringNotContainsString('s3cret', $status->detail);
        self::assertStringNotContainsString('127.0.0.1', $status->detail);
        self::assertStringNotContainsString('user', $status->detail);
    }

    public function testItReportsAnInvalidDsnWithoutException(): void
    {
        $status = new RabbitMqHealthCheck('not a dsn')->check();

        self::assertFalse($status->healthy);
        self::assertStringNotContainsString('not a dsn', $status->detail);
    }

    public function testItReportsAHealthyBrokerWhenTheConnectionSucceeds(): void
    {
        $status = new RabbitMqHealthCheck('amqp://localhost', static function (): void {})->check();

        self::assertTrue($status->healthy);
    }

    public function testItNeverLeaksTheConnectorFailure(): void
    {
        $check = new RabbitMqHealthCheck('amqp://localhost', static function (): never {
            throw new \RuntimeException('login s3cret@broker refused');
        });

        $status = $check->check();

        self::assertFalse($status->healthy);
        self::assertStringNotContainsString('s3cret', $status->detail);
    }

    public function testItConnectsWithTheDsnPartsAndOneSecondTimeouts(): void
    {
        $received = null;
        new RabbitMqHealthCheck('amqp://us%40er:p%3Ass@127.0.0.2:5673/%2fvh/messages', static function (array $parameters) use (&$received): void {
            $received = $parameters;
        })->check();

        self::assertSame([
            'host' => '127.0.0.2',
            'port' => 5673,
            'login' => 'us@er',
            'password' => 'p:ss',
            'vhost' => '/vh',
            'connect_timeout' => 1.0,
            'read_timeout' => 1.0,
            'write_timeout' => 1.0,
        ], $received);
    }

    public function testItAppliesDefaultsToABareDsn(): void
    {
        $received = null;
        new RabbitMqHealthCheck('amqp://localhost', static function (array $parameters) use (&$received): void {
            $received = $parameters;
        })->check();

        self::assertIsArray($received);
        self::assertSame('localhost', $received['host']);
        self::assertSame(5672, $received['port']);
        self::assertSame('guest', $received['login']);
        self::assertSame('guest', $received['password']);
        self::assertSame('/', $received['vhost']);
    }

    public function testItUsesTheDefaultVhostWhenThePathIsASlash(): void
    {
        $received = null;
        new RabbitMqHealthCheck('amqp://localhost/', static function (array $parameters) use (&$received): void {
            $received = $parameters;
        })->check();

        self::assertIsArray($received);
        self::assertSame('/', $received['vhost']);
    }
}
