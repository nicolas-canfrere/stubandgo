<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

/**
 * Renders gauges in the Prometheus text exposition format 0.0.4.
 */
final readonly class PrometheusTextRenderer
{
    /**
     * @param iterable<Gauge> $gauges
     */
    public function render(iterable $gauges): string
    {
        $text = '';
        $described = [];
        foreach ($gauges as $gauge) {
            if (!\in_array($gauge->name, $described, true)) {
                $described[] = $gauge->name;
                $text .= \sprintf("# HELP %s %s\n# TYPE %s gauge\n", $gauge->name, strtr($gauge->help, ['\\' => '\\\\', "\n" => '\n']), $gauge->name);
            }
            $text .= \sprintf("%s%s %s\n", $gauge->name, $this->labels($gauge->labels), $gauge->value);
        }

        return $text;
    }

    /**
     * @param array<string, string> $labels
     */
    private function labels(array $labels): string
    {
        if ([] === $labels) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = \sprintf('%s="%s"', $name, strtr($value, ['\\' => '\\\\', '"' => '\"', "\n" => '\n']));
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
