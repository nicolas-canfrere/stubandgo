<?php

declare(strict_types=1);

namespace App\Tests\Shared\Identity;

use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Shared\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UlidIdTest extends TestCase
{
    private const string VALID = '01JBXYZ0000000000000000000';

    public function testRejectsNonUlidString(): void
    {
        $this->expectException(InvalidValueException::class);

        EventId::fromString('not-a-ulid');
    }

    public function testRejectedValueReportsInvalidValueCode(): void
    {
        try {
            EventId::fromString('not-a-ulid');
            self::fail('Expected InvalidValueException');
        } catch (InvalidValueException $e) {
            self::assertSame('invalid_value', $e->errorCode());
            self::assertStringContainsString('not-a-ulid', $e->getMessage());
        }
    }

    public function testTwoIdsWithSameValueAreEqual(): void
    {
        $a = EventId::fromString(self::VALID);
        $b = EventId::fromString(self::VALID);

        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    public function testIdsWithDifferentValuesAreNotEqual(): void
    {
        $a = EventId::generate();
        $b = EventId::generate();

        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }

    public function testIdsOfDifferentTypesAreNotEqual(): void
    {
        $event = EventId::fromString(self::VALID);
        $order = OrderId::fromString(self::VALID);

        self::assertFalse($event->equals($order));
    }

    public function testToStringRoundTrips(): void
    {
        self::assertSame(self::VALID, EventId::fromString(self::VALID)->toString());
    }

    public function testGenerateReturnsValidUlid(): void
    {
        $id = PriceTierId::generate();

        self::assertTrue($id->equals(PriceTierId::fromString($id->toString())));
    }

    /**
     * @param class-string<EventId|OrderId|OrganizationId|PriceTierId> $class
     */
    #[DataProvider('idClasses')]
    public function testFromStringReturnsCalledType(string $class): void
    {
        self::assertInstanceOf($class, $class::fromString(self::VALID));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function idClasses(): iterable
    {
        yield 'EventId' => [EventId::class];
        yield 'OrderId' => [OrderId::class];
        yield 'OrganizationId' => [OrganizationId::class];
        yield 'PriceTierId' => [PriceTierId::class];
    }
}
