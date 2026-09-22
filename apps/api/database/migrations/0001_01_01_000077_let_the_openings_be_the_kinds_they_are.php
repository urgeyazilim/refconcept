<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The kinds and the directions the enums learned, told to the table as well.
 *
 * `room_constraints` carries a check constraint per column listing the values it will take,
 * and the PHP enums beside them had grown four kinds — a sealed pane, a vasistas, a folding
 * balcony door, and a sliding door for an interior doorway — and one direction, the top-hung
 * sash. The application accepted all five and the database refused them, so choosing
 * "Üstten açılır" on a window answered with a five hundred and a stack trace about a check
 * constraint, which is a sentence nobody can act on.
 *
 * Worth keeping the check rather than dropping it: the column is where a typo in a migration,
 * a stale client or a bad import would otherwise land quietly, and "window" with a swing of
 * `sliding` is not a thing that can be drawn. The cost is this file, once per new kind.
 *
 * `single_door` is deliberately still allowed for a window and `single` for a door, because
 * old rows have them and rewriting somebody's room to satisfy a constraint is worse than a
 * loose one. Which variant may go with which type is enforced where it can explain itself —
 * {@see OpeningVariant::fits()} — and answers a customer rather than a log.
 */
return new class extends Migration
{
    private const VARIANTS = [
        'single', 'double', 'triple', 'fixed', 'awning', 'french_balcony',
        'single_door', 'double_door', 'sliding', 'folding',
    ];

    private const SWINGS = ['start_in', 'end_in', 'start_out', 'end_out', 'top_hung'];

    public function up(): void
    {
        $this->allow('variant', self::VARIANTS);
        $this->allow('swing', self::SWINGS);
    }

    public function down(): void
    {
        /*
         * Anything outside the old list is cleared rather than left to fail the constraint.
         *
         * A rollback that cannot complete because of data written since is a rollback that
         * leaves the table with no check at all. A window that loses its "vasistas" is a
         * window somebody re-picks in a moment; a half-applied migration is an afternoon.
         */
        DB::statement("update room_constraints set variant = null where variant in ('fixed', 'awning', 'folding')");
        DB::statement("update room_constraints set swing = null where swing = 'top_hung'");

        $this->allow('variant', ['single', 'double', 'triple', 'french_balcony', 'single_door', 'double_door', 'sliding']);
        $this->allow('swing', ['start_in', 'end_in', 'start_out', 'end_out']);
    }

    /**
     * @param  list<string>  $values
     */
    private function allow(string $column, array $values): void
    {
        $name = "room_constraints_{$column}_check";
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $values));

        DB::statement("alter table room_constraints drop constraint if exists {$name}");
        DB::statement("alter table room_constraints add constraint {$name} check ({$column} is null or {$column}::text in ({$list}))");
    }
};
