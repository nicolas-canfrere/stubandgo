<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Query;

use App\Catalog\Core\Application\Query\OrganizationSlugs;
use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;
use PHPUnit\Framework\TestCase;

final class OrganizationSlugsTest extends TestCase
{
    public function testEachOrganizationIsReadOnceAndSlugsAreNotMixedUp(): void
    {
        $first = OrganizationId::generate();
        $second = OrganizationId::generate();
        $repository = new class([$first, $second]) implements OrganizationRepositoryInterface {
            public int $reads = 0;

            /** @var array<string, Organization> */
            private array $organizations = [];

            /**
             * @param list<OrganizationId> $ids
             */
            public function __construct(array $ids)
            {
                foreach ($ids as $position => $id) {
                    $this->organizations[$id->toString()] = Organization::create(
                        $id,
                        OrganizationName::fromString('Asso'),
                        Slug::fromString('asso-' . $position),
                        CommissionRate::fromBasisPoints(1000),
                    );
                }
            }

            public function save(Organization $organization): void
            {
            }

            public function get(OrganizationId $id): Organization
            {
                ++$this->reads;

                return $this->organizations[$id->toString()];
            }

            public function getBySlug(Slug $slug): Organization
            {
                throw new \LogicException('Not used.');
            }

            public function find(OrganizationId $id): ?Organization
            {
                return $this->organizations[$id->toString()] ?? null;
            }
        };
        $slugs = new OrganizationSlugs($repository);

        self::assertSame('asso-0', $slugs->of($first));
        self::assertSame('asso-1', $slugs->of($second));
        self::assertSame('asso-0', $slugs->of($first));
        self::assertSame('asso-1', $slugs->of($second));
        self::assertSame(2, $repository->reads);
    }
}
