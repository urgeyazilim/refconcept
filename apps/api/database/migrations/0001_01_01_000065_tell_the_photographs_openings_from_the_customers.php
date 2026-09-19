<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing a reading found could ever be read again.
 *
 * Openings are written into a room only when it has none: "a customer who has already
 * written down their window is not helped by a second one appearing beside it at slightly
 * different coordinates". True, and it had a consequence nobody looked at. The first reading
 * puts a door and a window on the walls, and from that moment the room *has* openings — so
 * every later reading of the same photographs is discarded, whatever it found.
 *
 * The product owner pressed "Tasarıma göre yeniden diz" and said the room was the same.
 * It was, and nothing they could press would have changed it: arranging re-runs the
 * furniture, not the walls, and re-reading the photographs could not touch the walls either.
 * The only route from a wrong window to a right one was dragging it by hand.
 *
 * The rule was right and the reason it was right was ownership, not existence. So the column
 * says who put each opening there. A reading may replace what a reading put there, and may
 * never touch what the customer wrote or moved.
 *
 * Backfilled from the sentence the proposer has been writing into `notes` since openings were
 * first adopted, which until now was the only mark of where one came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_constraints', function (Blueprint $table): void {
            /*
             * 'user' by default, deliberately.
             *
             * Everything written before this column existed and not marked by the proposer's
             * note was either typed by somebody or predates the reading placing anything —
             * and the safe mistake is to protect a row the photograph could have replaced,
             * not to overwrite one somebody put there themselves.
             */
            $table->string('source', 12)->default('user');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_constraints
            ADD CONSTRAINT room_constraints_source_check
            CHECK (source IN ('ai', 'user'))
        SQL);

        DB::table('room_constraints')
            ->where('notes', 'Fotoğraftan tespit edildi.')
            ->update(['source' => 'ai']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE room_constraints DROP CONSTRAINT IF EXISTS room_constraints_source_check');

        Schema::table('room_constraints', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
