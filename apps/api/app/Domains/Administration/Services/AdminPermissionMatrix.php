<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Support\Facades\Route;

/**
 * Which permission every administrative route needs. One table, one authority.
 *
 * Before this, each controller decided for itself, and a new endpoint was protected only
 * if somebody remembered to protect it — a check that is invisible when it is missing. The
 * matrix inverts that: {@see uncovered()} lists every admin route with no entry here, and
 * the test suite fails on a non-empty list. Adding an unguarded admin endpoint is
 * therefore no longer possible without noticing.
 *
 * Entries are matched by route name, longest prefix first, so `admin.finance.settlements.`
 * can require more than `admin.finance.` without repeating every leaf.
 *
 * **Split by consequence, not by screen.** Reading a seller's file and suspending them
 * live on the same page and are different powers; a permission named after the page would
 * hand both to anybody who needed either. That is why nearly every area appears twice, as
 * a view and as a verb.
 */
final class AdminPermissionMatrix
{
    /**
     * Route-name prefix => the permission it demands.
     *
     * Ordered here for reading; resolution sorts by length so the order below does not
     * matter to correctness.
     *
     * @var array<string, Permission>
     */
    private const RULES = [
        // --- sellers -------------------------------------------------------------
        'v1.admin.sellers.' => Permission::SellersView,
        'v1.admin.sellers.suspend' => Permission::SellersManage,
        'v1.admin.sellers.reactivate' => Permission::SellersManage,
        'v1.admin.sellers.commission' => Permission::SellersManage,
        'v1.admin.seller-applications.' => Permission::SellersView,
        'v1.admin.seller-applications.approve' => Permission::SellersManage,
        'v1.admin.seller-applications.reject' => Permission::SellersManage,
        'v1.admin.seller-applications.review' => Permission::SellersManage,
        // A tax certificate is somebody's identity document; reading one is a decision.
        'v1.admin.seller-documents.' => Permission::SellersManage,

        // --- catalogue -----------------------------------------------------------
        'v1.admin.products.' => Permission::CatalogModerate,

        // --- orders --------------------------------------------------------------
        'v1.admin.orders.' => Permission::OrdersView,

        /*
         * Customers. Reading an account is a support job and sits under the same permission
         * as any other look at a user record.
         *
         * Opening one of their photographs is listed separately even though it needs the
         * same permission, because the matrix is what somebody reads to answer "who can see
         * the inside of a customer's house". Folding it into the prefix above would make
         * that answer something you have to work out rather than something you can read.
         */
        'v1.admin.customers.' => Permission::UsersView,
        'v1.admin.customers.media' => Permission::UsersView,

        // --- payments and finance -------------------------------------------------
        'v1.admin.payments.' => Permission::PaymentsView,
        'v1.admin.payments.transfers.confirm' => Permission::PaymentsSettle,
        'v1.admin.payments.transfers.reject' => Permission::PaymentsSettle,
        'v1.admin.payments.accounts.store' => Permission::PaymentsSettle,
        'v1.admin.payments.accounts.update' => Permission::PaymentsSettle,

        'v1.admin.finance.' => Permission::PaymentsView,
        'v1.admin.finance.settlements.build' => Permission::PaymentsSettle,
        'v1.admin.finance.settlements.approve' => Permission::PaymentsSettle,
        'v1.admin.finance.settlements.paid' => Permission::PaymentsSettle,
        'v1.admin.finance.settlements.cancel' => Permission::PaymentsSettle,
        'v1.admin.finance.commission.store' => Permission::PaymentsSettle,
        'v1.admin.finance.commission.update' => Permission::PaymentsSettle,

        'v1.admin.refunds.' => Permission::PaymentsView,
        'v1.admin.refunds.store' => Permission::PaymentsSettle,
        'v1.admin.refunds.retry' => Permission::PaymentsSettle,

        // --- credits ---------------------------------------------------------------
        'v1.admin.credits.' => Permission::CreditsManage,

        // --- the AI console ---------------------------------------------------------
        'v1.admin.ai.' => Permission::AiManage,

        // --- the platform itself -----------------------------------------------------
        'v1.admin.audit.' => Permission::AuditView,
        'v1.admin.analytics.' => Permission::AnalyticsView,
        'v1.admin.system.jobs.' => Permission::JobsManage,
        'v1.admin.system.webhooks.' => Permission::JobsManage,
        // Turning a feature on for everybody is a release decision, and the one power here
        // whose blast radius is the whole platform.
        'v1.admin.system.flags.' => Permission::FlagsManage,
        'v1.admin.system.settings.' => Permission::FlagsManage,
    ];

    /**
     * What this route requires, or null when nothing claims it.
     *
     * Longest match wins, so a leaf can demand more than its branch without the branch
     * having to enumerate its leaves.
     */
    public function permissionFor(?string $routeName): ?Permission
    {
        if ($routeName === null) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach (self::RULES as $prefix => $permission) {
            if (! str_starts_with($routeName, $prefix)) {
                continue;
            }

            if (strlen($prefix) > $bestLength) {
                $best = $permission;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * Admin routes that no rule covers.
     *
     * The gate. A non-empty list means somebody has added an administrative endpoint
     * without deciding who may call it — which is exactly the mistake that is invisible
     * until it is exploited.
     *
     * @return list<string>
     */
    public function uncovered(): array
    {
        $uncovered = [];

        // ->getRoutes() rather than iterating the collection: the interface is only
        // countable and traversable, and PHPStan is right that that is not the same thing.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }

            $name = $route->getName();

            if ($name !== null && $this->permissionFor($name) !== null) {
                continue;
            }

            $uncovered[] = $name ?? $route->uri();
        }

        return array_values(array_unique($uncovered));
    }

    /**
     * The whole matrix, for the screen that shows an operator what they can do.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return array_map(static fn (Permission $permission): string => $permission->value, self::RULES);
    }
}
