<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every reading and every decision moves to GPT.
 *
 * The product owner tried both and asked for OpenAI across the board: reading the room,
 * taking the furniture out, planning the design, ranking the products, the vectors behind the
 * search, and the picture. The pictures were already there — this moves the rest.
 *
 * Three things are worth writing down rather than discovering later.
 *
 * **The vectors are not portable.** Two embedding models place the same sentence in different
 * spaces, so a catalogue embedded by one and searched by the other returns noise that looks
 * like a result. Every product has to be embedded again, which is why this migration clears
 * the table rather than leaving rows that would quietly poison every match. They are cheap to
 * rebuild — `refconcept:embed-catalogue` — and ruinous to keep.
 *
 * **The width is a fact about the column.** `product_embeddings.embedding` is `vector(768)`
 * and OpenAI's large model answers at 3072 unless asked for fewer. Its 3-series takes a
 * `dimensions` parameter for exactly this, and the adapter now sends it.
 *
 * **The fallbacks go too.** A route whose primary is GPT and whose fallback is Gemini is a
 * route that silently answers from the other provider on a bad afternoon — which is the one
 * thing nobody could debug from the outside. Where a fallback exists it becomes the same
 * family's smaller model, and otherwise it is dropped.
 */
return new class extends Migration
{
    /**
     * The model behind everything that reads or decides.
     *
     * One model rather than a different one per task: every one of these is "look at this and
     * answer in JSON", the prompts already carry what makes them different, and a second
     * model is a second set of behaviour to learn. It can be split per task later from the
     * console without a migration, which is what the routing table is for.
     */
    private const THINKER = 'gpt-6-astra';

    /** The generation before it, for a route that already had somewhere to fall back to. */
    private const FALLBACK = 'gpt-5.5';

    private const EMBEDDER = 'text-embedding-3-large';

    /** Already ours, and now the one that empties a room as well as the one that fills it. */
    private const PAINTER = 'gpt-image-2';

    public function up(): void
    {
        $provider = DB::table('ai_providers')->where('code', 'openai')->first();

        if ($provider === null) {
            return;
        }

        $thinker = $this->model((string) $provider->id, self::THINKER, 'GPT-6 Astra', 'vision', true, true);
        $fallback = $this->model((string) $provider->id, self::FALLBACK, 'GPT-5.5', 'vision', true, true);
        $embedder = $this->model((string) $provider->id, self::EMBEDDER, 'OpenAI metin vektörü', 'embedding', false, false);
        $painter = $this->model((string) $provider->id, self::PAINTER, 'gpt-image-2', 'image', false, true);

        // Everything that reads a picture or decides something.
        DB::table('ai_task_routes')
            ->whereIn('task', [
                'room_analysis', 'design_plan', 'render_check', 'object_extraction',
                'product_tagging', 'product_view_tagging', 'product_match_rerank',
                'product_query_rewrite', 'budget_optimize', 'support_assist',
                'catalog_enrichment',
            ])
            ->update([
                'primary_model_id' => $thinker,
                // Cast, because Postgres will not put a bare string into a uuid column — and
                // only where there was a fallback already: adding one to a route that never
                // had one is a decision about money, not a rename.
                'fallback_model_id' => DB::raw(sprintf("case when fallback_model_id is null then null else '%s'::uuid end", $fallback)),
                'updated_at' => now(),
            ]);

        // Taking the furniture out is an image edit, and we already have the editor.
        DB::table('ai_task_routes')
            ->whereIn('task', ['room_clear', 'image_edit'])
            ->update([
                'primary_model_id' => $painter,
                'fallback_model_id' => null,
                'updated_at' => now(),
            ]);

        DB::table('ai_task_routes')
            ->where('task', 'text_embedding')
            ->update([
                'primary_model_id' => $embedder,
                'fallback_model_id' => null,
                'updated_at' => now(),
            ]);

        /*
         * The old vectors go.
         *
         * Not kept "just in case": a vector from another model is not a worse answer in the
         * same space, it is a point in a different one, and searching across the two returns
         * confident nonsense. An empty table makes the catalogue un-searchable until it is
         * rebuilt, which is loud, and loud is the right failure here.
         */
        DB::table('product_embeddings')->delete();
    }

    public function down(): void
    {
        $google = DB::table('ai_providers')->where('code', 'google')->first();

        if ($google === null) {
            return;
        }

        $pro = DB::table('ai_models')->where('code', 'gemini-2.5-pro')->value('id');
        $image = DB::table('ai_models')->where('code', 'gemini-3-pro-image')->value('id');
        $embed = DB::table('ai_models')->where('code', 'gemini-embedding-001')->value('id');

        if ($pro !== null) {
            DB::table('ai_task_routes')
                ->whereIn('task', [
                    'room_analysis', 'design_plan', 'render_check', 'object_extraction',
                    'product_tagging', 'product_view_tagging', 'product_match_rerank',
                    'product_query_rewrite', 'budget_optimize', 'support_assist',
                    'catalog_enrichment',
                ])
                ->update(['primary_model_id' => $pro, 'updated_at' => now()]);
        }

        if ($image !== null) {
            DB::table('ai_task_routes')
                ->whereIn('task', ['room_clear', 'image_edit'])
                ->update(['primary_model_id' => $image, 'updated_at' => now()]);
        }

        if ($embed !== null) {
            DB::table('ai_task_routes')
                ->where('task', 'text_embedding')
                ->update(['primary_model_id' => $embed, 'updated_at' => now()]);
        }

        DB::table('product_embeddings')->delete();
    }

    /** A model row, made once and found thereafter. */
    private function model(
        string $providerId,
        string $code,
        string $name,
        string $modality,
        bool $structured,
        bool $images,
    ): string {
        $existing = DB::table('ai_models')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('ai_models')->insert([
            'id' => $id,
            'provider_id' => $providerId,
            'code' => $code,
            'name' => $name,
            'modality' => $modality,
            'supports_structured_output' => $structured,
            'supports_image_input' => $images,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
