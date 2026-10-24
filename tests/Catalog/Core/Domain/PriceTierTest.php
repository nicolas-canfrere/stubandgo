<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class PriceTierTest extends TestCase
{
    public function testPriceTierExposesItsState(): void
    {
        $id = PriceTierId::generate();
        $label = PriceTierLabel::fromString('Plein');
        $price = Money::EUR(2500);

        $tier = new PriceTier($id, $label, $price);

        self::assertSame($id, $tier->id());
        self::assertSame($label, $tier->label());
        self::assertSame($price, $tier->price());
    }

    public function testPriceTierRejectsNegativePrice(): void
    {
        $this->expectException(InvalidValueException::class);

        new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Plein'), Money::EUR(-1));
    }

    public function testPriceTierAcceptsZero(): void
    {
        $tier = new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Gratuit'), Money::EUR(0));

        self::assertTrue($tier->price()->isZero());
    }

    public function testPriceTierRejectsNonEuroCurrency(): void
    {
        $this->expectException(InvalidValueException::class);

        new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Plein'), Money::USD(100));
    }
}
