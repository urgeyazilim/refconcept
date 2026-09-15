<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plate: the room photograph with the furniture taken out.
 *
 * Every render of a room starts from it — the walls, floor, windows and doors are the
 * customer's own, and the furniture is whatever the layout says. It is a room media row
 * like the photograph it came from, on the same private disk under the same rules, and it
 * remembers which photograph that was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_media', function (Blueprint $table): void {
            $table->uuid('source_media_id')->nullable()->after('type');

            $table->foreign('source_media_id')->references('id')->on('room_media')->nullOnDelete();
        });

        DB::statement('ALTER TABLE room_media DROP CONSTRAINT IF EXISTS room_media_type_check');
        DB::statement("ALTER TABLE room_media ADD CONSTRAINT room_media_type_check CHECK (type IN ('photo', 'floor_plan', 'inspiration', 'document', 'plate'))");
    }

    public function down(): void
    {
        DB::table('room_media')->where('type', 'plate')->delete();

        DB::statement('ALTER TABLE room_media DROP CONSTRAINT IF EXISTS room_media_type_check');
        DB::statement("ALTER TABLE room_media ADD CONSTRAINT room_media_type_check CHECK (type IN ('photo', 'floor_plan', 'inspiration', 'document'))");

        Schema::table('room_media', function (Blueprint $table): void {
            $table->dropForeign(['source_media_id']);
            $table->dropColumn('source_media_id');
        });
    }
};
