<?php

declare(strict_types=1);

use App\Domains\Imports\Enums\ImportStatus;
use App\Domains\Imports\Models\ImportBatch;
use App\Domains\Imports\Models\ImportRow;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Pricing\Models\PriceHistory;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk import: the path by which a catalogue actually gets populated.
 *
 * The behaviour worth protecting is the *shape* of the process rather than the
 * parsing. A seller sees what will happen before it happens, one malformed line does
 * not take the other 399 with it, and running the same file twice updates rather than
 * duplicates. Those are what make an importer usable; the CSV reading is the easy part.
 */
beforeEach(function (): void {
    Storage::fake('s3');
    config()->set('refconcept.storage.private_disk', 's3');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');
    [$this->rivalSeller, $this->rivalUser] = makeApprovedSeller('Nova Yaşam', 'nova-yasam');
});

/**
 * A Turkish-Excel-shaped CSV: semicolons, comma decimals, a BOM.
 *
 * Written the way a real export looks rather than the way a parser would like it,
 * because that is the file the importer has to survive.
 *
 * @param  array<int, array<int, string>>  $rows
 */
function csvFile(array $rows, string $name = 'urunler.csv'): UploadedFile
{
    $headers = 'SKU Kodu;Ürün Adı;Kategori;Marka;Liste Fiyatı;KDV;Stok;Genişlik;Derinlik;Renk;Malzeme';

    $lines = ["\u{FEFF}".$headers];

    foreach ($rows as $row) {
        $lines[] = implode(';', $row);
    }

    $path = tempnam(sys_get_temp_dir(), 'rc-test-').'.csv';
    file_put_contents($path, implode("\r\n", $lines)."\r\n");

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/** Uploads a file and returns the batch it produced. */
function uploadCsv(UploadedFile $file): ImportBatch
{
    $response = test()->actingAs(test()->sellerUser)
        ->postJson('/api/v1/seller/imports', ['file' => $file])
        ->assertCreated();

    return ImportBatch::query()->findOrFail($response->json('data.id'));
}

// --- reading the file --------------------------------------------------------------

it('reads a semicolon file with a byte-order mark and Turkish decimals', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Bouclé Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    // A BOM left on the first header makes that one column silently fail to map while
    // every other column works — the kind of bug a seller reports as "it lost my SKUs".
    expect($batch->status)->toBe(ImportStatus::Mapped)
        ->and($batch->total_rows)->toBe(1)
        ->and($batch->detected_headers[0])->toBe('sku kodu')
        ->and($batch->mapping)->toHaveKey('sku kodu')
        ->and($batch->mapping['liste fiyatı'])->toBe('list_price');
});

it('stores every line verbatim so an error can be explained later', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-002', 'Koltuk', 'koltuk', 'Arden', '18.900,00', '20', '3', '780', '820', 'Taş', 'Keten'],
    ]));

    $rows = ImportRow::query()->where('batch_id', $batch->getKey())->orderBy('line_number')->get();

    // Line numbers count the file's own lines, header included, so a message can name
    // the line the seller sees in Excel.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->line_number)->toBe(2)
        ->and($rows[0]->raw['liste fiyatı'])->toBe('48.900,00');
});

it('skips trailing blank rows rather than reporting them as errors', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        [';;;;;;;;;;'],
        ['', '', '', '', '', '', '', '', '', '', ''],
    ]));

    expect($batch->total_rows)->toBe(1);
});

// --- the dry run ---------------------------------------------------------------------

it('checks every row and writes nothing to the catalogue', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-002', 'X', 'yok-boyle-kategori', 'Arden', 'abc', '20', '3', '780', '820', 'Krem', 'Keten'],
    ]));

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate")
        ->assertOk()
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.error_rows', 1)
        ->assertJsonPath('data.can_commit', true);

    // The whole point of a dry run: the catalogue is untouched.
    expect(Product::query()->count())->toBe(0)
        ->and(ProductSku::query()->count())->toBe(0);
});

it('names what is wrong with a row, field by field', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-002', 'X', 'yok-boyle-kategori', 'Yok Marka', 'abc', '20', '3', '780', '820', 'Mor', 'Keten'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");

    $row = ImportRow::query()->where('batch_id', $batch->getKey())->firstOrFail();
    $errors = $row->errors;

    expect($errors)->toHaveKeys(['name', 'category', 'brand', 'list_price', 'color'])
        ->and($errors['name'][0])->toContain('3 karakter')
        ->and($errors['category'][0])->toContain('yok-boyle-kategori');
});

it('catches a SKU that appears twice in the same file', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-001', 'Kanepe (kopya)', 'kanepe', 'Arden', '38.900,00', '20', '2', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");

    $second = ImportRow::query()->where('batch_id', $batch->getKey())->where('line_number', 3)->firstOrFail();

    // Two rows for one SKU would both look fine and the second would silently
    // overwrite the first, which is indistinguishable from the import losing a product.
    expect($second->errors['sku'][0])->toContain('2. satırda');
});

it('refuses to run a dry run before the required columns are mapped', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/imports/{$batch->getKey()}/mapping", [
            'mapping' => ['sku kodu' => 'sku'],
        ])
        ->assertOk()
        ->assertJsonPath('data.missing_required', ['Ürün adı', 'Kategori', 'Liste fiyatı']);

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");

    expect($batch->fresh()->status)->toBe(ImportStatus::Failed);
});

