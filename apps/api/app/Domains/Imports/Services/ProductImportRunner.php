<?php

declare(strict_types=1);

namespace App\Domains\Imports\Services;

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Imports\Enums\ImportStatus;
use App\Domains\Imports\Enums\RowStatus;
use App\Domains\Imports\Models\ImportBatch;
use App\Domains\Imports\Models\ImportRow;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Pricing\Services\PriceBook;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use App\Support\Text\TurkishText;
use App\Support\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a seller's spreadsheet into catalogue rows, in three separate steps.
 *
 *   analyse()   read the header, propose a mapping, store every line verbatim
 *   validate()  check each stored line; write nothing to the catalogue
 *   commit()    apply the lines that passed
 *
 * They are separate because the seller has to see the outcome before it happens. An
 * importer that writes as it validates leaves the catalogue half-changed when line 250
 * turns out to be malformed, and there is no undo for a catalogue: the seller would be
 * left reconciling four hundred products by hand against a file they no longer trust.
 *
 * Validation reads from `import_rows` rather than from the file. The file is parsed
 * exactly once, at analyse time, so re-validating after a mapping change costs nothing
 * and does not depend on the upload still being there.
 *
 * A row's SKU code is its identity. An existing code updates that offer, a new one
 * creates it — which is what makes a weekly price-and-stock file work rather than
 * producing four hundred duplicates every Monday.
 */
