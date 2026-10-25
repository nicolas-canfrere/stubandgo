<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class InviteMember implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $email,
        public string $role,
    ) {
    }
}
