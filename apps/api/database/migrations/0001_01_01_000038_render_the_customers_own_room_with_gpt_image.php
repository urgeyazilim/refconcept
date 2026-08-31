<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves the render onto OpenAI, because it keeps the customer's room and Gemini does not.
 *
 * The complaint was plain and correct: "sana verdiğim resimle tasarım yaptığın oda aynı
 * değil". A photograph of a room with French doors on the back wall, tall windows down one
 * side and a warm wooden floor came back as a different room — white walls, navy curtains,
 * a door in the wrong place, a different floor. Furniture placed beautifully in somebody
 * else's home.
 *
 * Tested on that exact photograph, with the same prompt and the same four product images.
 * `gpt-image-2` through `/images/edits` returned the same room: the same doors, the same
 * windows, the same floor tone and direction, the same cornice, the same camera. It also
 * removed the stock-library watermark cleanly, which is likely part of why Gemini gave up
 * and drew something new — a watermarked input is one a model would rather not reproduce.
 *
 * Draft and premium both move. There is no honest version of "the cheap one gives you a
 * different room": if the room is not the customer's, nothing else about the picture
 * matters, and a preview that lies is worse than no preview.
 *
 * Gemini stays configured as the fallback. It is a capable model that happens to be worse
 * at this one thing, and a render that produces the wrong room beats one that produces
 * nothing when OpenAI is unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provider = DB::table('ai_providers')->where('code', 'openai')->first();

        if ($provider === null) {
            return;
        }

        $model = $this->model((string) $provider->id);

        foreach (['image_render_draft', 'image_render_premium'] as $task) {
            $route = DB::table('ai_task_routes')->where('task', $task)->first();

            if ($route === null) {
                continue;
            }

            DB::table('ai_task_routes')->where('task', $task)->update([
                'primary_model_id' => $model,
                /*
                 * Whatever was primary becomes the fallback, which is Gemini today. A
                 * render in the wrong room is a poor answer; no render at all while a
                 * provider is down is a worse one.
                 */
                'fallback_model_id' => $route->primary_model_id,
                // Editing a photograph at high fidelity took ninety-six seconds in testing,
                // and the old sixty-second ceiling would have failed every one of them.
                'timeout_seconds' => 180,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Put Gemini back in front by swapping the pair, rather than naming a model this
        // migration did not necessarily create.
        foreach (['image_render_draft', 'image_render_premium'] as $task) {
            $route = DB::table('ai_task_routes')->where('task', $task)->first();

            if ($route?->fallback_model_id === null) {
                continue;
            }

            DB::table('ai_task_routes')->where('task', $task)->update([
                'primary_model_id' => $route->fallback_model_id,
                'fallback_model_id' => $route->primary_model_id,
                'updated_at' => now(),
            ]);
        }
    }

    private function model(string $providerId): string
    {
        $existing = DB::table('ai_models')
            ->where('provider_id', $providerId)
            ->where('code', 'gpt-image-2')
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('ai_models')->insert([
            'id' => $id,
            'provider_id' => $providerId,
            'code' => 'gpt-image-2',
            'name' => 'GPT Image 2',
            'modality' => 'image',
            'supports_structured_output' => false,
            'supports_image_input' => true,
            /*
             * Not sent for this model — it answers 400 rather than ignoring it, which is
             * the better behaviour and also the reason this is a column rather than a
             * constant somewhere in the adapter.
             */
            'max_output_tokens' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
