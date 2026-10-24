<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

/**
 * Structure of the payment body; the card number format is checked by the domain.
 */
final readonly class PayOrderRequest
{
    public function __construct(
        #[\SensitiveParameter]
        public string $cardNumber,
    ) {
    }
}
