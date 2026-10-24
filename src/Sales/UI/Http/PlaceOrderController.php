<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Identity\OrderId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlaceOrderController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/orders', name: 'sales_place_order', methods: 'POST')]
    #[OA\Post(
        summary: 'Place an order and reserve its seats; a free order is paid at once.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['eventId', 'buyerEmail', 'lines'],
            properties: [
                new OA\Property(property: 'eventId', type: 'string', description: 'ULID of the event.'),
                new OA\Property(property: 'buyerEmail', type: 'string', example: 'buyer@example.com'),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    required: ['priceTierId', 'quantity'],
                    properties: [
                        new OA\Property(property: 'priceTierId', type: 'string', description: 'ULID of the price tier.'),
                        new OA\Property(property: 'quantity', type: 'integer', example: 2),
                    ],
                    type: 'object',
                )),
            ],
        )),
        tags: ['Sales'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'The order was placed; its URL is in the Location header.',
                headers: [new OA\Header(header: 'Location', description: 'URL of the order.', schema: new OA\Schema(type: 'string'))],
                content: new OA\JsonContent(
                    required: ['id', 'status'],
                    properties: [
                        new OA\Property(property: 'id', type: 'string', description: 'ULID of the order.'),
                        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid']),
                    ],
                ),
            ),
            new OA\Response(
                response: 409,
                description: 'Not enough seats left (problem type /problems/sold_out).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 422,
                description: 'Malformed payload (/problems/validation_failed, with a violations member) or a business rule is broken: invalid_value, empty_order, order_too_large, unknown_price_tier, duplicate_price_tier, event_not_on_sale, sales_closed.',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(#[MapRequestPayload] PlaceOrderRequest $request): JsonResponse
    {
        $orderId = OrderId::generate()->toString();
        $this->commandBus->dispatch(new PlaceOrder(
            $orderId,
            $request->eventId,
            $request->buyerEmail,
            array_map(
                static fn(OrderLineRequest $line): array => ['priceTierId' => $line->priceTierId, 'quantity' => $line->quantity],
                $request->lines,
            ),
        ));

        /** @var OrderStatusView $order */
        $order = $this->queryBus->ask(new GetOrderStatus($orderId));

        return new JsonResponse(
            ['id' => $order->id, 'status' => $order->status],
            Response::HTTP_CREATED,
            ['Location' => '/api/orders/' . $order->id],
        );
    }
}
