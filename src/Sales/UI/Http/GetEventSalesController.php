<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use App\Sales\Core\Application\Query\GetEventSales;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class GetEventSalesController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/backoffice/events/{eventId}/sales', name: 'sales_backoffice_get_event_sales', methods: 'GET')]
    #[IsGranted('SALES_VIEW_EVENT', subject: 'eventId')]
    #[OA\Get(
        summary: 'Report the sales of an event of the organization of the caller.',
        description: 'Only paid orders count in the tickets and amounts, which are sums of the amounts frozen on the orders; the remaining seats also leave out the seats held by pending orders. Requires the Keycloak access token of an administrator of an organization (realm role organization-admin).',
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The sales of the event; amounts are in euro cents. Every price tier of the event is listed, with zeros when it sold nothing.',
                content: new OA\JsonContent(
                    required: ['eventId', 'seatsRemaining', 'ticketsSold', 'totalCents', 'commissionCents', 'netCents', 'priceTiers'],
                    properties: [
                        new OA\Property(property: 'eventId', type: 'string'),
                        new OA\Property(property: 'seatsRemaining', type: 'integer', example: 93),
                        new OA\Property(property: 'ticketsSold', type: 'integer', example: 4),
                        new OA\Property(property: 'totalCents', type: 'integer', example: 9000),
                        new OA\Property(property: 'commissionCents', type: 'integer', example: 1150),
                        new OA\Property(property: 'netCents', type: 'integer', example: 7850),
                        new OA\Property(
                            property: 'priceTiers',
                            type: 'array',
                            items: new OA\Items(
                                required: ['priceTierId', 'label', 'ticketsSold', 'totalCents'],
                                properties: [
                                    new OA\Property(property: 'priceTierId', type: 'string'),
                                    new OA\Property(property: 'label', type: 'string', example: 'Plein tarif'),
                                    new OA\Property(property: 'ticketsSold', type: 'integer', example: 3),
                                    new OA\Property(property: 'totalCents', type: 'integer', example: 7500),
                                ],
                                type: 'object',
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of the organization that owns the event, or the event does not exist (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $eventId): JsonResponse
    {
        $sales = $this->queryBus->ask(new GetEventSales($eventId));

        return new JsonResponse([
            'eventId' => $sales->eventId,
            'seatsRemaining' => $sales->seatsRemaining,
            'ticketsSold' => $sales->ticketsSold,
            'totalCents' => $sales->totalCents,
            'commissionCents' => $sales->commissionCents,
            'netCents' => $sales->netCents,
            'priceTiers' => $sales->priceTiers,
        ]);
    }
}
