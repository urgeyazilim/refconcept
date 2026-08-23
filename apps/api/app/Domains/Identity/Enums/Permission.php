<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * The canonical permission list.
 *
 * Permissions live in code, not in the admin UI: an authorization check referencing a
 * permission that was never defined would silently deny (or, worse, be typo'd into a
 * check that never matches). Seeding from this enum keeps code and database aligned,
 * and `permissions.name` is the string persisted in `role_permissions`.
 *
 * Later phases extend this list; nothing is ever renamed without a migration that
 * rewrites existing grants.
 */
enum Permission: string
{
    // --- platform administration -------------------------------------------------
    case UsersView = 'platform.users.view';
    case UsersManage = 'platform.users.manage';
    case RolesView = 'platform.roles.view';
    case RolesManage = 'platform.roles.manage';
    case OrganizationsView = 'platform.organizations.view';
    case OrganizationsManage = 'platform.organizations.manage';
    case AuditView = 'platform.audit.view';
    case SystemSettingsManage = 'platform.settings.manage';

    // --- seller-scoped -----------------------------------------------------------
    case SellerProfileView = 'seller.profile.view';
    case SellerProfileManage = 'seller.profile.manage';
    case SellerUsersView = 'seller.users.view';
    case SellerUsersManage = 'seller.users.manage';

    /** Grouping used by the admin UI and by `permissions.group`. */
    public function group(): string
    {
        return str_contains($this->value, 'seller.') ? 'seller' : 'platform';
    }

    public function description(): string
    {
        return match ($this) {
            self::UsersView => 'Kullanıcıları görüntüleme',
            self::UsersManage => 'Kullanıcı durumu ve bilgilerini yönetme',
            self::RolesView => 'Rol ve yetkileri görüntüleme',
            self::RolesManage => 'Rol atama ve yetki düzenleme',
            self::OrganizationsView => 'Organizasyonları görüntüleme',
            self::OrganizationsManage => 'Organizasyon oluşturma ve durum değiştirme',
            self::AuditView => 'Denetim kayıtlarını görüntüleme',
            self::SystemSettingsManage => 'Sistem ayarlarını yönetme',
            self::SellerProfileView => 'Satıcı profilini görüntüleme',
            self::SellerProfileManage => 'Satıcı profilini düzenleme',
            self::SellerUsersView => 'Satıcı kullanıcılarını görüntüleme',
            self::SellerUsersManage => 'Satıcı kullanıcılarını yönetme',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
