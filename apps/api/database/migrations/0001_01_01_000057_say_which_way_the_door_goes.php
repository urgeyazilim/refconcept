<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A door hangs on one jamb and opens one way, and the floor it sweeps is floor nothing may
 * stand on. Nullable: every door recorded before today is drawn as it always was, hung on
 * the start jamb and opening into the room, until somebody says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_constraints', function (Blueprint $table): void {
            $table->string('swing', 16)->nullable()->after('variant');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_constraints
            ADD CONSTRAINT room_constraints_swing_check
            CHECK (swing IS NULL OR swing IN ('start_in', 'end_in', 'start_out', 'end_out'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE room_constraints DROP CONSTRAINT IF EXISTS room_constraints_swing_check');

        Schema::table('room_constraints', function (Blueprint $table): void {
            $table->dropColumn('swing');
        });
    }
};
