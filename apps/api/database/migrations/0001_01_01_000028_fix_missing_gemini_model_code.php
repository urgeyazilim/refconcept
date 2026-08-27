<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoints the text model at one that exists.
 *
 * `gemini-3-pro` was seeded and Google does not serve it. Every job that used it — room
 * analysis, design planning, product tagging, support answers — failed with the provider's
 * `invalid_request`, which the platform renders to a customer as *"Oda fotoğrafı okunamadı:
 * Geçersiz istek. Daha aydınlık bir fotoğrafla tekrar deneyin."*
 *
 * That message is the worst part of the bug. It is a good message for the failure it was
 * written for and a lie about this one: the photograph was fine, and the customer retook it
 * in better light and failed again.
 *
 * The code is renamed rather than a new model inserted, so every route, rate card and usage
 * row that already points at this model follows it. Inserting a second model would leave the
 * routes aimed at the broken one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $google = DB::table('ai_providers')->where('code', 'google')->value('id');

        if ($google === null) {
            return;
        }

        // Only if the replacement is not already there — re-running must not collapse two
        // models into one and orphan a rate card.
        $taken = DB::table('ai_models')
            ->where('provider_id', $google)
            ->where('code', 'gemini-2.5-pro')
            ->exists();

        if ($taken) {
            return;
        }

        DB::table('ai_models')
            ->where('provider_id', $google)
            ->where('code', 'gemini-3-pro')
            ->update([
                'code' => 'gemini-2.5-pro',
                'name' => 'Gemini 2.5 Pro',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally empty. Putting a model code back that the provider does not serve
        // would restore the outage, and no rollback is worth that.
    }
};
