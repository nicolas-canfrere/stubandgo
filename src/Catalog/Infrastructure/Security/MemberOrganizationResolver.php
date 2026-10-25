<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Security;

use App\Catalog\Core\Application\Query\GetMemberOrganization;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Injects the organization of the current member into any controller argument typed MemberOrganizationView, so that
 * back-office routes never take the organization from the URL or the payload.
 *
 * Arguments are resolved before #[IsGranted] is evaluated: a member without a stubandgo organization is denied here,
 * the role itself is checked by the CATALOG_ORGANIZATION_ADMIN attribute.
 */
final readonly class MemberOrganizationResolver implements ValueResolverInterface
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<MemberOrganizationView>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (MemberOrganizationView::class !== $argument->getType()) {
            return [];
        }

        $member = $this->tokens->getToken()?->getUser();
        $keycloakOrganizationId = $member instanceof AuthenticatedMember ? $member->keycloakOrganizationId() : null;
        $organization = null === $keycloakOrganizationId ? null : $this->queryBus->ask(new GetMemberOrganization($keycloakOrganizationId));

        return [$organization ?? throw new AccessDeniedException('The member belongs to no organization of the platform.')];
    }
}
