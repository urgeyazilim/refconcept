<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Roles created by seeding and protected from deletion.
 *
 * A customer holds no role at all: customer capabilities are the baseline every
 * authenticated account has, so an empty role set is the safe default rather than a
 * privilege that could be granted by mistake.
 */
enum SystemRole: string
{
    /** Unrestricted platform access. Bypasses permission checks via Gate::before. */
    case SuperAdmin = 'super-admin';

    /** Day-to-day platform operations: moderation, support, order handling. */
    case Operator = 'operator';

    /** Read-only platform access, e.g. for finance review. */
    case Analyst = 'analyst';

    /** Owner of a seller organization. */
    case SellerOwner = 'seller-owner';

    /** Seller staff member with operational access inside one organization. */
    case SellerStaff = 'seller-staff';

    public function scope(): RoleScope
    {
        return match ($this) {
            self::SuperAdmin, self::Operator, self::Analyst => RoleScope::Platform,
            self::SellerOwner, self::SellerStaff => RoleScope::Organization,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Süper Admin',
            self::Operator => 'Operasyon',
            self::Analyst => 'Analist',
            self::SellerOwner => 'Satıcı Sahibi',
            self::SellerStaff => 'Satıcı Personeli',
        };
    }

    /**
     * Permissions granted by this role.
     *
     * Super admin is intentionally empty: it is handled by a Gate::before bypass, and
     * enumerating every permission here would drift out of date the moment one is added.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],

            self::Operator => [
                Permission::UsersView,
                Permission::UsersManage,
                Permission::OrganizationsView,
                Permission::OrganizationsManage,
                Permission::AuditView,
                Permission::PaymentsView,
                Permission::PaymentsSettle,
                Permission::SellersView,
                Permission::SellersManage,
                Permission::CatalogModerate,
                Permission::OrdersView,
                Permission::CreditsManage,
                Permission::AiManage,
                Permission::JobsManage,
                Permission::AnalyticsView,
                /*
                 * Not FlagsManage. Turning a feature on for everybody, or changing a
                 * system setting, is a release decision rather than an operational one —
                 * and the one power on this list whose blast radius is the whole platform.
                 */
            ],

            self::Analyst => [
                Permission::UsersView,
                Permission::OrganizationsView,
                Permission::AuditView,
                // Reads payments, cannot settle one: an analyst answering "did it arrive"
                // does not also get to decide that it did. The same shape runs through the
                // rest of the list — every view, no verbs.
                Permission::PaymentsView,
                Permission::SellersView,
                Permission::OrdersView,
                Permission::AnalyticsView,
            ],

            self::SellerOwner => [
                Permission::SellerProfileView,
                Permission::SellerProfileManage,
                Permission::SellerUsersView,
                Permission::SellerUsersManage,
            ],

            self::SellerStaff => [
                Permission::SellerProfileView,

                /*
                 * Reading the team, but never changing it.
                 *
                 * Somebody working a returns queue sees "kim onayladı" next to a decision,
                 * and a name they cannot look up is worse than no name. Who may *join* the
                 * company, and what they may do once they have, stays with the owner —
                 * that is the whole distinction between the two roles.
                 */
                Permission::SellerUsersView,
            ],
        };
    }
}
