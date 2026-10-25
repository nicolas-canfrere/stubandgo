<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Observability\TraceParent;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CorrelationHttpTest extends WebTestCase
{
    use LogRecordsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testIncomingValidTraceParentIsEchoedInResponse(): void
    {
        $incoming = TraceParent::generate()->toHeader();

        $this->client->request('GET', '/api/events', server: ['HTTP_TRACEPARENT' => $incoming]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('traceparent', $incoming);
    }

    public function testProblemResponseCarriesTheTraceParentToo(): void
    {
        $incoming = TraceParent::generate()->toHeader();

        $this->client->request('GET', '/api/orders/' . OrderId::generate()->toString(), server: ['HTTP_TRACEPARENT' => $incoming]);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('traceparent', $incoming);
    }

    public function testInvalidTraceParentIsReplacedByGeneratedOne(): void
    {
        $invalid = '00-00000000000000000000000000000000-00f067aa0ba902b7-01';

        $this->client->request('GET', '/api/events', server: ['HTTP_TRACEPARENT' => $invalid]);

        $generated = $this->responseTraceParent();
        self::assertNotSame($invalid, $generated->toHeader());
        self::assertNotSame(str_repeat('0', 32), $generated->traceId());
    }

    public function testMissingTraceParentIsGenerated(): void
    {
        $this->client->request('GET', '/api/events');

        $this->responseTraceParent();
    }

    public function testEachRequestWithoutTraceParentGetsItsOwn(): void
    {
        $this->client->request('GET', '/api/events');
        $first = $this->responseTraceParent();
        $this->client->request('GET', '/api/events');

        self::assertNotSame($first->traceId(), $this->responseTraceParent()->traceId());
    }

    public function testLogRecordsCarryTraceId(): void
    {
        $incoming = TraceParent::generate();
        self::logHandler()->clear();

        $this->client->request('GET', '/api/orders/' . OrderId::generate()->toString(), server: ['HTTP_TRACEPARENT' => $incoming->toHeader()]);

        $records = self::logRecords();
        self::assertNotEmpty($records);
        self::assertNotEmpty(array_filter($records, static fn(LogRecord $record): bool => 'request' === $record->channel), 'Symfony logs the matched route.');
        foreach ($records as $record) {
            self::assertSame($incoming->traceId(), $record->extra['trace_id'] ?? null, $record->message);
        }
    }

    private function responseTraceParent(): TraceParent
    {
        $header = $this->client->getResponse()->headers->get('traceparent');
        self::assertIsString($header);
        $traceParent = TraceParent::fromHeader($header);
        self::assertNotNull($traceParent, 'The response must carry a valid traceparent header.');

        return $traceParent;
    }
}
