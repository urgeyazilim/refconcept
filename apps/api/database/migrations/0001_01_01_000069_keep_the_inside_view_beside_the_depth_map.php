<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A depth map alone is a grey gradient, and that is what came back.
 *
 * Three real renders proved the constraint works: walls, corner, openings and every piece of
 * furniture came out where the plan put them, which was impossible before. They also came
 * back as illustrations rather than photographs, and the reason is the input. A depth-control
 * model is trained on maps estimated from real photographs — rich with texture, fabric folds
 * and fine relief — and ours is a CAD render: perfect flat planes, hard edges, no colour
 * anywhere. Asked to invent an entire photorealistic interior out of that, it answered with
 * something equally flat.
 *
 * The workshops that do this for a living give the renderer two things: the depth map as the
 * constraint, and the 3D colour render as the material to start from. We had the colour
 * render all along and were not sending it, which is the strongest input in the room going
 * unused.
 *
 * So the layout keeps a third picture: the same view as the depth map, in colour. Same view
 * deliberately — two views of one room are two rooms as far as the renderer is concerned, so
 * they are drawn together from one camera, inside the walls.
 *
 * It is still not a photograph of anybody's home. It is our own render of geometry the
 * customer confirmed, wearing generic product models and the wall colour the reading named,
 * and the product owner agreed to it leaving on exactly that understanding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            $table->string('inside_disk', 40)->nullable();
            $table->string('inside_path', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_layouts', function (Blueprint $table): void {
            $table->dropColumn(['inside_disk', 'inside_path']);
        });
    }
};
