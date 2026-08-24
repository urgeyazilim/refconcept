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
