<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the exact Prometheus content type: Response::prepare() would append a charset to any text/* type.
 */
final class PrometheusTextResponse extends Response
{
    private const string CONTENT_TYPE = 'text/plain; version=0.0.4';

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        $this->headers->set('Content-Type', self::CONTENT_TYPE);

        return $this;
    }
}
