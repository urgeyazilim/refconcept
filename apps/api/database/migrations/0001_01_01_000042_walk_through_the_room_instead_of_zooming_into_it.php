<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes the camera walk instead of zoom.
 *
 * The first version asked for "a slow, smooth cinematic camera move" and "a gentle dolly
 * forward with a slight arc", and the customer's verdict was exact: it zooms into the
 * picture and nothing else. Pulling frames out of the film confirmed it — at nought, two,
 * five and seven seconds the room is identical and simply larger. No wall passes out of
 * frame, nothing is revealed. It is the photograph on a slow push, which is a thing anybody
 * can do to any image for free.
 *
 * Three findings changed the wording, all from calling the API rather than reading about it:
 *
 *  - **"Slow" and "gentle" are instructions the model follows.** Eight seconds of gentle
 *    anything is a still. The move is now named in cinematography terms — steadicam, glides
 *    forward, turns to the right — which is what Veo's own prompt guide asks for.
 *  - **The turn is what makes it a room rather than a picture.** Forward travel alone still
 *    reads as a zoom, because the frame changes size without changing angle. A yaw partway
 *    through reveals the wall the camera was facing, and that is the moment it becomes a
 *    space somebody is standing in.
 *  - **An orbit was tried and rejected.** A lateral arc around the seating group moved the
 *    camera further and looked better for two seconds, and then deleted both armchairs and
 *    the rug. Furniture that vanishes mid-shot is worse than a shot that travels less: the
 *    promise is that everything in the film is something the customer can buy.
 *
 * `negativePrompt` would be the right place for "do not zoom" and this model rejects the
 * field outright — verified, a 400 — so the prohibitions live in the prompt itself.
 */
return new class extends Migration
{
    private const TASK = 'video_tour';

    private const VERSION = 2;

    public function up(): void
    {
        $template = DB::table('prompt_templates')->where('code', self::TASK)->value('id');

        if ($template === null) {
            return;
        }

        $existing = DB::table('prompt_versions')
            ->where('template_id', $template)
            ->where('version', self::VERSION)
            ->value('id');

        $id = $existing === null ? $this->publish((string) $template) : (string) $existing;

        DB::table('ai_task_routes')
            ->where('task', self::TASK)
            ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $template = DB::table('prompt_templates')->where('code', self::TASK)->value('id');

        if ($template === null) {
            return;
        }

        $previous = DB::table('prompt_versions')
            ->where('template_id', $template)
            ->where('version', '<', self::VERSION)
            ->orderByDesc('version')
            ->value('id');

        if ($previous === null) {
            return;
        }

        DB::table('ai_task_routes')
            ->where('task', self::TASK)
            ->update(['prompt_version_id' => $previous, 'updated_at' => now()]);
    }

    private function publish(string $templateId): string
    {
        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $templateId,
            'version' => self::VERSION,
            'status' => 'published',
            'published_at' => now(),
            'temperature_bps' => 2_000,
            /*
             * Still empty. The video endpoint takes one prompt string and has nowhere to put
             * a system message, so anything written here would silently never reach the
             * model.
             */
            'system_prompt' => null,
            'user_template' => implode(' ', [
                // The shot is named before anything else, because the model weights the
                // opening of the prompt most heavily and this is the whole point of the film.
                'Smooth steadicam walking shot through this exact interior.',
                '{{ camera_move }}',
                'Handheld-smooth continuous motion, eye level, wide lens, one continuous take,',
                'strong parallax between the foreground furniture and the back wall.',
                'The room, its walls, windows, doors, floor and every piece of furniture stay',
                'exactly as they are, and the camera never leaves the space the still image',
                'shows.',
                // Stated as prohibitions in the prompt because this model refuses a
                // negativePrompt field. "Do not zoom" is the single most important of them.
                'Do not zoom. Do not hold the camera still. Do not change, add or remove any',
                'furniture, and do not open any door.',
                'Natural daylight from the room own windows, consistent shadows, no cuts.',
                'No people, no text, no captions, no measurements, no arrows, no music.',
                'Style reference: {{ style }}. Room: {{ room_type }}.',
            ]),
            'response_schema' => null,
            'change_note' => 'Kamera yakınlaşmak yerine odanın içinde yürüyor; dönüş eklendi.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