it('refuses a mapping that points two columns at one field', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/imports/{$batch->getKey()}/mapping", [
            'mapping' => ['sku kodu' => 'sku', 'marka' => 'sku'],
        ])
        ->assertStatus(422);
});

// --- committing ------------------------------------------------------------------------

it('creates products, prices and stock from a validated file', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Bouclé Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-002', 'Keten Koltuk', 'koltuk', 'Arden', '18.900,50', '10', '3', '780', '820', 'Taş', 'Keten'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit")
        ->assertOk()
        ->assertJsonPath('data.created_rows', 2)
        ->assertJsonPath('data.updated_rows', 0);

    $sku = ProductSku::query()->where('sku', 'ATL-001')->firstOrFail();

    // "48.900,00" is Turkish for 48900.00 — the one conversion in the import path, and
    // the one place a misread decimal would put a sofa on sale for 489 lira.
    expect($sku->list_price_minor->amountMinor)->toBe(4_890_000)
        ->and($sku->tax_rate_bps)->toBe(2000)
        ->and($sku->dimensions->width_mm)->toBe(2200)
        ->and($sku->product->name)->toBe('Bouclé Kanepe');

    $second = ProductSku::query()->where('sku', 'ATL-002')->firstOrFail();

    expect($second->list_price_minor->amountMinor)->toBe(1_890_050)
        ->and($second->tax_rate_bps)->toBe(1000);
});

it('records an imported price as an import rather than as a manual change', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit");

    // A 40% drop caused by a misplaced decimal looks identical to a deliberate
    // campaign until you can see where the number came from.
    expect(PriceHistory::query()->where('source', 'import')->exists())->toBeTrue();
});

it('puts imported stock through the ledger as a count', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '9', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit");

    $sku = ProductSku::query()->where('sku', 'ATL-001')->firstOrFail();
    $item = app(InventoryLedger::class)->itemFor($sku);

    // A spreadsheet column says what the seller believes they have, which is a count —
    // not a delta that would double on the next weekly upload.
    expect($item->on_hand)->toBe(9)
        ->and($item->movements()->where('type', 'stocktake')->exists())->toBeTrue();
});

it('updates rather than duplicates when the same file is imported again', function (): void {
    $rows = [
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ];

    $first = uploadCsv(csvFile($rows));
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$first->getKey()}/validate");
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$first->getKey()}/commit");

    $second = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '43.900,00', '20', '4', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$second->getKey()}/validate");

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/imports/{$second->getKey()}/commit")
        ->assertOk()
        ->assertJsonPath('data.created_rows', 0)
        ->assertJsonPath('data.updated_rows', 1);

    // A weekly price-and-stock file has to work, not produce 400 duplicates a Monday.
    expect(ProductSku::query()->count())->toBe(1)
        ->and(ProductSku::query()->first()->list_price_minor->amountMinor)->toBe(4_390_000);
});

it('imports the good rows and leaves the bad ones alone', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-002', 'X', 'yok', 'Arden', 'abc', '20', '3', '780', '820', 'Krem', 'Keten'],
        ['ATL-003', 'Sehpa', 'sehpa', 'Nordhem', '12.400,00', '20', '8', '900', '900', 'Meşe', 'Masif Meşe'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit");

    // One malformed line must not take the other two with it — the seller would have
    // to start again, and the second attempt would hit the same row.
    expect(ProductSku::query()->pluck('sku')->sort()->values()->all())->toBe(['ATL-001', 'ATL-003']);
});

it('refuses to commit a batch that has not been dry-run', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit")
        ->assertStatus(422);

    expect(Product::query()->count())->toBe(0);
});

// --- isolation and shape ------------------------------------------------------------

it('never lets one seller open another seller import', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    // A supplier price list is the one document that tells a competitor exactly what
    // somebody pays. 404 rather than 403: the id should not even be confirmable.
    $this->actingAs($this->rivalUser)
        ->getJson("/api/v1/seller/imports/{$batch->getKey()}")
        ->assertNotFound();

    $this->actingAs($this->rivalUser)
        ->postJson("/api/v1/seller/imports/{$batch->getKey()}/commit")
        ->assertNotFound();
});

it('never exposes the storage path of an upload', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
    ]));

    $response = $this->actingAs($this->sellerUser)
        ->getJson("/api/v1/seller/imports/{$batch->getKey()}")
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('imports/');
});

it('lists the invalid rows by default, because that is what a seller came for', function (): void {
    $batch = uploadCsv(csvFile([
        ['ATL-001', 'Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        ['ATL-002', 'X', 'yok', 'Arden', 'abc', '20', '3', '780', '820', 'Krem', 'Keten'],
    ]));

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/imports/{$batch->getKey()}/validate");

    $this->actingAs($this->sellerUser)
        ->getJson("/api/v1/seller/imports/{$batch->getKey()}/rows")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.line_number', 3);
});

it('serves a template built from the fields the mapper understands', function (): void {
    $response = $this->actingAs($this->sellerUser)
        ->get('/api/v1/seller/imports/template')
        ->assertOk();

    $body = $response->getContent();

    // Semicolons and a BOM, because that is what Turkish Excel opens by double-click.
    // A template that opens as one column teaches the seller the feature is broken.
    expect($body)->toStartWith("\u{FEFF}")
        ->and($body)->toContain('SKU kodu;')
        ->and($body)->toContain('48.900,00');
});

it('refuses a file that is not a spreadsheet', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/imports', [
            'file' => UploadedFile::fake()->create('katalog.pdf', 40, 'application/pdf'),
        ])
        ->assertStatus(422);
});
