<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Render al" was quietly choosing different furniture.
 *
 * A customer arranges their room in 3D — moves the sofa, turns the chairs, decides they like
 * it — and presses "Render al". The request it sends even says so: "Oda planındaki yerleşimi
 * birebir uygula". Behind it, the pipeline ran the whole thing again from the top: a fresh
 * room analysis, a fresh design plan, and a fresh pass of the matcher over the catalogue.
 *
 * The product owner saw it on the waiting screen — "Ürünleri seçiyoruz" — and asked why,
 * which is the right question. Three things are wrong with it and only one is the wording.
 *
 * The plan is generated again, which is a paid call to answer a question nobody asked: the
 * layout is already decided, by them, by hand. The matcher runs again over a catalogue that
 * may have changed, so the picture can come back holding a different sofa from the one
 * standing in their 3D room and the one in the basket underneath it — which is exactly the
 * failure the pipeline's own comments say the matching order exists to prevent. And the
 * minute they spend waiting is narrated as work that should not be happening.
 *
 * So a version can be marked as following a layout. It inherits the parent's plan and the
 * parent's products rather than making new ones, and the only thing it does is draw the
 * picture.
 *
 * Off by default, because a refinement — "make the sofa darker" — is a different request and
 * does want a new plan and a new look through the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_versions', function (Blueprint $table): void {
            $table->boolean('follows_layout')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('design_versions', function (Blueprint $table): void {
            $table->dropColumn('follows_layout');
        });
    }
};
