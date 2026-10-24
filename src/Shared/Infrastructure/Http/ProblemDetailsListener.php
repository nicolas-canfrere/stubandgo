<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Exception\AbstractDomainException;
use App\Shared\Exception\AbstractNotFoundException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every exception as an RFC 9457 problem details response.
 *
 * Runs after Symfony's logging listener (priority 0), which must still see the exception.
 */
#[AsEventListener(priority: -10)]
final class ProblemDetailsListener
{
    private const string VALIDATION_FAILED_TYPE = 'validation_failed';
    private const string VALIDATION_FAILED_DETAIL = 'The request payload is invalid.';
    private const string UNEXPECTED_ERROR_DETAIL = 'An unexpected error occurred.';

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $headers = [];
        $extensions = [];

        if ($exception instanceof AbstractDomainException) {
            $status = $this->domainStatusCode($exception);
            $type = $exception->errorCode();
            $detail = $exception->getMessage();
        } elseif ($exception instanceof HttpExceptionInterface && $violations = $this->payloadViolations($exception)) {
            $status = $exception->getStatusCode();
            $type = self::VALIDATION_FAILED_TYPE;
            $detail = self::VALIDATION_FAILED_DETAIL;
            $extensions = ['violations' => $violations];
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $type = $this->snakeCase($this->reasonPhrase($status));
            $detail = $exception->getMessage();
            $headers = $exception->getHeaders();
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $type = $this->snakeCase($this->reasonPhrase($status));
            $detail = self::UNEXPECTED_ERROR_DETAIL;
        }

        $event->setResponse(new JsonResponse(
            [
                'type' => '/problems/' . $type,
                'title' => $this->reasonPhrase($status),
                'status' => $status,
                'detail' => $detail,
                'instance' => $event->getRequest()->getPathInfo(),
                ...$extensions,
            ],
            $status,
            [...$headers, 'Content-Type' => 'application/problem+json'],
        ));
    }

    /**
     * The violations of a request payload rejected by #[MapRequestPayload] (a 422 HTTP exception wrapping them).
     *
     * @return list<array{propertyPath: string, message: string}>
     */
    private function payloadViolations(HttpExceptionInterface $exception): array
    {
        $previous = $exception->getPrevious();
        if (Response::HTTP_UNPROCESSABLE_ENTITY !== $exception->getStatusCode() || !$previous instanceof ValidationFailedException) {
            return [];
        }

        $violations = [];
        foreach ($previous->getViolations() as $violation) {
            $violations[] = ['propertyPath' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $violations;
    }

    private function domainStatusCode(AbstractDomainException $exception): int
    {
        return match (true) {
            $exception instanceof AbstractNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof AbstractConflictException => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }

    private function reasonPhrase(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Unknown Status';
    }

    private function snakeCase(string $phrase): string
    {
        return strtolower((string) preg_replace('/\W+/', '_', $phrase));
    }
}
