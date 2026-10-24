<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetOrderStatusController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/orders/{orderId}', name: 'sales_get_order_status', methods: 'GET')]
    #[OA\Get(
        summary: 'Show the status of an order (never the buyer email).',
        tags: ['Sales'],
        parameters: [new OA\Parameter(name: 'orderId', description: 'ULID of the order.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The order status.',
                content: new OA\JsonContent(
                    required: ['id', 'status', 'eventId', 'lines', 'totalCents'],
                    properties: [
                        new OA\Property(property: 'id', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'failed', 'expired']),
                        new OA\Property(property: 'eventId', type: 'string'),
                        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'priceTierId', type: 'string'),
                                new OA\Property(property: 'quantity', type: 'integer'),
                                new OA\Property(property: 'unitPriceCents', type: 'integer'),
                            ],
                            type: 'object',
                        )),
                        new OA\Property(property: 'totalCents', type: 'integer', description: 'Total in euro cents.'),
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'The order does not exist (problem type /problems/order_not_found).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 422,
                description: 'The order identifier is not a valid ULID (problem type /problems/invalid_value).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $orderId): JsonResponse
    {
        $order = $this->queryBus->ask(new GetOrderStatus($orderId));

        return new JsonResponse([
            'id' => $order->id,
            'status' => $order->status,
            'eventId' => $order->eventId,
            'lines' => $order->lines,
            'totalCents' => $order->totalCents,
        ]);
    }
}
