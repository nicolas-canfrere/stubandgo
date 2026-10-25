<?php

declare(strict_types=1);

namespace App\Access\UI\Http;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Structure of the scan body. Any string is a scan, the empty one included: a token that is not a ticket token
 * answers "unknown", so the token is deliberately not required to be non-blank.
 */
final readonly class ScanTicketRequest
{
    public function __construct(
        #[\SensitiveParameter]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        #[OA\Property(description: 'Token read from the QR code of the ticket (43 base64url characters for a real ticket).', example: 'gKLhZN-ot9fBxtYtPWJNQkFE2j03nIhf5Rfh3Q52pww')]
        public string $token,
    ) {
    }
}
