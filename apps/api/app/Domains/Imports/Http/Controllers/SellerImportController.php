<?php

declare(strict_types=1);

namespace App\Domains\Imports\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Imports\Enums\RowStatus;
use App\Domains\Imports\Models\ImportBatch;
use App\Domains\Imports\Models\ImportRow;
use App\Domains\Imports\Services\ImportColumnMapper;
use App\Domains\Imports\Services\ImportStorage;
use App\Domains\Imports\Services\ProductImportRunner;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Bulk product import, driven by the seller.
 *
 * Four steps, each its own request, because each is a decision the seller makes:
 * upload, confirm the column mapping, run the dry run, commit. Collapsing them into
 * one "import" button is what produces a half-changed catalogue and a support ticket.
 *
 * Every batch is scoped to the organizations the signed-in user belongs to. There is
 * no batch id that opens somebody else's supplier price list.
 */
final class SellerImportController
{
    public function __construct(
        private readonly ImportStorage $storage,
        private readonly ProductImportRunner $runner,
        private readonly ImportColumnMapper $mapper,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationIds = $this->organizationIds($request);

        $batches = ImportBatch::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $batches->map(fn (ImportBatch $batch): array => $this->summary($batch))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organizationIds = $this->organizationIds($request);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(ImportStorage::MAX_SIZE_BYTES / 1024),
            ],
        ]);

        $organization = Organization::query()->findOrFail($organizationIds[0]);
        $seller = Seller::query()->where('organization_id', $organization->getKey())->first();

        try {
            $batch = $this->storage->store(
                organization: $organization,
                seller: $seller,
                file: $request->file('file'),
                uploader: $request->user(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        // Parsed inline rather than queued: the seller is sitting in front of the
        // screen waiting to map columns, and a 20 MB file parses in seconds. The queue
        // is where the commit belongs, not the read.
        $this->runner->analyse($batch);

        // The upload has served its purpose; every line is now a row we can query.
        $this->storage->discard($batch->fresh());

        $this->audit->record(
            action: 'imports.batch.uploaded',
            subject: $batch,
            context: ['rows' => $batch->fresh()->total_rows],
            actor: $request->user(),
            organizationId: $organization->getKey(),
        );

        return response()->json(['data' => $this->detail($batch->fresh())], 201);
    }

    public function show(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json(['data' => $this->detail($batch)]);
    }

    /** The seller corrects the guessed column mapping. */
    public function updateMapping(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        abort_unless($batch->status->isMappable(), 422, 'Bu içe aktarmanın eşleştirmesi artık değiştirilemez.');

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'in:'.implode(',', array_keys(ImportColumnMapper::FIELDS))],
        ]);

        /** @var array<string, string|null> $mapping */
        $mapping = $validated['mapping'];

        // Nulls mean "ignore this column"; storing them would make the field look
        // mapped to nothing rather than unmapped.
        $mapping = array_filter($mapping, static fn (?string $field): bool => $field !== null && $field !== '');

        if (count($mapping) !== count(array_unique($mapping))) {
            throw ValidationException::withMessages([
                'mapping' => ['Aynı alan birden fazla sütuna eşleştirilemez.'],
            ]);
        }

        $batch->forceFill(['mapping' => $mapping])->save();

        return response()->json(['data' => $this->detail($batch->fresh())]);
    }

    /** The dry run. Nothing is written to the catalogue. */
    public function validateBatch(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        abort_unless($batch->status->isMappable(), 422, 'Bu içe aktarma ön izlenemez.');

        $this->runner->validate($batch);

        return response()->json(['data' => $this->detail($batch->fresh())]);
    }

    public function commit(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        abort_unless($batch->status->isCommittable(), 422, 'Önce ön izleme çalıştırılmalı.');

        $this->runner->commit($batch);

        $fresh = $batch->fresh();

        $this->audit->record(
            action: 'imports.batch.committed',
            subject: $fresh,
            context: [
                'created' => $fresh->created_rows,
                'updated' => $fresh->updated_rows,
                'errors' => $fresh->error_rows,
            ],
            actor: $request->user(),
            organizationId: $fresh->organization_id,
        );

        return response()->json(['data' => $this->detail($fresh)]);
    }

    /**
     * The rows, filtered by status.
     *
     * Defaults to the invalid ones: a seller opening this screen after a dry run wants
     * the problems, not a paginated copy of their own spreadsheet.
     */
    public function rows(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,valid,invalid,imported,skipped'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $batch->rows()->getQuery()
            ->where('status', $validated['status'] ?? RowStatus::Invalid->value);

        $rows = $query->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (ImportRow $row): array => [
                'line_number' => $row->line_number,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'action' => $row->action,
                'raw' => $row->raw,
                'errors' => $row->errorMessages(),
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** The example file, generated from the fields the mapper understands. */
    public function template(): Response
    {
        return response($this->storage->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="refconcept-urun-sablonu.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroy(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        $batch->delete();

        return response()->json(['message' => 'İçe aktarma kaydı kaldırıldı.']);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function summary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'original_name' => $batch->original_name,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'is_running' => $batch->status->isRunning(),
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'error_rows' => $batch->error_rows,
            'created_rows' => $batch->created_rows,
            'updated_rows' => $batch->updated_rows,
            'progress_percent' => $batch->progressPercent(),
            'failure_reason' => $batch->failure_reason,
            'created_at' => $batch->created_at?->toIso8601String(),
            'committed_at' => $batch->committed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ImportBatch $batch): array
    {
        return [
            ...$this->summary($batch),
            'detected_headers' => $batch->detected_headers ?? [],
            'mapping' => $batch->mapping ?? [],
            'fields' => $this->mapper->fieldCatalogue(),
            'missing_required' => $this->mapper->missingRequired($batch->mapping ?? []),
            'can_validate' => $batch->status->isMappable(),
            'can_commit' => $batch->status->isCommittable(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function organizationIds(Request $request): array
    {
        $ids = $this->access->organizationIds($request->user());

        abort_if($ids === [], 403, 'Satıcı hesabınız bulunmuyor.');

        return $ids;
    }

    private function authorizeBatch(Request $request, ImportBatch $batch): void
    {
        // 404 rather than 403: a batch id that belongs to another seller should not be
        // confirmable as existing.
        abort_unless(
            in_array($batch->organization_id, $this->organizationIds($request), true),
            404,
        );
    }
}
