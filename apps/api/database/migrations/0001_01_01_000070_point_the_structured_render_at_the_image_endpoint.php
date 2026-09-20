<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The renderer starts from the room's own render, not from nothing.
 *
 * The first endpoint took a depth map and a sentence. Three real renders came back obeying
 * the geometry exactly and looking like illustrations, because a model trained on depth maps
 * estimated from photographs cannot make a photograph out of a CAD gradient with no colour in
 * it. The endpoint that takes an initial image as well is the one this needs: the depth map
 * fixes where everything is, and the 3D colour render says what it is made of.
 */
return new class extends Migration
{
    private const FROM = 'fal-ai/flux-control-lora-depth';

    private const TO = 'fal-ai/flux-control-lora-depth/image-to-image';

    public function up(): void
    {
        DB::table('ai_models')
            ->where('code', self::FROM)
            ->update([
                'code' => self::TO,
                'name' => 'FLUX.1 Control LoRA Depth (odaya bağlı render)',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('ai_models')
            ->where('code', self::TO)
            ->update(['code' => self::FROM, 'updated_at' => now()]);
    }
};
