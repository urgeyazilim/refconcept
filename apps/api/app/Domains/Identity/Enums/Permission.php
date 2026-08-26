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

    /*
     * Finance is split in two on purpose. Reading a payment is a support job; confirming
     * that money arrived releases goods and cannot be undone, so it is a separate grant
     * somebody has to be given deliberately.
     */
    case PaymentsView = 'platform.payments.view';
    case PaymentsSettle = 'platform.payments.settle';

    /*
     * The rest of the platform surface, one permission per thing an operator can do.
     *
     * Split by *consequence*, not by screen. Reading a seller's file and suspending them
     * are different powers even though they live on the same page, and a permission named
     * after the page would grant both to anybody who needs either.
     */
    case SellersView = 'platform.sellers.view';
    case SellersManage = 'platform.sellers.manage';
    case CatalogModerate = 'platform.catalog.moderate';
    case OrdersView = 'platform.orders.view';
    case CreditsManage = 'platform.credits.manage';
    case AiManage = 'platform.ai.manage';
    case FlagsManage = 'platform.flags.manage';
    case JobsManage = 'platform.jobs.manage';
    case AnalyticsView = 'platform.analytics.view';

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
            self::PaymentsView => 'Ödemeleri ve havaleleri görüntüleme',
            self::PaymentsSettle => 'Havale onaylama ve reddetme',
            self::SellersView => 'Satıcıları ve başvuruları görüntüleme',
            self::SellersManage => 'Satıcı onaylama, askıya alma ve komisyon değiştirme',
            self::CatalogModerate => 'Ürün moderasyonu',
            self::OrdersView => 'Siparişleri görüntüleme',
            self::CreditsManage => 'Kredi paketleri, promosyonlar ve bakiye düzeltme',
            self::AiManage => 'Yapay zekâ sağlayıcı ve görev yönetimi',
            self::FlagsManage => 'Özellik anahtarları ve sistem ayarları',
            self::JobsManage => 'Başarısız işler ve bildirimleri yönetme',
            self::AnalyticsView => 'Platform raporlarını görüntüleme',
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
