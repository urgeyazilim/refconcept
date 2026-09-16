<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A window was a hole with a sill; a customer's window is a double casement, or a French
 * balcony, or three panes across the whole wall. The variant says which. It is nullable
 * because every opening read before today has none, and the plan then judges by the width.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_constraints', function (Blueprint $table): void {
            $table->string('variant', 32)->nullable()->after('type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_constraints
            ADD CONSTRAINT room_constraints_variant_check
            CHECK (variant IS NULL OR variant IN (
                'single', 'double', 'triple', 'french_balcony', 'single_door', 'double_door', 'sliding'
            ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE room_constraints DROP CONSTRAINT IF EXISTS room_constraints_variant_check');

        Schema::table('room_constraints', function (Blueprint $table): void {
            $table->dropColumn('variant');
        });
    }
};
