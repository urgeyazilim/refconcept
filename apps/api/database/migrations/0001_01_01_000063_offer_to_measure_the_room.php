<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A route for measuring a room from its photographs, and the model that does it.
 *
 * VGGT on fal: several unposed pictures in, camera positions and a coloured point cloud out.
 * It answers the question the reading has been guessing at — which wall is the long one — by
 * triangulating instead of reasoning about door heights.
 *
 * **Paused on arrival, and never automatic.** It costs money per room and the first real
 * attempt came back as a cloud with no walls in it, so nothing may spend a customer's money
 * on it until somebody has looked at what it produces and turned it on deliberately. A
 * paused route is refused by the dispatcher, which is the state this should live in until it
 * has earned otherwise.
 */
return new class extends Migration
{
    private const MODEL = 'fal-ai/vggt-1b';

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
                'name' => 'VGGT (oda taraması)',
                'modality' => 'model_3d',
                'supports_image_input' => true,
                'supports_structured_output' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('ai_task_routes')->where('task', 'room_scan')->exists()) {
            return;
        }

        DB::table('ai_task_routes')->insert([
            'id' => (string) Str::uuid7(),
            'task' => 'room_scan',
            'primary_model_id' => $modelId,
            'fallback_model_id' => null,
            // Nobody is charged for it while it is being judged; the platform pays for the
            // few it runs, and the price is settled when somebody decides to keep it.
            'credit_cost' => 0,
            'max_attempts' => 1,
            // A reconstruction is minutes, not seconds, and nobody is watching a spinner.
            'timeout_seconds' => 600,
            'is_paused' => true,
            // The table refuses a pause with no reason on it, which is the right rule: a
            // switch nobody can explain is a switch nobody dares turn back on.
            'pause_reason' => 'Deneme aşamasında. Tek tek denenip kalmasına karar verilene kadar müşteri parası harcamaz.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_task_routes')->where('task', 'room_scan')->delete();
        DB::table('ai_models')->where('code', self::MODEL)->delete();
    }
};
