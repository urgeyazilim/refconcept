<?php

declare(strict_types=1);

namespace App\Domains\Imports\Services;

/**
 * Guesses which spreadsheet column is which field.
 *
 * A seller's file says "Ürün Adı" or "urun_adi" or "Product Name", and asking them to
 * rename forty columns before their first upload is how an import feature goes unused.
 * The guess is a *starting point*, never the decision: the seller confirms or corrects
 * the mapping on screen before anything is validated, and their choice is stored on
 * the batch.
 *
 * Matching is deliberately conservative — exact aliases first, then a normalised
 * comparison that ignores spacing and punctuation. Fuzzy matching is not used: a
 * column silently mapped to the wrong field writes wrong data into a live catalogue,
 * which is far worse than an unmapped column the seller has to point at.
 */
final class ImportColumnMapper
{
    /**
     * Fields a product import understands, and the headers that mean them.
     *
     * `sku` is the identity: a row whose SKU already exists updates that offer, and a
     * row with a new one creates it. Without it there is no way to tell an update from
     * a duplicate, which is why it is the one column that cannot be left unmapped.
     *
     * @var array<string, array{label: string, required: bool, aliases: array<int, string>}>
     */
    public const FIELDS = [
        'sku' => [
            'label' => 'SKU kodu',
            'required' => true,
            'aliases' => ['sku', 'sku kodu', 'stok kodu', 'stok kodu', 'urun kodu', 'ürün kodu', 'kod', 'code', 'product code', 'barkod kodu'],
        ],
        'name' => [
            'label' => 'Ürün adı',
            'required' => true,
            'aliases' => ['ad', 'adi', 'adı', 'isim', 'urun adi', 'ürün adı', 'urun ismi', 'baslik', 'başlık', 'name', 'product name', 'title'],
        ],
        'description' => [
            'label' => 'Açıklama',
            'required' => false,
            'aliases' => ['aciklama', 'açıklama', 'urun aciklamasi', 'ürün açıklaması', 'detay', 'description', 'details'],
        ],
        'category' => [
            'label' => 'Kategori',
            'required' => true,
            'aliases' => ['kategori', 'kategori kodu', 'kategori slug', 'category', 'category slug'],
        ],
        'brand' => [
            'label' => 'Marka',
            'required' => false,
            'aliases' => ['marka', 'brand'],
        ],
        'barcode' => [
            'label' => 'Barkod',
            'required' => false,
            'aliases' => ['barkod', 'barcode', 'ean', 'gtin'],
        ],
        'variant_label' => [
            'label' => 'Seçenek adı',
            'required' => false,
            'aliases' => ['secenek', 'seçenek', 'varyant', 'variant', 'option'],
        ],
        'list_price' => [
            'label' => 'Liste fiyatı',
            'required' => true,
            'aliases' => ['fiyat', 'liste fiyati', 'liste fiyatı', 'satis fiyati', 'satış fiyatı', 'price', 'list price'],
        ],
        'sale_price' => [
            'label' => 'İndirimli fiyat',
            'required' => false,
            'aliases' => ['indirimli fiyat', 'kampanya fiyati', 'kampanya fiyatı', 'sale price', 'discount price'],
        ],
        'tax_rate' => [
            'label' => 'KDV oranı (%)',
            'required' => false,
            'aliases' => ['kdv', 'kdv orani', 'kdv oranı', 'vergi', 'tax', 'vat', 'tax rate'],
        ],
        'stock' => [
            'label' => 'Stok adedi',
            'required' => false,
            'aliases' => ['stok', 'stok adedi', 'adet', 'miktar', 'stock', 'quantity', 'qty'],
        ],
        'width_mm' => [
            'label' => 'Genişlik (mm)',
            'required' => false,
            'aliases' => ['genislik', 'genişlik', 'en', 'width'],
        ],
        'depth_mm' => [
            'label' => 'Derinlik (mm)',
            'required' => false,
            'aliases' => ['derinlik', 'boy', 'depth'],
        ],
        'height_mm' => [
            'label' => 'Yükseklik (mm)',
            'required' => false,
            'aliases' => ['yukseklik', 'yükseklik', 'height'],
        ],
        'weight_g' => [
            'label' => 'Ağırlık (g)',
            'required' => false,
            'aliases' => ['agirlik', 'ağırlık', 'weight', 'kilo'],
        ],
        'color' => [
            'label' => 'Renk',
            'required' => false,
            'aliases' => ['renk', 'color', 'colour'],
        ],
        'material' => [
            'label' => 'Malzeme',
            'required' => false,
            'aliases' => ['malzeme', 'kumas', 'kumaş', 'material', 'fabric'],
        ],
    ];

    /**
     * A first guess at header → field.
     *
     * Only unambiguous matches are proposed. If two columns both look like the price,
     * neither is mapped: a seller correcting one wrong guess is a nuisance, a seller
     * not noticing one is a catalogue full of wrong prices.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string> header => field
     */
    public function suggest(array $headers): array
    {
        $mapping = [];
        $claimed = [];

        foreach ($headers as $header) {
            $normalised = $this->normalise($header);

            if ($normalised === '') {
                continue;
            }

            $field = $this->fieldFor($normalised);

            if ($field === null) {
                continue;
            }

            if (isset($claimed[$field])) {
                // Two columns claim the same field. Drop both rather than pick one:
                // the seller has to say which, and the screen will show it unmapped.
                unset($mapping[$claimed[$field]]);

                continue;
            }

            $mapping[$header] = $field;
            $claimed[$field] = $header;
        }

        return $mapping;
    }

    /**
     * Fields the seller still has to point at before anything can be validated.
     *
     * @param  array<string, string>  $mapping
     * @return array<int, string> field labels
     */
    public function missingRequired(array $mapping): array
    {
        $mapped = array_values($mapping);
        $missing = [];

        foreach (self::FIELDS as $field => $definition) {
            if ($definition['required'] && ! in_array($field, $mapped, true)) {
                $missing[] = $definition['label'];
            }
        }

        return $missing;
    }

    /**
     * The catalogue of fields, for the mapping screen.
     *
     * @return array<int, array{field: string, label: string, required: bool}>
     */
    public function fieldCatalogue(): array
    {
        $catalogue = [];

        foreach (self::FIELDS as $field => $definition) {
            $catalogue[] = [
                'field' => $field,
                'label' => $definition['label'],
                'required' => $definition['required'],
            ];
        }

        return $catalogue;
    }

    private function fieldFor(string $normalisedHeader): ?string
    {
        foreach (self::FIELDS as $field => $definition) {
            foreach ($definition['aliases'] as $alias) {
                if ($this->normalise($alias) === $normalisedHeader) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Reduces a header to something comparable.
     *
     * Turkish characters are folded to ASCII so "Genişlik" and "genislik" match — a
     * seller who typed their headers without the Turkish keyboard should not have to
     * remap every column.
     */
    private function normalise(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');

        $header = strtr($header, [
            'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u', 'ş' => 's', 'Ş' => 's',
            'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        ]);

        // Punctuation and spacing carry no meaning in a column name.
        $header = preg_replace('/[^a-z0-9]+/u', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }
}
