<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\AttributeValue;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Color;
use App\Domains\Catalog\Models\Material;
use App\Domains\Catalog\Models\Style;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform's descriptive vocabulary.
 *
 * Reference data, not demo data: it runs in production too. Idempotent by natural
 * key, so a deploy that re-runs it neither duplicates a category nor orphans the
 * products already pointing at one.
 *
 * The taxonomy is deliberately real rather than a placeholder. Everything downstream
 * depends on it — category pages, filters, the room-aware matching in Phase 9 — and
 * "Category A / Category B" would make all of that untestable.
 */
final class CatalogTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedStyles();
        /*
         * Adjacency and palettes are seeded here, beside the styles they point at, rather
         * than in the migration that created their tables. The migration ran before the
         * styles existed on any database built from scratch — CI, a new environment, the
         * test suite — so it seeded nothing at all, and the affinity map came back empty
         * everywhere except the one machine where the styles happened to predate it.
         * Reference data belongs with the seeder that owns it, which is also the one thing
         * re-run on every deploy.
         */
        $this->seedStyleAdjacency();
        $this->seedPalettes();
        $this->seedBrands();
        $this->seedColors();
        $this->seedMaterials();
        $attributes = $this->seedAttributes();
        $this->seedCategories($attributes);

        $this->command?->info(sprintf(
            'Catalog taxonomy: %d categories, %d attributes, %d colours, %d materials, %d styles.',
            Category::query()->count(),
            Attribute::query()->count(),
            Color::query()->count(),
            Material::query()->count(),
            Style::query()->count(),
        ));
    }

    private function seedStyles(): void
    {
        $styles = [
            ['modern', 'Modern', 'Sade çizgiler, işlevsel formlar, nötr paletler.'],
            ['minimal', 'Minimal', 'Az sayıda parça, geniş boşluk, sakin yüzeyler.'],
            ['scandinavian', 'İskandinav', 'Açık ahşap, doğal ışık, yumuşak tekstil.'],
            ['warm-contemporary', 'Sıcak Çağdaş', 'Bej ve taupe tonları, dokulu kumaşlar.'],
            ['luxury', 'Lüks', 'Mermer, pirinç detay, ağır kumaş, katmanlı aydınlatma.'],
            ['industrial', 'Endüstriyel', 'Ham metal, beton yüzey, açık tesisat.'],
            ['classic', 'Klasik', 'Simetri, oyma detay, koyu ahşap.'],
            ['bohemian', 'Bohem', 'Karışık desen, el dokuması, bitki yoğunluğu.'],
        ];

        foreach ($styles as $index => [$code, $name, $description]) {
            Style::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'position' => $index],
            );
        }
    }

    /**
     * How close each style sits to each other, in basis points.
     *
     * Matching needs this because filtering hard on the chosen style empties the room. With
     * a dozen products in the catalogue a customer choosing "Lüks" and getting a strict
     * `WHERE style = luxury` sees nothing — not because the shop has nothing for them but
     * because nothing is tagged that exact word — and a customer reads nothing as a broken
     * page. So style ranks rather than filters: luxury first, classic just behind it,
     * industrial not at all.
     *
     * Symmetric and deliberately sparse. Only pairs a person would accept as neighbours are
     * listed; anything absent is simply unrelated. The numbers are judgement rather than
     * measurement, which is why they live in a table — tuning them from what customers
     * actually accept should be an UPDATE, not a deploy.
     */
    private function seedStyleAdjacency(): void
    {
        $pairs = [
            ['modern', 'minimal', 8_000],
            ['modern', 'scandinavian', 7_000],
            ['modern', 'warm-contemporary', 7_500],
            ['modern', 'industrial', 6_000],
            ['minimal', 'scandinavian', 8_000],
            ['minimal', 'industrial', 5_500],
            ['scandinavian', 'warm-contemporary', 7_000],
            ['warm-contemporary', 'bohemian', 6_000],
            ['luxury', 'classic', 8_000],
            ['luxury', 'warm-contemporary', 5_500],
            ['classic', 'warm-contemporary', 5_000],
            ['bohemian', 'scandinavian', 5_000],
            ['industrial', 'bohemian', 5_000],
        ];

        $styles = Style::query()->pluck('id', 'code');

        foreach ($pairs as [$left, $right, $affinity]) {
            if (! isset($styles[$left], $styles[$right])) {
                continue;
            }

            // Written both ways round, so a lookup never has to try the pair reversed.
            foreach ([[$left, $right], [$right, $left]] as [$from, $to]) {
                DB::table('style_adjacency')->updateOrInsert(
                    ['style_id' => $styles[$from], 'neighbour_style_id' => $styles[$to]],
                    ['affinity_bps' => $affinity, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    /**
     * The colour sets a customer chooses between.
     *
     * Nobody picks "taupe". They pick "sıcak nötr" and mean six colours at once, and they
     * pick it by looking at it — so a palette is a named set of the colours the catalogue
     * already uses rather than a second colour vocabulary to keep in step.
     *
     * A colour belongs to more than one palette on purpose: cream is at home in both "açık
     * ve ferah" and "sıcak nötr", and pretending otherwise would make one of them wrong.
     */
    private function seedPalettes(): void
    {
        $palettes = [
            ['warm-neutral', 'Sıcak Nötr', 'Bej, krem ve kum tonları; yumuşak ve davetkâr.', ['beige', 'cream', 'sand', 'taupe', 'oak', 'brass']],
            ['cool-grey', 'Soğuk Gri', 'Gri, taş ve antrasit; sakin ve dingin.', ['grey', 'stone', 'charcoal', 'white', 'chrome', 'ash']],
            ['earthy', 'Toprak Tonları', 'Terakota, zeytin ve ceviz; doğal ve sıcak.', ['terracotta', 'olive', 'walnut', 'sand', 'mustard', 'forest']],
            ['dark-dramatic', 'Koyu ve Dramatik', 'Siyah, lacivert ve koyu yeşil; iddialı.', ['black', 'charcoal', 'navy', 'forest', 'brass', 'walnut']],
            ['light-airy', 'Açık ve Ferah', 'Beyaz, krem ve açık ahşap; aydınlık.', ['white', 'cream', 'beige', 'ash', 'oak', 'stone']],
        ];

        foreach ($palettes as $position => [$code, $name, $description, $colors]) {
            $id = DB::table('palettes')->where('code', $code)->value('id') ?? (string) Str::uuid7();

            DB::table('palettes')->updateOrInsert(
                ['code' => $code],
                [
                    'id' => $id,
                    'name' => $name,
                    'description' => $description,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            // Replaced wholesale rather than merged: a colour removed from a palette here
            // should leave it, and updateOrInsert alone would keep the old row for ever.
            DB::table('palette_colors')->where('palette_id', $id)->delete();

            DB::table('palette_colors')->insert(
                collect($colors)
                    ->values()
                    ->map(fn (string $color, int $index): array => [
                        'palette_id' => $id,
                        'color_value' => $color,
                        'position' => $index,
                    ])
                    ->all()
            );
        }
    }

    /**
     * House brands the platform curates.
     *
     * Brands are shared vocabulary, not seller-owned rows: two sellers listing the
     * same manufacturer must point at one brand, or the storefront's brand filter
     * silently splits the catalogue in two.
     */
    private function seedBrands(): void
    {
        $brands = [
            ['Arden', 'arden', 'Bouclé ve keten ağırlıklı oturma grupları.'],
            ['Nordhem', 'nordhem', 'İskandinav çizgide masif ahşap mobilya.'],
            ['Vela Studio', 'vela-studio', 'El yapımı aydınlatma ve aksesuar.'],
            ['Meridyen', 'meridyen', 'Klasik formların çağdaş yorumu.'],
            ['Kavim', 'kavim', 'Anadolu dokuma geleneğinden halı ve tekstil.'],
            ['Loft & Co.', 'loft-co', 'Endüstriyel metal ve geri dönüştürülmüş ahşap.'],
        ];

        foreach ($brands as [$name, $slug, $description]) {
            Brand::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'is_active' => true],
            );
        }
    }

    private function seedColors(): void
    {
        // Grouped into families so a design asking for "a warm neutral" can widen its
        // search without listing every shade.
        $colors = [
            ['beige', 'Bej', '#D9CFC1', 'neutral'],
            ['cream', 'Krem', '#F2EBE0', 'neutral'],
            ['sand', 'Kum', '#DCCE86', 'neutral'],
            ['taupe', 'Taupe', '#A89E8E', 'neutral'],
            ['stone', 'Taş', '#C7C2B8', 'neutral'],
            ['white', 'Beyaz', '#FFFFFF', 'neutral'],
            ['charcoal', 'Antrasit', '#333333', 'dark'],
            ['black', 'Siyah', '#111111', 'dark'],
            ['walnut', 'Ceviz', '#5C4033', 'wood'],
            ['oak', 'Meşe', '#C4A484', 'wood'],
            ['ash', 'Dişbudak', '#DED0BC', 'wood'],
            ['olive', 'Zeytin Yeşili', '#6E8C4B', 'green'],
            ['forest', 'Koyu Yeşil', '#33503B', 'green'],
            ['terracotta', 'Terakota', '#B4573F', 'warm'],
            ['mustard', 'Hardal', '#C08A3E', 'warm'],
            ['navy', 'Lacivert', '#2A3A55', 'cool'],
            ['grey', 'Gri', '#8A8175', 'neutral'],
            ['brass', 'Pirinç', '#C9A86A', 'metal'],
            ['chrome', 'Krom', '#C8CCCE', 'metal'],
        ];

        foreach ($colors as $index => [$code, $name, $hex, $family]) {
            Color::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'hex' => $hex, 'family' => $family, 'position' => $index],
            );
        }
    }

    private function seedMaterials(): void
    {
        $materials = [
            ['solid-oak', 'Masif Meşe', 'wood'],
            ['solid-walnut', 'Masif Ceviz', 'wood'],
            ['mdf-lacquer', 'Lake MDF', 'wood'],
            ['veneer', 'Kaplama', 'wood'],
            ['boucle', 'Bouclé', 'fabric'],
            ['linen', 'Keten', 'fabric'],
            ['cotton', 'Pamuk', 'fabric'],
            ['velvet', 'Kadife', 'fabric'],
            ['wool', 'Yün', 'fabric'],
            ['leather', 'Deri', 'leather'],
            ['faux-leather', 'Suni Deri', 'leather'],
            ['marble', 'Mermer', 'stone'],
            ['travertine', 'Traverten', 'stone'],
            ['ceramic', 'Seramik', 'stone'],
            ['glass', 'Cam', 'glass'],
            ['steel', 'Çelik', 'metal'],
            ['brass', 'Pirinç', 'metal'],
            ['rattan', 'Rattan', 'natural'],
        ];

        foreach ($materials as $index => [$code, $name, $family]) {
            Material::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'family' => $family, 'position' => $index],
            );
        }
    }

    /**
     * @return array<string, Attribute>
     */
    private function seedAttributes(): array
    {
        $definitions = [
            ['color', 'Renk', 'select', null, true, true],
            ['material', 'Malzeme', 'select', null, true, true],
            ['size', 'Ölçü', 'select', null, true, true],
            ['seat_count', 'Oturma Kapasitesi', 'integer', 'kişi', false, true],
            ['assembly', 'Montaj', 'boolean', null, false, true],
            ['warranty_months', 'Garanti', 'integer', 'ay', false, true],
            ['origin', 'Menşei', 'string', null, false, true],
            ['care', 'Bakım Talimatı', 'string', null, false, false],
        ];

        $attributes = [];

        foreach ($definitions as $index => [$code, $name, $type, $unit, $variantDefining, $filterable]) {
            $attributes[$code] = Attribute::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'data_type' => $type,
                    'unit' => $unit,
                    'is_variant_defining' => $variantDefining,
                    'is_filterable' => $filterable,
                    'position' => $index,
                ],
            );
        }

        $this->seedAttributeValues($attributes['color'], array_map(
            static fn (Color $color): array => [$color->code, $color->name],
            Color::query()->orderBy('position')->get()->all(),
        ));

        $this->seedAttributeValues($attributes['material'], array_map(
            static fn (Material $material): array => [$material->code, $material->name],
            Material::query()->orderBy('position')->get()->all(),
        ));

        $this->seedAttributeValues($attributes['size'], [
            ['2-seat', '2 Kişilik'],
            ['3-seat', '3 Kişilik'],
            ['4-seat', '4 Kişilik'],
            ['corner', 'Köşe'],
            ['single', 'Tekli'],
            ['80x80', '80×80 cm'],
            ['120x60', '120×60 cm'],
            ['160x90', '160×90 cm'],
            ['200x300', '200×300 cm'],
        ]);

        return $attributes;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $values
     */
    private function seedAttributeValues(Attribute $attribute, array $values): void
    {
        foreach ($values as $index => [$value, $label]) {
            AttributeValue::query()->updateOrCreate(
                ['attribute_id' => $attribute->getKey(), 'value' => $value],
                ['label' => $label, 'position' => $index],
            );
        }
    }

    /**
     * @param  array<string, Attribute>  $attributes
     */
    private function seedCategories(array $attributes): void
    {
        /*
         * Room type is carried on the category so an AI design for a bedroom never
         * proposes kitchen cabinetry, and so a room-scoped catalogue page is one query.
         */
        $tree = [
            ['mobilya', 'Mobilya', null, [
                ['oturma-grubu', 'Oturma Grubu', 'living_room', [
                    ['kanepe', 'Kanepe', 'living_room'],
                    ['koltuk', 'Koltuk', 'living_room'],
                    ['puf', 'Puf', 'living_room'],
                ]],
                ['masa-sandalye', 'Masa & Sandalye', 'dining_room', [
                    ['yemek-masasi', 'Yemek Masası', 'dining_room'],
                    ['sandalye', 'Sandalye', 'dining_room'],
                    ['sehpa', 'Sehpa', 'living_room'],
                ]],
                ['yatak-odasi-mobilya', 'Yatak Odası', 'bedroom', [
                    ['yatak', 'Yatak', 'bedroom'],
                    ['komodin', 'Komodin', 'bedroom'],
                    ['gardirop', 'Gardırop', 'bedroom'],
                ]],
                ['depolama', 'Depolama', null, [
                    ['kitaplik', 'Kitaplık', 'living_room'],
                    ['tv-unitesi', 'TV Ünitesi', 'living_room'],
                    ['konsol', 'Konsol', 'living_room'],
                ]],
            ]],
            ['aydinlatma', 'Aydınlatma', null, [
                ['tavan-aydinlatma', 'Tavan Aydınlatma', null],
                ['lambader', 'Lambader', 'living_room'],
                ['masa-lambasi', 'Masa Lambası', null],
                ['duvar-aydinlatma', 'Duvar Aydınlatma', null],
            ]],
            ['tekstil', 'Tekstil', null, [
                ['hali', 'Halı', null],
                ['perde', 'Perde', null],
                ['kirlent', 'Kırlent', 'living_room'],
                ['nevresim', 'Nevresim', 'bedroom'],
            ]],
            ['dekorasyon', 'Dekorasyon', null, [
                ['tablo', 'Tablo', null],
                ['ayna', 'Ayna', null],
                ['vazo', 'Vazo', null],
                ['bitki', 'Bitki & Saksı', null],
            ]],
            ['mutfak', 'Mutfak', 'kitchen', [
                ['mutfak-dolabi', 'Mutfak Dolabı', 'kitchen'],
                ['tezgah', 'Tezgah', 'kitchen'],
                ['bar-taburesi', 'Bar Taburesi', 'kitchen'],
            ]],
            ['banyo', 'Banyo', 'bathroom', [
                ['banyo-dolabi', 'Banyo Dolabı', 'bathroom'],
                ['lavabo', 'Lavabo', 'bathroom'],
                ['banyo-aksesuar', 'Banyo Aksesuarı', 'bathroom'],
            ]],
        ];

        foreach ($tree as $position => $node) {
            $this->seedCategoryNode($node, null, $position, $attributes);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string|null, 3?: array<int, mixed>}  $node
     * @param  array<string, Attribute>  $attributes
     */
    private function seedCategoryNode(array $node, ?Category $parent, int $position, array $attributes): void
    {
        [$slug, $name, $roomType] = $node;

        $category = Category::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'parent_id' => $parent?->getKey(),
                'name' => $name,
                'room_type' => $roomType,
                'position' => $position,
                'is_active' => true,
            ],
        );

        // Leaf categories get the attributes a seller must actually fill in. Branch
        // nodes carry none, because nothing is listed directly against them.
        if (! isset($node[3])) {
            $category->attributes()->syncWithoutDetaching([
                $attributes['color']->getKey() => ['is_required' => true, 'position' => 0],
                $attributes['material']->getKey() => ['is_required' => true, 'position' => 1],
                $attributes['size']->getKey() => ['is_required' => false, 'position' => 2],
                $attributes['warranty_months']->getKey() => ['is_required' => false, 'position' => 3],
            ]);

            return;
        }

        foreach ($node[3] as $childPosition => $child) {
            $this->seedCategoryNode($child, $category, $childPosition, $attributes);
        }
    }
}
