<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

interface QrCodeRendererInterface
{
    /**
     * @return string the bytes of a PNG image encoding the payload
     */
    public function png(string $payload): string;
}
