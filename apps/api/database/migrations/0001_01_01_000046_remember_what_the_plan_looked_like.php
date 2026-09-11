<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the picture of a layout is kept.
 *
 * The browser renders the room it has already agreed with the customer — walls at the
 * confirmed measurements, openings where their openings are, every piece at its real size in
 * its chosen place — and that image goes to the renderer as the structure to follow. This is
 * the pointer to it.
 *
 * Three columns rather than a `metadata` bag, because it is one specific thing rather than a
 * place to put things. A bag on a table is a column nobody can query, nobody can index, and
 * everybody adds one more key to.
 *
 * No foreign key to a media table, because there is no row: one layout has one current
 * snapshot, it is regenerated whenever the furniture moves, and a file nothing points at is
 * rubbish rather than a record. The object lives on the private disk with the customer's own
 * photographs and under the same rules — it is a picture of the inside of their home, and
 * that it was drawn rather than photographed changes nothing about who may see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            $table->string('snapshot_disk', 40)->nullable();

            // Not an index and deliberately not unique: the path is a random key under the
            // layout's id, and the only thing that ever looks it up is this row.
            $table->string('snapshot_path', 500)->nullable();

            // When it was taken, so a snapshot older than the layout it claims to show can be
            // spotted rather than quietly sent to a renderer as the truth.
            $table->timestampTz('snapshot_taken_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            $table->dropColumn(['snapshot_disk', 'snapshot_path', 'snapshot_taken_at']);
        });
    }
};
