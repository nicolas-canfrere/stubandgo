<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\Exception;

use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\AbstractConflictException;
use App\Shared\Exception\AbstractDomainException;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testEventNotFound(): void
    {
        $id = EventId::generate();
        $e = EventNotFoundException::withId($id);

        self::assertInstanceOf(AbstractNotFoundException::class, self::asThrowable($e));
        self::assertSame('event_not_found', $e->errorCode());
        self::assertSame(\sprintf('Event "%s" not found.', $id->toString()), $e->getMessage());
    }

    public function testOrganizationNotFoundById(): void
    {
        $id = OrganizationId::generate();
        $e = OrganizationNotFoundException::withId($id);

        self::assertInstanceOf(AbstractNotFoundException::class, self::asThrowable($e));
        self::assertSame('organization_not_found', $e->errorCode());
        self::assertSame(\sprintf('Organization "%s" not found.', $id->toString()), $e->getMessage());
    }

    public function testOrganizationNotFoundBySlug(): void
    {
        $e = OrganizationNotFoundException::withSlug(Slug::fromString('rock'));

        self::assertSame('organization_not_found', $e->errorCode());
        self::assertSame('Organization with slug "rock" not found.', $e->getMessage());
    }

    public function testEventAlreadyPublished(): void
    {
        $id = EventId::generate();
        $e = EventAlreadyPublishedException::withId($id);

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('event_already_published', $e->errorCode());
        self::assertSame(\sprintf('Event "%s" is already published.', $id->toString()), $e->getMessage());
    }

    public function testEventWithoutPriceTier(): void
    {
        $id = EventId::generate();
        $e = EventWithoutPriceTierException::withId($id);

        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('event_without_price_tier', $e->errorCode());
        self::assertSame(\sprintf('Event "%s" has no price tier and cannot be published.', $id->toString()), $e->getMessage());
    }

    private static function asThrowable(\Throwable $e): \Throwable
    {
        return $e;
    }
}
