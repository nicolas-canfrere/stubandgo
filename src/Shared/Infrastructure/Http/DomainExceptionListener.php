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

#[AsEventListener]
final class DomainExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof AbstractDomainException) {
            return;
        }

        $status = $this->statusCode($exception);

        $event->setResponse(new JsonResponse(
            [
                'type' => '/problems/' . $exception->errorCode(),
                'title' => Response::$statusTexts[$status],
                'status' => $status,
                'detail' => $exception->getMessage(),
                'instance' => $event->getRequest()->getPathInfo(),
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function statusCode(AbstractDomainException $exception): int
    {
        return match (true) {
            $exception instanceof AbstractNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof AbstractConflictException => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }
}
