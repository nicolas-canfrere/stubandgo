<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use App\Sales\Core\Application\Command\PayOrder;
use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A declined or late payment is a persisted outcome, not an exception: the controller reads the resulting status
 * and builds the matching RFC 9457 problem itself, with the order status as extension member.
 */
final readonly class PayOrderController
{
    private const string DECLINED = 'failed';
    private const string EXPIRED = 'expired';

    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/orders/{orderId}/payment', name: 'sales_pay_order', methods: 'POST')]
    #[OA\Post(
        summary: 'Pay a pending order with a card (simulated: 4242424242424242 is accepted, any other card is declined).',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['cardNumber'],
            properties: [new OA\Property(property: 'cardNumber', type: 'string', example: '4242424242424242')],
        )),
        tags: ['Sales'],
        parameters: [new OA\Parameter(name: 'orderId', description: 'ULID of the order.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The order is paid.',
                content: new OA\JsonContent(required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', example: 'paid')]),
            ),
            new OA\Response(
                response: 402,
                description: 'The card was declined (problem type /problems/payment_declined, orderStatus member "failed").',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 404,
                description: 'The order does not exist (problem type /problems/order_not_found).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 409,
                description: 'The order expired before payment (/problems/order_expired, orderStatus member "expired") or is not pending (/problems/order_not_pending).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 422,
                description: 'Malformed payload (/problems/validation_failed) or invalid card number (/problems/invalid_value).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $orderId, #[MapRequestPayload] PayOrderRequest $payment, Request $request): JsonResponse
    {
        $this->commandBus->dispatch(new PayOrder($orderId, $payment->cardNumber));

        /** @var OrderStatusView $order */
        $order = $this->queryBus->ask(new GetOrderStatus($orderId));

        return match ($order->status) {
            self::DECLINED => $this->problem(Response::HTTP_PAYMENT_REQUIRED, 'payment_declined', 'The payment was declined.', $order, $request),
            self::EXPIRED => $this->problem(Response::HTTP_CONFLICT, 'order_expired', 'The order expired before it was paid.', $order, $request),
            default => new JsonResponse(['status' => $order->status]),
        };
    }

    private function problem(int $status, string $code, string $detail, OrderStatusView $order, Request $request): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => '/problems/' . $code,
                'title' => Response::$statusTexts[$status],
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getPathInfo(),
                'orderStatus' => $order->status,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
