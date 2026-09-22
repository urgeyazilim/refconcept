<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console;

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts a testable catalogue on a server that has none.
 *
 * A deploy ships code, so a fresh server is a working shop with nothing in it, and the first
 * thing anybody sees is "ürün bulunamadı". Everything needed to fix that is already in the
 * repository and takes three commands in the right order — which is two too many to remember
 * correctly at the end of a deploy, through a web terminal, when the thing has already
 * failed twice for other reasons.
 *
 * The order matters and is the whole reason this exists: the accounts own the sellers, the
 * sellers own the listings, and the meshes are matched to listings by slug. Run the import
 * before the seeder and it reports thirty-one products missing and exits successfully.
 *
 * **Refuses in production unless told twice.** `DatabaseSeeder` skips demo data there on
 * purpose: these are invented listings under invented sellers with invented stock, and a
 * customer who orders one has bought nothing. Somebody standing up a server to test on has a
 * good reason to want them anyway, and that reason should be typed out rather than assumed.
 */
final class SeedDemoCatalogueCommand extends Command
{
    protected $signature = 'refconcept:seed-demo
        {--force-in-production : Demo listings on a production server, which is nearly always wrong}
        {--skip-accounts : The sellers already exist; only the listings and their files}
        {--credits=200 : Credits for the demo customer, so a design can actually be run}';

    protected $description = 'Demo hesapları, demo kataloğu ve 3B modelleri tek seferde kurar.';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force-in-production')) {
            $this->error('Burası üretim. Demo ilanlar gerçek satıcıya ait değildir ve satın alınamaz.');
            $this->line('Yine de istiyorsanız: --force-in-production');

            return self::FAILURE;
        }

        if (! $this->option('skip-accounts')) {
            $this->components->task('Demo hesaplar ve satıcılar', function (): void {
                $this->callSilent('db:seed', ['--class' => DemoAccountsSeeder::class, '--force' => true]);
            });
        }

        $this->components->task('Demo katalog ve fotoğrafları', function (): void {
            $this->callSilent('db:seed', ['--class' => DemoCatalogSeeder::class, '--force' => true]);
        });

        /*
         * The meshes and the vectors, which no seeder can make.
         *
         * Each one was a paid generation on somebody's machine. Absent, the shop works and
         * the 3D room shows every product as a plain box — which reads as a broken feature
         * rather than as missing data, and is the part somebody testing would report as a bug.
         */
        $package = database_path('catalogue');

        if (is_dir($package)) {
            $this->components->task('3B modeller ve arama vektörleri', function () use ($package): void {
                $this->callSilent('refconcept:import-catalogue', ['dir' => $package]);
            });
        }
        else {
            $this->warn($package.' yok; 3B modeller ve vektörler atlandı.');
        }

        /*
         * Credits, or the demo account cannot do the thing it exists to demonstrate.
         *
         * A design costs credits. Without them somebody logs into a server that has just
         * been stood up, presses "Tasarım oluştur" and is told they cannot afford it — which
         * reads as the credit system being broken rather than as an account with an empty
         * wallet, and is the next thing anybody testing would report.
         *
         * By reference, so running this again tops nobody up twice.
         */
        $credits = (int) $this->option('credits');
        $customer = User::query()->where('email', 'customer@refconcept.local')->first();

        if ($credits > 0 && $customer !== null) {
            $this->components->task(sprintf('Demo müşteriye %d kredi', $credits), function () use ($customer, $credits): void {
                app(CreditLedger::class)->grant(
                    user: $customer,
                    credits: $credits,
                    source: CreditLotSource::Grant,
                    description: 'Demo kurulumu',
                    reference: 'demo-seed:'.$customer->getKey(),
                );
            });
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=gray>ürün</> %d   <fg=gray>3B model</> %d   <fg=gray>vektör</> %d',
            DB::table('products')->where('status', 'active')->count(),
            DB::table('product_media')->where('type', 'model_3d')->count(),
            DB::table('product_embeddings')->count(),
        ));

        if ($customer !== null) {
            $this->line(sprintf(
                '  <fg=gray>giriş</> %s   <fg=gray>kredi</> %d',
                $customer->email,
                app(CreditLedger::class)->balanceFor($customer)->balance,
            ));
        }

        return self::SUCCESS;
    }
}
