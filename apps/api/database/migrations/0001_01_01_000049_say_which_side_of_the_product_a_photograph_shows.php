<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which side of the product a photograph shows.
 *
 * A mesh made from one photograph has to invent the back of the sofa, because the back was
 * never photographed. Given the back as well, it does not have to invent anything — and the
 * generator charges the same for four views as for one. The only thing missing is knowing
 * which photograph is which, and a catalogue photograph does not say.
 *
 * Four values and no more. "Front, left, back, right" is what the generator asks for; a fifth
 * value here would be a label nothing can use, and "detail" or "in a room" is the absence of
 * a label rather than one of its own — those photographs are left null and never sent.
 *
 * One of each per product, enforced. Two photographs both claiming to be the back is two
 * answers to the same question, and the one the query happened to return first would be the
 * one the generator was given. A wrong view is worse than a missing one: told that the front
 * is the back, the generator fuses two fronts and produces something that is not furniture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->string('view', 8)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_media
            ADD CONSTRAINT product_media_view_check
            CHECK (view IS NULL OR view IN ('front', 'left', 'back', 'right'))
        SQL);

        /*
         * Images only, and one of each.
         *
         * A 3D model has no side and neither does a document; the partial index says so
         * rather than leaving it to whoever writes the next uploader.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX product_media_single_view
            ON product_media (product_id, view)
            WHERE type = 'image' AND view IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_media_single_view');
        DB::statement('ALTER TABLE product_media DROP CONSTRAINT IF EXISTS product_media_view_check');

        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropColumn('view');
        });
    }
};