final class ProductImportRunner
{
    /** Above this, an import is refused rather than silently truncated. */
    public const MAX_ROWS = 20_000;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportColumnMapper $mapper,
        private readonly PriceBook $prices,
        private readonly InventoryLedger $inventory,
    ) {}

    /**
     * Reads the file once: header out, every line stored, a mapping proposed.
     */
    public function analyse(ImportBatch $batch): ImportBatch
    {
        $batch->forceFill(['status' => ImportStatus::Analysing])->save();

        try {
            $path = $this->materialise($batch);
            $extension = strtolower(pathinfo($batch->original_name, PATHINFO_EXTENSION));

            $headers = $this->reader->headers($path, $extension);
            $stored = 0;

            // Chunked inserts: one INSERT per row would make a 20,000-line file a
            // 20,000-round-trip operation.
            $buffer = [];

            foreach ($this->reader->records($path, $extension) as $record) {
                $stored++;

                if ($stored > self::MAX_ROWS) {
                    throw new RuntimeException(sprintf(
                        'Dosya %s satırdan uzun. Lütfen daha küçük parçalara bölün.',
                        number_format(self::MAX_ROWS, 0, ',', '.'),
                    ));
                }

                $buffer[] = [
                    'id' => Str::uuid7()->toString(),
                    'batch_id' => $batch->getKey(),
                    'line_number' => $record['line'],
                    'raw' => json_encode($record['values'], JSON_UNESCAPED_UNICODE),
                    'status' => RowStatus::Pending->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($buffer) >= 500) {
                    ImportRow::query()->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                ImportRow::query()->insert($buffer);
            }

            @unlink($path);

            $batch->forceFill([
                'status' => ImportStatus::Mapped,
                'detected_headers' => $headers,
                'mapping' => $batch->mapping ?? $this->mapper->suggest($headers),
                'total_rows' => $stored,
                'analysed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            return $this->fail($batch, $e->getMessage());
        }

        return $batch;
    }

    /**
     * The dry run. Checks every stored row and writes nothing to the catalogue.
     */
    public function validate(ImportBatch $batch): ImportBatch
    {
        $mapping = $batch->mapping ?? [];
        $missing = $this->mapper->missingRequired($mapping);

        if ($missing !== []) {
            return $this->fail($batch, 'Zorunlu sütunlar eşleştirilmedi: '.implode(', ', $missing));
        }

        $batch->forceFill(['status' => ImportStatus::Validating, 'valid_rows' => 0, 'error_rows' => 0])->save();

        try {
            $context = $this->buildContext($batch);
            $valid = 0;
            $invalid = 0;

            // Codes seen earlier in *this file*, so a duplicate inside one upload is
            // caught. Two rows for the same SKU would otherwise both look fine and the
            // second would silently overwrite the first.
            $seenSkus = [];

            $batch->rows()->chunkById(500, function ($rows) use ($mapping, $context, &$valid, &$invalid, &$seenSkus): void {
                foreach ($rows as $row) {
                    $result = $this->evaluate($row, $mapping, $context, $seenSkus);

                    if ($result['errors'] === []) {
                        $seenSkus[$result['normalised']['sku']] = $row->line_number;
                        $valid++;
                    } else {
                        $invalid++;
                    }

                    $row->forceFill([
                        'normalised' => $result['normalised'],
                        'errors' => $result['errors'] === [] ? null : $result['errors'],
                        'status' => $result['errors'] === [] ? RowStatus::Valid : RowStatus::Invalid,
                        'action' => $result['action'],
                        'product_id' => $result['product_id'],
                        'sku_id' => $result['sku_id'],
                    ])->save();
                }
            });

            $batch->forceFill([
                'status' => ImportStatus::Validated,
                'valid_rows' => $valid,
                'error_rows' => $invalid,
                'dry_run_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            return $this->fail($batch, $e->getMessage());
        }

        return $batch;
    }

    /**
     * Applies the rows that passed. Invalid rows are left alone, not guessed at.
     *
     * Each row is its own transaction rather than one transaction for the file. A
     * single failure in row 3,900 must not roll back 3,899 successful ones — the
     * seller would have to start again, and the second attempt would hit the same row.
     */
    public function commit(ImportBatch $batch): ImportBatch
    {
        if (! $batch->status->isCommittable()) {
            throw new RuntimeException('Bu içe aktarma henüz ön izlenmedi.');
        }

        $batch->forceFill(['status' => ImportStatus::Importing])->save();

        $created = 0;
        $updated = 0;
        $failed = 0;

        try {
            $context = $this->buildContext($batch);

            $batch->rows()->valid()->chunkById(200, function ($rows) use ($context, $batch, &$created, &$updated, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $action = DB::transaction(fn (): string => $this->apply($row, $context, $batch));

                        $action === 'create' ? $created++ : $updated++;
                    } catch (Throwable $e) {
                        $failed++;

                        $row->forceFill([
                            'status' => RowStatus::Invalid,
                            'errors' => ['import' => [$e->getMessage()]],
                        ])->save();
                    }
                }
            });
        } catch (Throwable $e) {
            return $this->fail($batch, $e->getMessage());
        }

        $batch->forceFill([
            'status' => ImportStatus::Completed,
            'created_rows' => $created,
            'updated_rows' => $updated,
            'error_rows' => $batch->error_rows + $failed,
            'valid_rows' => max(0, $batch->valid_rows - $failed),
            'committed_at' => now(),
        ])->save();

        return $batch;
    }

    // --- validation ----------------------------------------------------------

    /**
     * Checks one row against the catalogue and returns what it would do.
     *
     * @param  array<string, string>  $mapping
     * @param  array{seller: Seller, categories: array<string, Category>, brands: array<string, Brand>, attributes: array<string, Attribute>, skus: array<string, ProductSku>}  $context
     * @param  array<string, int>  $seenSkus
     * @return array{normalised: array<string, mixed>, errors: array<string, array<int, string>>, action: string|null, product_id: string|null, sku_id: string|null}
     */
    private function evaluate(ImportRow $row, array $mapping, array $context, array $seenSkus): array
    {
        $values = $this->mapRow($row->raw, $mapping);
        $errors = [];
        $normalised = [];

        $sku = trim((string) ($values['sku'] ?? ''));

        if ($sku === '') {
            $errors['sku'][] = 'SKU kodu boş olamaz.';
        } elseif (! preg_match('/^[A-Za-z0-9._-]+$/', $sku)) {
            $errors['sku'][] = 'SKU kodu yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.';
        } elseif (isset($seenSkus[$sku])) {
            $errors['sku'][] = sprintf('Bu SKU kodu %d. satırda da var.', $seenSkus[$sku]);
        }

        $normalised['sku'] = $sku;

        $name = trim((string) ($values['name'] ?? ''));

        if ($name === '') {
            $errors['name'][] = 'Ürün adı boş olamaz.';
        } elseif (mb_strlen($name) < 3) {
            $errors['name'][] = 'Ürün adı en az 3 karakter olmalıdır.';
        }

        $normalised['name'] = $name;
        $normalised['description'] = trim((string) ($values['description'] ?? '')) ?: null;
        $normalised['variant_label'] = trim((string) ($values['variant_label'] ?? '')) ?: null;
        $normalised['barcode'] = trim((string) ($values['barcode'] ?? '')) ?: null;

        // Category by slug or by name, because a seller's file will have whichever
        // their supplier gave them.
        $categoryKey = $this->key((string) ($values['category'] ?? ''));
        $category = $context['categories'][$categoryKey] ?? null;

        if ($categoryKey === '') {
            $errors['category'][] = 'Kategori boş olamaz.';
        } elseif ($category === null) {
            $errors['category'][] = sprintf('"%s" adında bir kategori yok.', trim((string) ($values['category'] ?? '')));
        }

        $normalised['category_id'] = $category?->getKey();

        $brandKey = $this->key((string) ($values['brand'] ?? ''));
        $brand = $brandKey === '' ? null : ($context['brands'][$brandKey] ?? null);

        if ($brandKey !== '' && $brand === null) {
            $errors['brand'][] = sprintf('"%s" adında bir marka yok.', trim((string) ($values['brand'] ?? '')));
        }

        $normalised['brand_id'] = $brand?->getKey();

        // Prices arrive as human decimals and become integer minor units here, once.
        $listMinor = $this->toMinor((string) ($values['list_price'] ?? ''));

        if ($listMinor === null) {
            $errors['list_price'][] = 'Liste fiyatı geçerli bir sayı olmalıdır.';
        } elseif ($listMinor < 0) {
            $errors['list_price'][] = 'Liste fiyatı negatif olamaz.';
        }

        $normalised['list_price_minor'] = $listMinor;

        $saleRaw = trim((string) ($values['sale_price'] ?? ''));
        $saleMinor = $saleRaw === '' ? null : $this->toMinor($saleRaw);

        if ($saleRaw !== '' && $saleMinor === null) {
            $errors['sale_price'][] = 'İndirimli fiyat geçerli bir sayı olmalıdır.';
        } elseif ($saleMinor !== null && $listMinor !== null && $saleMinor > $listMinor) {
            $errors['sale_price'][] = 'İndirimli fiyat liste fiyatından yüksek olamaz.';
        }

        $normalised['sale_price_minor'] = $saleMinor;

        // A tax rate is written as a percentage and stored as basis points.
        $taxRaw = trim((string) ($values['tax_rate'] ?? ''));
        $taxBps = 2000;

        if ($taxRaw !== '') {
            $percent = $this->toDecimal($taxRaw);

            if ($percent === null || $percent < 0 || $percent > 100) {
                $errors['tax_rate'][] = 'KDV oranı 0 ile 100 arasında bir yüzde olmalıdır.';
            } else {
                $taxBps = (int) round($percent * 100);
            }
        }

        $normalised['tax_rate_bps'] = $taxBps;

        foreach (['stock' => 'stock', 'width_mm' => 'width_mm', 'depth_mm' => 'depth_mm', 'height_mm' => 'height_mm', 'weight_g' => 'weight_g'] as $input => $field) {
            $raw = trim((string) ($values[$input] ?? ''));

            if ($raw === '') {
                $normalised[$field] = null;

                continue;
            }

            $number = $this->toDecimal($raw);

            if ($number === null || $number < 0) {
                $errors[$input][] = 'Sayısal bir değer olmalıdır.';
                $normalised[$field] = null;

                continue;
            }

            $normalised[$field] = (int) round($number);
        }

        foreach (['color', 'material'] as $attributeCode) {
            $raw = trim((string) ($values[$attributeCode] ?? ''));

            if ($raw === '') {
                $normalised[$attributeCode] = null;

                continue;
            }

            $match = $this->matchAttributeValue($context, $attributeCode, $raw);

            if ($match === null) {
                $errors[$attributeCode][] = sprintf('"%s" tanımlı bir değer değil.', $raw);
            }

            $normalised[$attributeCode] = $match;
        }

        $existing = $sku === '' ? null : ($context['skus'][$sku] ?? null);

        return [
            'normalised' => $normalised,
            'errors' => $errors,
            'action' => $errors === [] ? ($existing === null ? 'create' : 'update') : null,
            'product_id' => $existing?->product_id,
            'sku_id' => $existing?->getKey(),
        ];
    }

    // --- application ---------------------------------------------------------

    /**
     * @param  array{seller: Seller, categories: array<string, Category>, brands: array<string, Brand>, attributes: array<string, Attribute>, skus: array<string, ProductSku>}  $context
     */
    private function apply(ImportRow $row, array $context, ImportBatch $batch): string
    {
        /** @var array<string, mixed> $values */
        $values = $row->normalised ?? [];
        $seller = $context['seller'];

        $existing = ProductSku::query()
            ->where('seller_id', $seller->getKey())
            ->where('sku', $values['sku'])
            ->first();

        $action = $existing === null ? 'create' : 'update';

        $product = $existing === null
            ? Product::query()->create([
                'organization_id' => $batch->organization_id,
                'primary_category_id' => $values['category_id'],
                'brand_id' => $values['brand_id'],
                'name' => $values['name'],
                'slug' => $this->uniqueSlug((string) $values['name']),
                'description' => $values['description'],
                'created_by' => $batch->created_by,
            ])
            : $existing->product;

        if ($existing !== null && $product !== null) {
            $product->fill([
                'name' => $values['name'],
                'primary_category_id' => $values['category_id'],
                'brand_id' => $values['brand_id'],
                'description' => $values['description'] ?? $product->description,
            ])->save();
        }

        $sku = $existing ?? ProductSku::query()->create([
            'product_id' => $product->getKey(),
            'seller_id' => $seller->getKey(),
            'sku' => $values['sku'],
            'barcode' => $values['barcode'],
            'variant_label' => $values['variant_label'],
            'list_price_minor' => (int) $values['list_price_minor'],
            'sale_price_minor' => $values['sale_price_minor'],
            'tax_rate_bps' => (int) $values['tax_rate_bps'],
            'stock_quantity' => (int) ($values['stock'] ?? 0),
        ]);

        if ($existing !== null) {
            $sku->fill([
                'barcode' => $values['barcode'] ?? $sku->barcode,
                'variant_label' => $values['variant_label'] ?? $sku->variant_label,
                'tax_rate_bps' => (int) $values['tax_rate_bps'],
            ])->save();
        }

        /*
         * Price goes through the price book so the change lands in the history with
         * `import` as its source — which is how a 40% drop caused by a misplaced
         * decimal is later told apart from a deliberate campaign.
         *
         * A newly created SKU already carries the price it was created with, so
         * setPrice() would correctly see no change and write nothing. Its first price
         * is exactly the one whose origin matters most, so it is recorded explicitly.
         */
        if ($action === 'create') {
            $this->prices->recordInitialPrice($sku, $batch->author, 'import');
        } else {
            $this->prices->setPrice(
                $sku,
                Money::of((int) $values['list_price_minor'], $sku->currency),
                $values['sale_price_minor'] === null ? null : Money::of((int) $values['sale_price_minor'], $sku->currency),
                $batch->author,
                'import',
            );
        }

        if ($values['stock'] !== null) {
            // A stocktake, not an adjustment: a spreadsheet column says what the seller
            // believes they have, which is exactly what a count is.
            $item = $this->inventory->itemFor($sku);

            $this->inventory->stocktake(
                $item,
                (int) $values['stock'],
                $batch->author,
                'Toplu içe aktarma: '.$batch->original_name,
            );

            $sku->forceFill(['stock_quantity' => (int) $values['stock']])->save();
        }

        $this->applyDimensions($sku, $values);
        $this->applyAttributes($product, $context, $values);

        $row->forceFill([
            'status' => RowStatus::Imported,
            'action' => $action,
            'product_id' => $product->getKey(),
            'sku_id' => $sku->getKey(),
        ])->save();

        return $action;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function applyDimensions(ProductSku $sku, array $values): void
    {
        $dimensions = array_filter([
            'width_mm' => $values['width_mm'] ?? null,
            'depth_mm' => $values['depth_mm'] ?? null,
            'height_mm' => $values['height_mm'] ?? null,
            'weight_g' => $values['weight_g'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($dimensions === []) {
            return;
        }

        $sku->dimensions()->updateOrCreate(['sku_id' => $sku->getKey()], $dimensions);
    }

    /**
     * @param  array{attributes: array<string, Attribute>}  $context
     * @param  array<string, mixed>  $values
     */
    private function applyAttributes(Product $product, array $context, array $values): void
    {
        foreach (['color', 'material'] as $code) {
            $value = $values[$code] ?? null;

            if ($value === null) {
                continue;
            }

            $attribute = $context['attributes'][$code] ?? null;

            if ($attribute === null) {
                continue;
            }

            $option = $attribute->values->firstWhere('value', $value);

            if ($option === null) {
                continue;
            }

            $product->attributeValues()->updateOrCreate(
                ['product_id' => $product->getKey(), 'attribute_id' => $attribute->getKey()],
                ['attribute_value_id' => $option->getKey()],
            );
        }
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Loads the whole catalogue vocabulary once.
     *
     * Looking a category up per row would be forty thousand queries for a
     * twenty-thousand-line file. The taxonomy is small enough to hold in memory and
     * changes far more slowly than an import runs.
     *
     * @return array{seller: Seller, categories: array<string, Category>, brands: array<string, Brand>, attributes: array<string, Attribute>, skus: array<string, ProductSku>}
     */
    private function buildContext(ImportBatch $batch): array
    {
        $seller = Seller::query()
            ->where('organization_id', $batch->organization_id)
            ->first();

        if ($seller === null) {
            throw new RuntimeException('Bu organizasyona bağlı onaylı satıcı hesabı yok.');
        }

        $categories = [];

        foreach (Category::query()->active()->get() as $category) {
            $categories[$this->key($category->slug)] = $category;
            $categories[$this->key($category->name)] = $category;
        }

        $brands = [];

        foreach (Brand::query()->where('is_active', true)->get() as $brand) {
            $brands[$this->key($brand->slug)] = $brand;
            $brands[$this->key($brand->name)] = $brand;
        }

        $attributes = [];

        foreach (Attribute::query()->whereIn('code', ['color', 'material'])->with('values')->get() as $attribute) {
            $attributes[$attribute->code] = $attribute;
        }

        $skus = [];

        foreach (ProductSku::query()->where('seller_id', $seller->getKey())->get() as $sku) {
            $skus[$sku->sku] = $sku;
        }

        return [
            'seller' => $seller,
            'categories' => $categories,
            'brands' => $brands,
            'attributes' => $attributes,
            'skus' => $skus,
        ];
    }

    /**
     * @param  array{attributes: array<string, Attribute>}  $context
     */
    private function matchAttributeValue(array $context, string $code, string $raw): ?string
    {
        $attribute = $context['attributes'][$code] ?? null;

        if ($attribute === null) {
            return null;
        }

        $needle = $this->key($raw);

        foreach ($attribute->values as $option) {
            if ($this->key($option->value) === $needle || $this->key($option->label) === $needle) {
                return $option->value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private function mapRow(array $raw, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $header => $field) {
            $values[$field] = (string) ($raw[$header] ?? '');
        }

        return $values;
    }

    /** Human decimal to integer minor units — the one conversion in the import path. */
    private function toMinor(string $raw): ?int
    {
        $decimal = $this->toDecimal($raw);

        return $decimal === null ? null : (int) round($decimal * 100);
    }

    /**
     * Parses a number written either way round.
     *
     * "1.234,56" is Turkish and "1,234.56" is English, and a supplier file may contain
     * either. Whichever separator appears last is the decimal one, because no locale
     * puts a grouping separator after the decimal point.
     */
    private function toDecimal(string $raw): ?float
    {
        $cleaned = preg_replace('/[^0-9,.\-]/u', '', trim($raw)) ?? '';

        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /** Case- and accent-insensitive key for matching a seller's spelling. */
    private function key(string $value): string
    {
        // One folding rule for the whole system: see TurkishText for why the order of
        // lowercasing and folding is not interchangeable.
        return app(TurkishText::class)->fold($value, '-');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'urun';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Copies the upload to local disk so the reader can stream it.
     *
     * Object storage cannot be seeked, and both delimiter detection and the reader
     * itself need a real file handle.
     */
    private function materialise(ImportBatch $batch): string
    {
        $stream = Storage::disk($batch->disk)->readStream($batch->storage_path);

        if ($stream === null) {
            throw new RuntimeException('Yüklenen dosya bulunamadı.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'rc-import-');

        if ($temporary === false) {
            throw new RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $target = fopen($temporary, 'wb');

        if ($target === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $temporary;
    }

    private function fail(ImportBatch $batch, string $reason): ImportBatch
    {
        $batch->forceFill([
            'status' => ImportStatus::Failed,
            'failure_reason' => Str::limit($reason, 900),
        ])->save();

        return $batch;
    }
}
