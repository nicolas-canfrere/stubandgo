<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Metrics;

use App\Shared\Infrastructure\Observability\Metrics\Gauge;
use App\Shared\Infrastructure\Observability\Metrics\PrometheusTextRenderer;
use PHPUnit\Framework\TestCase;

final class PrometheusTextRendererTest extends TestCase
{
    public function testRendererOutputsHelpTypeAndEscapedLabels(): void
    {
        $text = new PrometheusTextRenderer()->render([
            new Gauge('demo_total', "Counts things.\nSecond \\ line.", 2.0, ['a' => 'x"y', 'b' => "l1\nl2", 'c' => 'back\\slash']),
            new Gauge('demo_total', 'ignored duplicate help', 0.5, ['a' => 'z']),
            new Gauge('plain', 'No labels.', 1700000000.0),
        ]);

        self::assertSame(
            <<<'TXT'
                # HELP demo_total Counts things.\nSecond \\ line.
                # TYPE demo_total gauge
                demo_total{a="x\"y",b="l1\nl2",c="back\\slash"} 2
                demo_total{a="z"} 0.5
                # HELP plain No labels.
                # TYPE plain gauge
                plain 1700000000

                TXT,
            $text,
        );
    }

    public function testNothingToRenderGivesAnEmptyDocument(): void
    {
        self::assertSame('', new PrometheusTextRenderer()->render([]));
    }
}
