<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Catalog\Services\CatalogCoverage;
use App\Domains\Catalog\Services\ProgrammeCoverageReport;
use Illuminate\Console\Command;

/**
 * What the shop cannot answer yet, room by room.
 *
 * Prints the same numbers the admin screen shows, for the person who is signing sellers and
 * wants to know where to start. Every missing category is a question a customer will be
 * shown greyed out with "bu ürün grubunda henüz satıcımız yok" — so this is a list of
 * sentences the product is currently having to say, in order of how often it has to say
 * them.
 */
final class CatalogueCoverageCommand extends Command
{
    protected $signature = 'refconcept:catalogue-coverage {--style= : Score against one style rather than the whole catalogue}';

    protected $description = 'Reports which room-programme questions the catalogue can answer';

    public function handle(ProgrammeCoverageReport $report, CatalogCoverage $coverage): int
    {
        // Read fresh: an operator running this after publishing a listing wants to see the
        // listing, not a ten-minute-old cache.
        $coverage->forget();

        $style = $this->option('style');
        $style = is_string($style) && $style !== '' ? $style : null;

        $rows = $report->all($style);

        $this->table(
            ['Oda', 'Cevaplanabilir', 'Eksik kategoriler'],
            array_map(static fn (array $room): array => [
                $room['name'],
                sprintf('%d / %d', $room['answerable'], $room['questions']),
                $room['missing_categories'] === []
                    ? '—'
                    : implode(', ', array_slice($room['missing_categories'], 0, 6))
                        .(count($room['missing_categories']) > 6 ? '…' : ''),
            ], $rows),
        );

        $missing = collect($rows)->flatMap(fn (array $room): array => $room['missing_categories'])->unique();

        if ($missing->isEmpty()) {
            $this->info('Her soru karşılanabiliyor.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d kategoride hiç satıcı yok: %s',
            $missing->count(),
            $missing->sort()->implode(', '),
        ));

        // Success either way. A thin catalogue is a fact about the business, not a failing
        // check — exiting non-zero would put this in the same bucket as a broken deploy and
        // teach somebody to ignore it.
        return self::SUCCESS;
    }
}
