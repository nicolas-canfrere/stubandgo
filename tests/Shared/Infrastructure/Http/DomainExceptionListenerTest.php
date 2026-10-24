<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\DomainExceptionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class DomainExceptionListenerTest extends WebTestCase
{
    public function testNotFoundExceptionIsRenderedAsProblemDetails(): void
    {
        $this->assertProblem('/_test/not-found', 404, [
            'type' => '/problems/fixture_not_found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Thing 42 not found.',
            'instance' => '/_test/not-found',
        ]);
    }

    /**
     * @return iterable<string, array{string, int, array<string, mixed>}>
     */
    public static function otherDomainExceptions(): iterable
    {
        yield 'conflict' => ['/_test/conflict', 409, [
            'type' => '/problems/fixture_conflict',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Thing 42 conflicts.',
            'instance' => '/_test/conflict',
        ]];
        yield 'other domain exception' => ['/_test/domain', 422, [
            'type' => '/problems/fixture_other',
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'Thing 42 is invalid.',
            'instance' => '/_test/domain',
        ]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('otherDomainExceptions')]
    public function testDomainExceptionsAreRenderedAsProblemDetails(string $path, int $status, array $expected): void
    {
        $this->assertProblem($path, $status, $expected);
    }

    public function testNonDomainExceptionIsLeftUntouched(): void
    {
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('crash'),
        );

        (new DomainExceptionListener())($event);

        self::assertNull($event->getResponse());
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertProblem(string $path, int $status, array $expected): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame($expected, json_decode((string) $client->getResponse()->getContent(), true));
    }
}
