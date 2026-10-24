<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ProblemDetailsListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ProblemDetailsListenerTest extends WebTestCase
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

    public function testUnknownRouteIsRenderedAsProblemDetails(): void
    {
        $this->assertProblem('/_test/does-not-exist', 404, [
            'type' => '/problems/not_found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'No route found for "GET http://localhost/_test/does-not-exist"',
            'instance' => '/_test/does-not-exist',
        ]);
    }

    public function testWrongMethodIsRenderedAsProblemDetailsWithAllowHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/events');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('Allow', 'GET');
        self::assertSame([
            'type' => '/problems/method_not_allowed',
            'title' => 'Method Not Allowed',
            'status' => 405,
            'detail' => 'No route found for "POST http://localhost/api/events": Method Not Allowed (Allow: GET)',
            'instance' => '/api/events',
        ], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testUnexpectedExceptionIsRenderedAsGenericProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_test/crash');

        self::assertResponseStatusCodeSame(500);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $content = (string) $client->getResponse()->getContent();
        self::assertSame([
            'type' => '/problems/internal_server_error',
            'title' => 'Internal Server Error',
            'status' => 500,
            'detail' => 'An unexpected error occurred.',
            'instance' => '/_test/crash',
        ], json_decode($content, true));
        self::assertStringNotContainsString('secret internals', $content);
    }

    public function testHttpExceptionTypeIsSnakeCasedReasonPhrase(): void
    {
        $response = $this->render(new HttpException(418, 'Short and stout.', null, ['X-Teapot' => 'yes']));

        self::assertSame(418, $response->getStatusCode());
        self::assertSame('yes', $response->headers->get('X-Teapot'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame([
            'type' => '/problems/i_m_a_teapot',
            'title' => "I'm a teapot",
            'status' => 418,
            'detail' => 'Short and stout.',
            'instance' => '/some/path',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testHttpExceptionWithUnknownStatusFallsBackToGenericReasonPhrase(): void
    {
        $response = $this->render(new HttpException(599, 'Odd.'));

        self::assertSame(599, $response->getStatusCode());
        self::assertSame([
            'type' => '/problems/unknown_status',
            'title' => 'Unknown Status',
            'status' => 599,
            'detail' => 'Odd.',
            'instance' => '/some/path',
        ], json_decode((string) $response->getContent(), true));
    }

    private function render(\Throwable $exception): Response
    {
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/some/path'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        (new ProblemDetailsListener())($event);

        return $event->getResponse() ?? self::fail('No response set.');
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
