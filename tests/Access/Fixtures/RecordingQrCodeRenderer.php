<?php

declare(strict_types=1);

namespace App\Tests\Access\Fixtures;

use App\Access\Infrastructure\QrCodeRendererInterface;

/**
 * Decorates the QR code renderer in the test environment to record what each QR code encodes.
 */
final class RecordingQrCodeRenderer implements QrCodeRendererInterface
{
    /** @var list<string> */
    private array $payloads = [];

    public function __construct(private readonly QrCodeRendererInterface $inner)
    {
    }

    public function png(string $payload): string
    {
        $this->payloads[] = $payload;

        return $this->inner->png($payload);
    }

    /**
     * @return list<string>
     */
    public function payloads(): array
    {
        return $this->payloads;
    }
}
