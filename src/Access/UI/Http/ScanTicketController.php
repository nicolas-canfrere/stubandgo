<?php

declare(strict_types=1);

namespace App\Access\UI\Http;

use App\Access\Core\Application\Command\ScanTicket;
use App\Access\Core\Application\Query\GetScanAttempt;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * The outcome of a scan is a business result, not an error: the route answers 200 whatever the verdict.
 *
 * Only a controller of the organization that owns the event may scan (ScanEventVoter of Access.Infrastructure). The
 * check runs before the dispatch: a refused scan is not an attempt at the gate and is never recorded.
 */
final readonly class ScanTicketController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/events/{eventId}/scans', name: 'access_scan_ticket', methods: 'POST')]
    #[IsGranted('ACCESS_SCAN_EVENT', subject: 'eventId')]
    #[OA\Post(
        description: 'Consumes the admission right of the token for the event: only the first scan of a ticket is valid. Every authorized scan is recorded, a malformed token included (outcome "unknown"). Requires the Keycloak access token of a controller (realm role organization-controller) of the organization that owns the event; a refused scan is not recorded.',
        summary: 'Scan a ticket token at the gate of an event.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScanTicketRequest')),
        security: [['bearerAuth' => []]],
        tags: ['Access'],
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event whose gate scans the token.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The verdict of the scan, whatever it is.',
                content: new OA\JsonContent(
                    required: ['outcome', 'firstUsedAt'],
                    properties: [
                        new OA\Property(property: 'outcome', type: 'string', enum: ['valid', 'already_used', 'wrong_event', 'unknown']),
                        new OA\Property(property: 'firstUsedAt', type: 'string', format: 'date-time', nullable: true, description: 'For already_used only, when the ticket was first scanned, in UTC (RFC 3339, Z suffix); null otherwise.', example: '2026-11-20T19:00:00Z'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge); nothing is recorded.',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 403,
                description: 'The member is not a controller of the organization that owns the event, or the event does not exist or its identifier is not a ULID (/problems/forbidden); nothing is recorded.',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 422,
                description: 'Malformed payload (/problems/validation_failed); nothing is recorded.',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $eventId, #[MapRequestPayload] ScanTicketRequest $scan): JsonResponse
    {
        $scanAttemptId = (string) new Ulid();
        $this->commandBus->dispatch(new ScanTicket($scanAttemptId, $eventId, $scan->token));

        $attempt = $this->queryBus->ask(new GetScanAttempt($scanAttemptId));

        return new JsonResponse([
            'outcome' => $attempt->outcome,
            'firstUsedAt' => $attempt->firstUsedAt?->format('Y-m-d\TH:i:sp'),
        ]);
    }
}
