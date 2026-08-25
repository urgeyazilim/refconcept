<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turning a design into a shopping list.
 *
 * The plan says "a sofa up to 2200mm against the south wall, in oak and cream". These
 * tables are what turns that sentence into products a customer can actually buy, and the
 * order of operations behind them is the important part:
 *
 *  1. **Narrow by what cannot be negotiated.** The right category, in stock, within
 *     budget, small enough to fit. This is SQL, not a model, because "2400mm wide" is a
 *     fact and asking a language model to respect it is asking for disappointment.
 *  2. **Rank what survives by meaning.** `product_embeddings` holds a vector per product
 *     so "warm minimalist oak" finds a product described as "İskandinav meşe" without
 *     either phrase appearing in the other.
 *  3. **Reorder the shortlist with the design in view.** Only the top handful, because a
 *     rerank is a model call and a model call over four hundred candidates is a bill.
 *
 * `design_matches` is the answer, kept rather than recomputed: a customer who comes back
 * next week must see the list they were shown, at the prices they were shown, even if the
 * catalogue has moved on. What they *buy* is priced at checkout — this is a record of a
 * recommendation, not a quote.
 */
return new class extends Migration
{
    /**
     * The vector width.
     *
     * 768 because that is what the embedding models in reach actually produce
     * (`text-embedding-004` and its neighbours). It is a schema-level commitment: changing
     * it means re-embedding the whole catalogue, so `model` is stored alongside every
     * vector and a mismatch is detectable rather than silently meaningless.
     */
    private const EMBEDDING_DIMENSIONS = 768;

    public function up(): void
    {
        /*
         * One vector per product per purpose.
         *
         * Two purposes exist and they are genuinely different questions. A *text* vector
         * describes what the seller wrote; an *image* vector describes what the product
         * looks like. A customer asking for "a sofa like the one in this render" is asking
         * the second, and folding both into one column would make each of them worse.
         *
         * `content_hash` is what makes re-embedding cheap: a product whose description has
         * not changed does not need a second call, and a nightly backfill that re-embedded
         * the whole catalogue would cost real money for no new information.
         */
        Schema::create('product_embeddings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->string('source', 20);
            $table->string('model', 120);
            $table->string('content_hash', 64);

            $table->timestampsTz();

            // One current vector per product per purpose. A second would make "which one
            // did we search against" unanswerable.
            $table->unique(['product_id', 'source']);
            $table->index(['model']);
        });

        DB::statement(sprintf(
            'ALTER TABLE product_embeddings ADD COLUMN embedding vector(%d) NOT NULL',
            self::EMBEDDING_DIMENSIONS,
        ));

        DB::statement(<<<'SQL'
            ALTER TABLE product_embeddings
            ADD CONSTRAINT product_embeddings_source_check
            CHECK (source IN ('text', 'image'))
        SQL);

        /*
         * The index that makes similarity search a search rather than a scan.
         *
         * HNSW rather than IVFFlat: it needs no training pass, so it works on an empty
         * catalogue and stays correct as products are added one at a time — which is
         * exactly how a marketplace grows. Cosine distance, because the embeddings are
         * normalised and the magnitude carries nothing.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX product_embeddings_vector_idx
            ON product_embeddings
            USING hnsw (embedding vector_cosine_ops)
        SQL);

        /*
         * What the engine found inside a render.
         *
         * Separate from the plan on purpose. The plan is what we *asked* for; this is what
         * the picture actually contains, and the two differ — a model told to place a sofa
         * and a coffee table will sometimes draw a rug as well. A customer pointing at
         * something in the image and asking "what is that" is asking about this table.
         */
        Schema::create('design_extracted_objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            $table->uuid('ai_job_id')->nullable();
            $table->foreign('ai_job_id')->references('id')->on('ai_jobs')->nullOnDelete();

            $table->string('label', 80);
            $table->string('normalised_label', 80)->nullable();

            /*
             * The box, in thousandths of the image's width and height rather than pixels.
             * A render regenerated at a different size would make pixel coordinates lie,
             * and a fraction survives any resize. Integers, because a coordinate that ends
             * up in a CSS calculation should not be a float with a rounding history.
             */
            $table->unsignedSmallInteger('x1_permille')->nullable();
            $table->unsignedSmallInteger('y1_permille')->nullable();
            $table->unsignedSmallInteger('x2_permille')->nullable();
            $table->unsignedSmallInteger('y2_permille')->nullable();

            $table->unsignedInteger('confidence_bps')->nullable();
            $table->integer('position')->default(0);

            $table->timestampsTz();

            $table->index(['design_version_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_extracted_objects
            ADD CONSTRAINT design_extracted_objects_box_check
            CHECK (
                (x1_permille IS NULL AND y1_permille IS NULL AND x2_permille IS NULL AND y2_permille IS NULL)
                OR (x2_permille > x1_permille AND y2_permille > y1_permille
                    AND x2_permille <= 1000 AND y2_permille <= 1000)
            )
        SQL);

        /*
         * The shopping list: one row per product suggested for one place in the plan.
         *
         * The price is a **snapshot**, and that word is doing work. A customer who opens
         * their design next week must see the list they were shown rather than a page that
         * silently repriced itself — and when the two differ, the difference is the thing
         * worth telling them about. What they pay is decided at checkout; this is a record
         * of a recommendation, not an offer.
         *
         * `rank` is the position within one placement. `score_bps` is why it got there,
         * kept so that "why did it suggest this" has an answer that is not a shrug.
         */
        Schema::create('design_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            // Which line of the plan this is for. An index rather than a foreign key
            // because a placement is an element of a JSON array, not a row.
            $table->unsignedSmallInteger('placement_index');
            $table->string('placement_category', 120);

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            $table->unsignedSmallInteger('rank');

            /*
             * The score, in basis points, and the parts it was made of. Kept apart because
             * they answer different questions: the total decides the order, and the parts
             * are what somebody reads when the order looks wrong.
             */
            $table->unsignedInteger('score_bps');
            $table->unsignedInteger('similarity_bps')->nullable();
            $table->unsignedInteger('rerank_bps')->nullable();

            $table->string('reason', 300)->nullable();

            $table->bigInteger('price_minor');
            $table->string('currency', 3)->default('TRY');

            $table->string('status', 20)->default('suggested');

            $table->timestampsTz();

            // One suggestion of a given SKU per placement. The same sofa proposed twice for
            // the same spot is not a richer list, it is a bug wearing a rank.
            $table->unique(['design_version_id', 'placement_index', 'sku_id']);
            $table->index(['design_version_id', 'placement_index', 'rank']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_matches
            ADD CONSTRAINT design_matches_status_check
            CHECK (status IN ('suggested', 'accepted', 'rejected', 'replaced'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE design_matches
            ADD CONSTRAINT design_matches_score_check
            CHECK (
                score_bps <= 10000
                AND (similarity_bps IS NULL OR similarity_bps <= 10000)
                AND (rerank_bps IS NULL OR rerank_bps <= 10000)
                AND price_minor >= 0
            )
        SQL);

        /*
         * What the customer thought of a suggestion.
         *
         * The only honest source of truth about whether matching works. Every other signal
         * — similarity scores, rerank confidence — is the system marking its own homework;
         * this is somebody looking at a sofa and saying "not that one".
         *
         * Append-only, and a reason is optional: a customer who cannot be bothered to say
         * why is still telling us something, and demanding a reason is how a feedback
         * button stops being pressed.
         */
        Schema::create('design_match_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('match_id');
            $table->foreign('match_id')->references('id')->on('design_matches')->cascadeOnDelete();

            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('verdict', 20);
            $table->string('reason_code', 40)->nullable();
            $table->string('note', 300)->nullable();

            $table->timestampTz('created_at');

            $table->index(['match_id', 'created_at']);
            $table->index(['verdict', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_match_feedback
            ADD CONSTRAINT design_match_feedback_verdict_check
            CHECK (verdict IN ('good', 'bad', 'wrong_category', 'too_expensive', 'wrong_style', 'wrong_size'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('design_match_feedback');
        Schema::dropIfExists('design_matches');
        Schema::dropIfExists('design_extracted_objects');

        DB::statement('DROP INDEX IF EXISTS product_embeddings_vector_idx');

        Schema::dropIfExists('product_embeddings');
    }
};
