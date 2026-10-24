<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

final readonly class PayOrder
{
    /**
     * @param string $cardNumber handed to the payment gateway only: never stored, logged or echoed
     */
    public function __construct(
        public string $orderId,
        #[\SensitiveParameter]
        public string $cardNumber,
    ) {
    }
}
