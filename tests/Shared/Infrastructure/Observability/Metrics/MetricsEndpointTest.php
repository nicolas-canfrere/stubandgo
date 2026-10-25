<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Metrics;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MetricsEndpointTest extends WebTestCase
{
    public function testMetricsEndpointContentType(): void
    {
        $client = self::createClient();

        $client->request('GET', '/metrics');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/plain; version=0.0.4');
    }

    public function testHeadRequestGetsTheHeadersWithoutBody(): void
    {
        $client = self::createClient();

        $client->request('HEAD', '/metrics');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/plain; version=0.0.4');
        self::assertSame('', $client->getResponse()->getContent());
    }

    public function testApiDocDescribesTheMetricsEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $doc = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($doc);
        self::assertIsArray($doc['paths']);
        self::assertIsArray($doc['paths']['/metrics'] ?? null);
        self::assertSame(['get'], array_keys($doc['paths']['/metrics']));
        self::assertIsArray($doc['paths']['/metrics']['get']);
        self::assertIsArray($doc['paths']['/metrics']['get']['responses']);
        self::assertIsArray($doc['paths']['/metrics']['get']['responses']['200'] ?? null);
        self::assertIsArray($doc['paths']['/metrics']['get']['responses']['200']['content'] ?? null);
        self::assertSame(['text/plain; version=0.0.4'], array_keys($doc['paths']['/metrics']['get']['responses']['200']['content']));
    }

    public function testMetricsEndpointExposesEveryProvider(): void
    {
        $client = self::createClient();

        $client->request('GET', '/metrics');

        $body = (string) $client->getResponse()->getContent();
        foreach (['stubandgo_orders', 'stubandgo_outbox_pending_messages', 'stubandgo_outbox_oldest_pending_age_seconds', 'stubandgo_outbox_quarantined_messages', 'stubandgo_scans', 'stubandgo_ticket_bundles_undelivered'] as $name) {
            self::assertStringContainsString("# TYPE $name gauge\n", $body);
        }
        self::assertStringContainsString('stubandgo_orders{status="paid"} ', $body);
        self::assertStringContainsString('stubandgo_scans{outcome="already_used"} ', $body);
    }
}
