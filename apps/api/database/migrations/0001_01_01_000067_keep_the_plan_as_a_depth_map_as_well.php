<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture of the plan is a suggestion. A depth map is a constraint.
 *
 * The renderer is handed a screenshot of the 3D room and asked to follow it. It is a
 * photorealistic model looking at a reference image: it takes the idea and resolves the rest
 * however it likes, and what it likes is a better picture. The product owner's design came
 * back with the window on a different wall from their own, an armchair nobody sells, a plant
 * and a table lamp — and the fidelity check said so, and we showed it anyway, because there
 * was nothing better to show.
 *
 * The fix is not a sterner prompt. A depth map goes into a control-conditioned renderer as
 * structure rather than as advice, and then the walls, the openings and every piece of
 * furniture are where the customer's room put them, because the geometry is an input.
 *
 * So the layout keeps two pictures of itself: the one a person would recognise, and the one
 * a renderer can be made to obey. Beside the colour snapshot rather than instead of it — the
 * colour frame is what the fidelity check compares against and what a human reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            // Alongside snapshot_disk / snapshot_path, and written in the same request, so
            // the two are always a picture of the same arrangement or both absent.
            $table->string('depth_disk', 40)->nullable();
            $table->string('depth_path', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            $table->dropColumn(['depth_disk', 'depth_path']);
        });
    }
};
