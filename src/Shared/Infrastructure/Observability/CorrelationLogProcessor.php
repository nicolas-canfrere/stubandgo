<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

#[AsMonologProcessor]
final readonly class CorrelationLogProcessor implements ProcessorInterface
{
    public function __construct(private CorrelationContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['trace_id'] = $this->context->current()->traceId();

        return $record;
    }
}
