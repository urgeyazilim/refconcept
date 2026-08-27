<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Products\Enums\ProductStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Takes the end-to-end suite's leavings back out of the catalogue.
 *
 * The suite lists real products through the real endpoints, which is the point of it — a
 * fixture inserted behind the API's back would not prove that a basket refuses what the
 * catalogue refuses. What it never did was tidy up, and the fixtures go into the real
 * `kanepe` category with a flat five-kilobyte placeholder for a photograph. After enough
 * runs a development catalogue of eighteen real products carried a hundred and twenty-six
 * test ones, and the design matcher — asked for a sofa, doing exactly its job — offered
 * five of them. The rendered room looked wrong because the shopping list under it was
 * furniture that does not exist.
 *
 * Archived rather than deleted. Fixture sellers have orders, payouts and ledger entries
 * behind them, and those tables are append-only by trigger: a DELETE would either fail or,
 * worse, succeed partly and leave an order pointing at a product that is gone. Archiving
 * takes them out of search, out of matching and out of the storefront, which is the whole
 * of what is wrong with them, and leaves every financial record intact and explicable.
 *
 * Refuses to run in production. There are no fixtures there, so a hit would mean the
 * pattern had caught something real.
 */
final class PurgeE2eFixturesCommand extends Command
{
    /**
     * The mark every fixture account carries.
     *
     * Kept in step with `E2E_EMAIL_DOMAIN` in tests/e2e/support/accounts.ts. A domain no
     * real account can hold, rather than a name pattern that might one day match a seller
     * who happens to put digits in their product titles.
     */
    private const FIXTURE_DOMAIN = '@e2e.refconcept.local';

    /**
     * Fixtures listed before the domain marker existed.
     *
     * Their addresses end in a millisecond timestamp on the ordinary local domain. Matched
     * only for the accounts the suite creates — `seller-`, `operator-`, `journey-` and the
     * like — so a seeded account keeps its products.
     */
    private const LEGACY_PATTERN = '^(seller|operator|customer|journey|buyer|admin)-[0-9]{13}-[0-9]+@refconcept\.local$';

    protected $signature = 'refconcept:purge-e2e-fixtures {--dry-run : Say what would be archived and change nothing}';

    protected $description = 'Archives products left behind by the end-to-end suite';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Üretimde çalıştırılamaz.');

            return self::FAILURE;
        }

        $organizations = $this->fixtureOrganizations();

        if ($organizations === []) {
            $this->info('Temizlenecek test artığı yok.');

            return self::SUCCESS;
        }

        $products = DB::table('products')
            ->whereIn('organization_id', $organizations)
            ->where('status', '!=', ProductStatus::Archived->value);

        $count = $products->count();

        if ($this->option('dry-run')) {
            $this->line(sprintf('%d satıcı, %d ürün arşivlenecek.', count($organizations), $count));

            return self::SUCCESS;
        }

        $products->update([
            'status' => ProductStatus::Archived->value,
            'updated_at' => now(),
        ]);

        $this->info(sprintf('%d satıcının %d ürünü arşivlendi.', count($organizations), $count));

        return self::SUCCESS;
    }

    /**
     * Organizations whose owner is a fixture account.
     *
     * @return array<int, string>
     */
    private function fixtureOrganizations(): array
    {
        $users = DB::table('users')
            ->where(function ($query): void {
                $query
                    ->where('email', 'like', '%'.self::FIXTURE_DOMAIN)
                    ->orWhere('email', '~', self::LEGACY_PATTERN);
            })
            ->pluck('id')
            ->all();

        if ($users === []) {
            return [];
        }

        return DB::table('organizations')
            ->whereIn('owner_user_id', $users)
            ->pluck('id')
            ->all();
    }
}
