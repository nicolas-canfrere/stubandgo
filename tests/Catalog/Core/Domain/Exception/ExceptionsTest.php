<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\Exception;

use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\Exception\OrganizationNameTakenException;
use App\Catalog\Core\Domain\Exception\OrganizationNotActiveException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\Exception\OrganizationSlugTakenException;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\AbstractConflictException;
use App\Shared\Exception\AbstractDomainException;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Exception\AbstractUpstreamFailureException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\ValueObject\EmailAddress;
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

    public function testOrganizationSlugTaken(): void
    {
        $e = OrganizationSlugTakenException::withSlug(Slug::fromString('rock'));

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('organization_slug_taken', $e->errorCode());
        self::assertSame('Organization slug "rock" is already taken.', $e->getMessage());
    }

    public function testOrganizationNameTaken(): void
    {
        $e = OrganizationNameTakenException::withName(OrganizationName::fromString('Rock Asso'));

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('organization_name_taken', $e->errorCode());
        self::assertSame('Organization name "Rock Asso" is already taken.', $e->getMessage());
    }

    public function testOrganizationNotActive(): void
    {
        $id = OrganizationId::generate();
        $e = OrganizationNotActiveException::withId($id);

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('organization_not_active', $e->errorCode());
        self::assertSame(\sprintf('Organization "%s" is not active yet.', $id->toString()), $e->getMessage());
    }

    public function testMemberAlreadyExists(): void
    {
        $e = MemberAlreadyExistsException::withEmail(EmailAddress::fromString('a@example.com'));

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('member_already_exists', $e->errorCode());
        self::assertSame('A member with the email address "a@example.com" already exists.', $e->getMessage());
    }

    public function testIdentityProviderUnavailable(): void
    {
        $e = IdentityProviderUnavailableException::unreachable();

        self::assertInstanceOf(AbstractUpstreamFailureException::class, self::asThrowable($e));
        self::assertSame('identity_provider_unavailable', $e->errorCode());
        self::assertSame('The identity provider is unavailable.', $e->getMessage());
        self::assertSame(0, $e->getCode());
        self::assertNull($e->getPrevious());
        $cause = new \RuntimeException('down');
        self::assertSame($cause, IdentityProviderUnavailableException::unreachable($cause)->getPrevious());
    }

    private static function asThrowable(\Throwable $e): \Throwable
    {
        return $e;
    }
}
