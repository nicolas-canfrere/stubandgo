<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Infrastructure\EndroidQrCodeRenderer;
use PHPUnit\Framework\TestCase;

final class EndroidQrCodeRendererTest extends TestCase
{
    public function testRendersAPngImage(): void
    {
        $png = new EndroidQrCodeRenderer()->png('some-payload');

        self::assertStringStartsWith("\x89PNG", $png);
        self::assertNotSame($png, new EndroidQrCodeRenderer()->png('another-payload'));
    }
}
