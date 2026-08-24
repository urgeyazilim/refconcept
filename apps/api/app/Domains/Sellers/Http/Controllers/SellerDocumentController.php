<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Sellers\Enums\DocumentType;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerDocument;
use App\Domains\Sellers\Services\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload and retrieval of onboarding documents.
 *
 * Authorisation happens on the *application*, not the document: a document is only
 * ever reachable through the application it belongs to, which is what stops one
 * applicant enumerating another's identity papers by guessing ids.
 */
final class SellerDocumentController
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $application = $this->currentApplication($request);
        abort_unless($request->user()?->can('update', $application) === true, 403);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'file' => [
                'required',
                'file',
                'max:'.(DocumentStorage::MAX_SIZE_BYTES / 1024),
                'mimetypes:'.implode(',', DocumentStorage::ALLOWED_MIME_TYPES),
            ],
        ]);

        $type = DocumentType::from((string) $validated['type']);

        try {
            $document = $this->storage->store(
                application: $application,
                file: $request->file('file'),
                type: $type,
                uploader: $request->user(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        /*
         * Replacing a document supersedes the previous one of the same type rather
         * than deleting it: what was submitted at review time stays provable, and the
         * checklist only counts the live row.
         */
        $application->documents()
            ->where('type', $type->value)
            ->whereKeyNot($document->getKey())
            ->delete();

        $this->audit->record(
            action: 'sellers.document.uploaded',
            subject: $document,
            context: ['type' => $type->value, 'size_bytes' => $document->size_bytes],
            actor: $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => $document->id,
                'type' => $document->type->value,
                'type_label' => $document->type->label(),
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'status' => $document->status->value,
            ],
        ], 201);
    }

    /** A short-lived link, issued only after the ownership check. */
    public function link(Request $request, SellerDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        return response()->json([
            'data' => [
                'url' => $this->storage->temporaryUrl($document),
                'expires_in' => 300,
            ],
        ]);
    }

    /**
     * Streams the file for storage drivers that cannot sign a URL.
     *
     * The bytes pass through the application so the policy check still applies; a
     * public path would hand out an identity document to anyone with the link.
     */
    public function download(Request $request, SellerDocument $document): StreamedResponse
    {
        $this->authorizeDocument($request, $document);

        abort_unless($this->storage->exists($document), 404);

        $stream = $this->storage->readStream($document);

        abort_if($stream === null, 404);

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    public function destroy(Request $request, SellerDocument $document): JsonResponse
    {
        $application = $document->application;
        abort_unless($request->user()?->can('update', $application) === true, 403);

        $document->delete();

        $this->audit->record(
            action: 'sellers.document.deleted',
            subject: $document,
            actor: $request->user(),
        );

        return response()->json(['message' => 'Belge kaldırıldı.']);
    }

    private function authorizeDocument(Request $request, SellerDocument $document): void
    {
        abort_unless($request->user()?->can('view', $document->application) === true, 403);
    }

    private function currentApplication(Request $request): SellerApplication
    {
        $application = SellerApplication::query()
            ->where('applicant_user_id', $request->user()?->getKey())
            ->orderByDesc('created_at')
            ->first();

        abort_if($application === null, 404, 'Satıcı başvurunuz bulunamadı.');

        return $application;
    }
}
