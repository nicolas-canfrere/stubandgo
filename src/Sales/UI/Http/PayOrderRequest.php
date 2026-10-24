<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use OpenApi\Attributes as OA;

/**
 * Structure of the payment body; the card number format is checked by the domain.
 */
final readonly class PayOrderRequest
{
    public function __construct(
        #[\SensitiveParameter]
        #[OA\Property(example: '4242424242424242')]
        public string $cardNumber,
    ) {
    }
}
