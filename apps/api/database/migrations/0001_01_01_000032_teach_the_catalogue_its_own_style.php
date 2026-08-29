<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives products a style vocabulary the design engine can actually search on.
 *
 * The catalogue has never described itself. `products.style_id` is nullable, the seller form
 * treats it as optional, and every product carrying one does so only because a seeder set it.
 * A customer choosing "Lüks" would today be offered nothing at all, and "Klasik" a single
 * bed — not because the shop is empty but because nobody ever asked a seller what they were
 * selling. A style picker over a catalogue that cannot answer is a picker that returns
 * nothing, so this comes before the picker.
 *
 * Three tables, and each answers a question the single foreign key could not.
 *
 * **A product has more than one style.** A plain oak sideboard is credibly scandinavian and
 * minimal, and forcing a seller to pick one loses half the truth. `product_styles` carries a
 * strength so "primarily minimal, also scandinavian" is expressible, and matching can weigh
 * the primary heavier than the secondary.
 *
 * **Styles have neighbours.** With twelve products in the catalogue, filtering hard on the
 * chosen style empties the room — which is the failure this whole effort exists to stop.
 * `style_adjacency` lets a request for "lüks" rank classic pieces just below the luxury ones
 * rather than discarding them, so a thin catalogue reads as thin rather than as broken.
 * Style becomes a ranking signal, not a WHERE clause.
 *
 * **A palette is a set of colours, not a colour.** The nineteen colour values are already a
 * controlled list; what was missing is the grouping a customer actually chooses between —
 * nobody picks "taupe", they pick "sıcak nötr" and mean six colours at once.
 *
 * Schema only. The adjacency map and the palettes are reference data and belong to the
 * catalogue seeder, beside the styles they point at: seeded here they would find an empty
 * `styles` table on any database built from scratch — CI, a new environment, the test
 * suite — and silently seed nothing, leaving the affinity map empty everywhere except the
 * one machine where the styles happened to predate the migration.
 *
 * `products.style_id` stays for now and is kept in step with the primary row. Dropping it is
 * a separate change once the readers — public catalogue, search, the seller form — have moved
 * over, and two sources of truth for one deploy is safer than a column disappearing under
 * running code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_styles', function (Blueprint $table): void {
            $table->uuid('product_id');
            $table->uuid('style_id');

            /*
             * How strongly the product belongs to this style, in basis points. The seller
             * picks a primary and optionally secondaries; the primary is 10000 and each
             * secondary 5000, rather than asking a person to reason about a percentage.
             */
            $table->unsignedSmallInteger('strength_bps')->default(10_000);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['product_id', 'style_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('style_id')->references('id')->on('styles')->cascadeOnDelete();

            // Matching reads this the other way round — "which products are luxury" — and
            // that query is on the hot path of every design.
            $table->index(['style_id', 'strength_bps']);
        });

        // One primary per product, enforced where it cannot drift: a product with two
        // primaries has no answer to "what is this, mainly?".
        DB::statement(
            'CREATE UNIQUE INDEX product_styles_one_primary_idx
             ON product_styles (product_id) WHERE is_primary'
        );

        Schema::create('style_adjacency', function (Blueprint $table): void {
            $table->uuid('style_id');
            $table->uuid('neighbour_style_id');

            // 10000 would be "the same style"; nothing here is, so the range in practice is
            // 5000-8000 and a missing row means unrelated.
            $table->unsignedSmallInteger('affinity_bps');
            $table->timestamps();

            $table->primary(['style_id', 'neighbour_style_id']);
            $table->foreign('style_id')->references('id')->on('styles')->cascadeOnDelete();
            $table->foreign('neighbour_style_id')->references('id')->on('styles')->cascadeOnDelete();
        });

        Schema::create('palettes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('palette_colors', function (Blueprint $table): void {
            $table->uuid('palette_id');
            // The colour attribute's own value code — `beige`, `walnut` — rather than a new
            // vocabulary. Products are already tagged with these and a second list of colour
            // names is a second list to keep in step.
            $table->string('color_value');
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['palette_id', 'color_value']);
            $table->foreign('palette_id')->references('id')->on('palettes')->cascadeOnDelete();
            $table->index('color_value');
        });

        $this->backfillProductStyles();
    }

    public function down(): void
    {
        Schema::dropIfExists('palette_colors');
        Schema::dropIfExists('palettes');
        Schema::dropIfExists('style_adjacency');
        Schema::dropIfExists('product_styles');
    }

    /**
     * Moves whatever style the catalogue already has into the new table.
     *
     * Only the twelve seeded products carry one, but they are the twelve the design engine
     * matches against today, and losing them would make the change look like a regression
     * when it is a migration.
     */
    private function backfillProductStyles(): void
    {
        $rows = DB::table('products')
            ->whereNotNull('style_id')
            ->get(['id', 'style_id']);

        if ($rows->isEmpty()) {
            return;
        }

        DB::table('product_styles')->insert(
            $rows->map(fn (object $product): array => [
                'product_id' => $product->id,
                'style_id' => $product->style_id,
                'strength_bps' => 10_000,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all()
        );
    }
};
