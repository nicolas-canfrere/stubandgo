<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\DomainExceptionListener;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureConflictException;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureNotFoundException;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureOtherException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class DomainExceptionListenerTest extends WebTestCase
{
    public function testNotFoundExceptionIsRenderedAs404WithErrorCode(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_test/not-found');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            ['error' => 'fixture_not_found', 'message' => 'Thing 42 not found.'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function domainExceptions(): iterable
    {
        yield 'conflict' => ['/_test/conflict', 409, 'fixture_conflict'];
        yield 'other domain exception' => ['/_test/domain', 422, 'fixture_other'];
    }

    #[DataProvider('domainExceptions')]
    public function testDomainExceptionsMapToTheirStatus(string $path, int $status, string $code): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($code, $body['error']);
    }

    public function testNonDomainExceptionIsLeftUntouched(): void
    {
        $event = $this->event(new \RuntimeException('crash'));

        (new DomainExceptionListener())($event);

        self::assertNull($event->getResponse());
    }

    public function testListenerRendersConflictAndOtherWithoutKernel(): void
    {
        $listener = new DomainExceptionListener();

        $conflict = $this->event(new FixtureConflictException('c'));
        $listener($conflict);
        self::assertSame(409, $conflict->getResponse()?->getStatusCode());

        $other = $this->event(new FixtureOtherException('o'));
        $listener($other);
        self::assertSame(422, $other->getResponse()?->getStatusCode());

        $notFound = $this->event(new FixtureNotFoundException('n'));
        $listener($notFound);
        self::assertSame(404, $notFound->getResponse()?->getStatusCode());
    }

    private function event(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
