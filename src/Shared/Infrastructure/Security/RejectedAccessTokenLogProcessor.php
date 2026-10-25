<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Symfony's OIDC token handler logs every token it rejects as an error, with a stack trace. A client sending a bad
 * token is a refused request, not a fault of the API: the record is lowered to info and loses its trace (which
 * also quotes the start of the token). The handler's other errors, such as Keycloak's keys being unreachable,
 * stay errors.
 */
#[AsMonologProcessor]
final readonly class RejectedAccessTokenLogProcessor implements ProcessorInterface
{
    private const string REJECTED_TOKEN_MESSAGE = 'An error occurred while decoding and validating the token.';

    public function __invoke(LogRecord $record): LogRecord
    {
        if (self::REJECTED_TOKEN_MESSAGE !== $record->message) {
            return $record;
        }

        $context = $record->context;
        unset($context['trace']);

        return $record->with(level: Level::Info, context: $context);
    }
}
