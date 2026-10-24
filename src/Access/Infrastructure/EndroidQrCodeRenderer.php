<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

final readonly class EndroidQrCodeRenderer implements QrCodeRendererInterface
{
    public function png(string $payload): string
    {
        return new PngWriter()->write(new QrCode($payload))->getString();
    }
}
