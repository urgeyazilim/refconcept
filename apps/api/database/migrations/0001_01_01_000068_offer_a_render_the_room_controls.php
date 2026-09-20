<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A renderer that cannot move a wall.
 *
 * Every render so far has been a photorealistic model handed a photograph of the plan and
 * asked to follow it. It does not follow it. It takes the idea and resolves the rest however
 * it likes, and what it likes is a better picture: the product owner's design came back with
 * the window on a different wall from their own flat, an extra armchair nobody sells, a plant
 * and a table lamp. Our own fidelity check said "faithful: false" and named all four, and we
 * showed the picture anyway, because there was nothing better to show.
 *
 * A control-conditioned model is the difference between showing it the room and giving it the
 * room. The arrangement goes in as a depth map, which is a hard constraint rather than a
 * reference, so the walls, the openings and every product come out where the customer's own
 * room put them.
 *
 * **Only the depth map leaves this system.** fal fetches what it is given, so anything sent
 * to it is put on its CDN first, behind an unguessable but unauthenticated link — and a
 * photograph of somebody's living room must never be on such a link. So the endpoint is the
 * text-to-image one: what goes up is a grey geometric frame with no colour, no texture, no
 * window view and nothing of theirs in it. Everything else — their oak floor, their wall
 * colour, the daylight — is carried in words, which is why the reading records surfaces.
 *
 * Paused, and deliberately. It costs money per image, the quality against gpt-image-2 has not
 * been judged on a real room yet, and nothing in this system spends a customer's money on a
 * comparison nobody has made.
 */
return new class extends Migration
{
    private const MODEL = 'fal-ai/flux-control-lora-depth';

    private const TASK = 'image_render_structured';

    public function up(): void
    {
        $provider = DB::table('ai_providers')->where('code', 'fal')->first();

        if ($provider === null) {
            return;
        }

        $modelId = DB::table('ai_models')->where('code', self::MODEL)->value('id');

        if ($modelId === null) {
            $modelId = (string) Str::uuid7();

            DB::table('ai_models')->insert([
                'id' => $modelId,
                'provider_id' => $provider->id,
                'code' => self::MODEL,
                'name' => 'FLUX.1 Control LoRA Depth (odaya bağlı render)',
                'modality' => 'image',
                // The depth map is an image input, and the only one this path ever sends.
                'supports_image_input' => true,
                'supports_structured_output' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('ai_task_routes')->where('task', self::TASK)->exists()) {
            return;
        }

        DB::table('ai_task_routes')->insert([
            'id' => (string) Str::uuid7(),
            'task' => self::TASK,
            'primary_model_id' => $modelId,
            'fallback_model_id' => null,
            /*
             * Nobody is charged while it is being judged.
             *
             * The platform pays for the few it runs against the renderer it already has, and
             * the price is settled when somebody has looked at both pictures and decided.
             */
            'credit_cost' => 0,
            'max_attempts' => 1,
            // A render is seconds and somebody is usually watching one.
            'timeout_seconds' => 180,
            'is_paused' => true,
            // The table refuses a pause with no reason on it, which is the right rule: a
            // switch nobody can explain is a switch nobody dares turn back on.
            'pause_reason' => 'Deneme aşamasında. Görsel başına ücretli; mevcut render ile karşılaştırılıp karar verilene kadar kapalı.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_task_routes')->where('task', self::TASK)->delete();
        DB::table('ai_models')->where('code', self::MODEL)->delete();
    }
};
