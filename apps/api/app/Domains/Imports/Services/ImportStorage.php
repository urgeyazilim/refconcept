<?php

declare(strict_types=1);

namespace App\Domains\Imports\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Imports\Models\ImportBatch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores an uploaded spreadsheet.
 *
 * On the **private** disk, under a random key. A seller's supplier price list is
 * commercially sensitive — it is the one document that tells a competitor exactly what
 * they pay — so it gets the same treatment as an identity document rather than the
 * same treatment as a product photograph.
 */
final class ImportStorage
{
    public const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    public const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    public function store(
        Organization $organization,
        ?Seller $seller,
        UploadedFile $file,
        User $uploader,
        string $type = 'products',
    ): ImportBatch {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, SpreadsheetReader::SUPPORTED_EXTENSIONS, true)) {
            throw new RuntimeException('Yalnızca CSV ve XLSX dosyaları yüklenebilir.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Dosya boyutu 20 MB sınırını aşıyor.');
        }

        $disk = (string) config('refconcept.storage.private_disk', config('filesystems.default'));

        $path = sprintf(
            'imports/%s/%s.%s',
            $organization->getKey(),
            Str::uuid7()->toString(),
            $extension,
        );

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Yüklenen dosya okunamadı.');
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ImportBatch::query()->create([
            'organization_id' => $organization->getKey(),
            'seller_id' => $seller?->getKey(),
            'type' => $type,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'disk' => $disk,
            'storage_path' => $path,
            'size_bytes' => (int) $file->getSize(),
            'created_by' => $uploader->getKey(),
        ]);
    }

    /**
     * The example file a seller downloads before their first import.
     *
     * Generated rather than committed as a static asset, so it can never fall out of
     * step with the columns the mapper actually understands. Semicolon-separated with
     * a BOM, because that is what Turkish Excel opens correctly by double-click — a
     * template that opens as one column teaches the seller the feature is broken.
     */
    public function template(): string
    {
        $headers = [];
        $example = [];

        foreach (ImportColumnMapper::FIELDS as $definition) {
            $headers[] = $definition['label'];
        }

        $example = [
            'ATL-KNP-001',
            'Bouclé Üç Kişilik Kanepe',
            'Masif kayın iskelet, çıkarılabilir kılıf.',
            'kanepe',
            'Arden',
            '8680000000001',
            'Ekru · 220 cm',
            '48.900,00',
            '43.900,00',
            '20',
            '6',
            '2200',
            '950',
            '780',
            '54000',
            'Krem',
            'Bouclé',
        ];

        return "\u{FEFF}".implode(';', $headers)."\r\n".implode(';', $example)."\r\n";
    }

    /** Removes the upload once its rows are stored; the rows are the record now. */
    public function discard(ImportBatch $batch): void
    {
        Storage::disk($batch->disk)->delete($batch->storage_path);
    }
}
