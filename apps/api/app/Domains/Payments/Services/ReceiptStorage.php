<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores and serves transfer receipts.
 *
 * A bank's PDF or a screenshot of an app: an account number, a balance and a full name.
 * Same tier as seller onboarding documents, and the same three rules — private disk,
 * random key, and access only through a short-lived signed URL issued after a check.
 */
final class ReceiptStorage
{
    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const MAX_SIZE_BYTES = 8 * 1024 * 1024;

    private const SIGNED_URL_TTL_MINUTES = 5;

    public function store(BankTransfer $transfer, UploadedFile $file, User $uploader): PaymentReceipt
    {
        $this->assertAcceptable($file);

        $path = sprintf(
            'payment-receipts/%s/%s.%s',
            $transfer->getKey(),
            Str::uuid7()->toString(),
            strtolower($file->getClientOriginalExtension() ?: 'bin'),
        );

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Yüklenen dosya okunamadı.');
        }

        try {
            Storage::disk($this->disk())->put($path, $stream);
        } finally {
            fclose($stream);
        }

        return PaymentReceipt::query()->create([
            'bank_transfer_id' => $transfer->getKey(),
            'uploaded_by' => $uploader->getKey(),
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'storage_path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ]);
    }

    /**
     * A link that stops working.
     *
     * Minutes, not hours. A URL that works forever is a leak waiting for one careless
     * forward, and this one shows somebody's bank balance.
     */
    public function temporaryUrl(PaymentReceipt $receipt): string
    {
        $disk = Storage::disk($this->disk());

        if (! method_exists($disk, 'temporaryUrl')) {
            throw new RuntimeException('Bu disk imzalı bağlantı üretemiyor.');
        }

        return $disk->temporaryUrl($receipt->storage_path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Dosya en fazla 8 MB olabilir.');
        }

        if (! in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Yalnızca PDF veya görsel yükleyebilirsiniz.');
        }
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.private_disk', config('filesystems.default'));
    }
}
