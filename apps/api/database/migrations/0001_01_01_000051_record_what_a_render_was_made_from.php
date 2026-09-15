<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A render's three inputs, and what the check said about the picture.
 *
 * Rule K23 of the studio contract: a render is made from the plate, the layout snapshot and
 * the product photographs, and which three can be shown afterwards. Rule K24: a picture
 * that invented furniture or moved a wall is not shown — the check's verdict is kept beside
 * the render so an operator can see why one was made twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_versions', function (Blueprint $table): void {
            $table->jsonb('render_inputs')->nullable()->after('ai_job_id');
            $table->jsonb('fidelity')->nullable()->after('render_inputs');
        });
    }

    public function down(): void
    {
        Schema::table('design_versions', function (Blueprint $table): void {
            $table->dropColumn(['render_inputs', 'fidelity']);
        });
    }
};
