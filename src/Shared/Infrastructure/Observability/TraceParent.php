<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

/**
 * W3C trace context "traceparent" header (version 00): the trace id correlates every log line of one request,
 * across the buses, the outbox and the worker.
 */
final readonly class TraceParent
{
    private const string FORMAT = '/\A00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}\z/';
    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    private function __construct(private string $header, private string $traceId)
    {
    }

    /**
     * Returns null when the header is not a valid version 00 traceparent (an all-zero trace id is invalid).
     */
    public static function fromHeader(string $header): ?self
    {
        if (1 !== preg_match(self::FORMAT, $header, $matches) || self::INVALID_TRACE_ID === $matches[1]) {
            return null;
        }

        return new self($header, $matches[1]);
    }

    /**
     * A new trace, flagged as sampled.
     */
    public static function generate(): self
    {
        $traceId = bin2hex(random_bytes(16));

        return new self(\sprintf('00-%s-%s-01', $traceId, bin2hex(random_bytes(8))), $traceId);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function toHeader(): string
    {
        return $this->header;
    }
}
