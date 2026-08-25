<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use App\Domains\Payments\Models\PaymentReceipt;
use App\Domains\Payments\Services\BankTransferService;
use App\Domains\Payments\Services\ReceiptStorage;
use App\Support\ValueObjects\Iban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Finance's side of a bank transfer.
 *
 * Reading a payment and settling one are separate permissions: answering "did it arrive"
 * is a support job, while confirming that it did releases goods and cannot be undone.
 * Every decision carries who, when, against which statement date, and — for anything but a
 * clean confirmation — why.
 */
final class AdminPaymentController
{
    public function __construct(
        private readonly BankTransferService $transfers,
        private readonly ReceiptStorage $receipts,
        private readonly AccessControl $access,
    ) {}

    /** The queue: what is waiting, oldest first. */
    public function transfers(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $status = $request->query('status');

        $query = BankTransfer::query()
            ->with(['bankAccount', 'intent.user', 'intent.session'])
            ->orderBy('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            // The default is the queue, not the archive: a screen that opens on everything
            // ever is a screen nobody uses to work.
            $query->open();
        }

        return $this->json([
            'data' => $query->limit(200)->get()->map(fn (BankTransfer $transfer): array => $this->row($transfer))->all(),
        ]);
    }

    public function transfer(Request $request, BankTransfer $transfer): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        $transfer->loadMissing(['bankAccount', 'intent.user', 'receipts', 'confirmedBy']);

        return $this->json([
            'data' => $this->row($transfer) + [
                'receipts' => $transfer->receipts->map->toArray()->all(),
                'decision_note' => $transfer->decision_note,
                'confirmed_by' => $transfer->confirmedBy?->email,
                'confirmed_at' => $transfer->confirmed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * A short-lived link to one receipt.
     *
     * Issued after the permission check, never stored, and gone in minutes: the file shows
     * somebody's bank balance.
     */
    public function receipt(Request $request, BankTransfer $transfer, PaymentReceipt $receipt): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        abort_unless($receipt->bank_transfer_id === $transfer->getKey(), 404);

        return $this->json(['data' => ['url' => $this->receipts->temporaryUrl($receipt)]]);
    }

    /**
     * The money arrived.
     *
     * The amount is what the statement says rather than what was expected, and the
     * difference decides the outcome. An operator cannot round.
     */
    public function confirm(Request $request, BankTransfer $transfer): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'received_minor' => ['required', 'integer', 'min:1'],
            'value_date' => ['required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $settled = $this->transfers->confirm(
            $transfer,
            (int) $validated['received_minor'],
            Carbon::parse($validated['value_date']),
            $this->user($request),
            $validated['note'] ?? null,
        );

        return $this->json(['data' => $this->row($settled)]);
    }

    public function reject(Request $request, BankTransfer $transfer): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        // The reason is required, not optional: an unexplained financial refusal is
        // indistinguishable from a mistake when somebody reads it back later.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $rejected = $this->transfers->reject($transfer, $this->user($request), $validated['reason']);

        return $this->json(['data' => $this->row($rejected)]);
    }

    // --- receiving accounts ---------------------------------------------------

    public function accounts(Request $request): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsView);

        return $this->json([
            'data' => PaymentBankAccount::query()->orderBy('position')->get()->map(
                fn (PaymentBankAccount $account): array => $account->toCustomerArray() + [
                    'is_active' => $account->is_active,
                    'position' => $account->position,
                ],
            )->all(),
        ]);
    }

    public function saveAccount(Request $request, ?PaymentBankAccount $account = null): JsonResponse
    {
        $this->authorise($request, Permission::PaymentsSettle);

        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_holder' => ['required', 'string', 'max:160'],
            /*
             * The mod-97 check, not merely a length. A mistyped receiving IBAN sends
             * every customer's money somewhere else, and this is the check that catches
             * the single-character and transposition mistakes people actually make.
             */
            'iban' => [
                'required',
                'string',
                'max:42',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || ! Iban::isValid($value)) {
                        $fail('Geçerli bir IBAN girin.');
                    }
                },
            ],
            'currency' => ['sometimes', 'string', 'size:3'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        // Validated above, so construction cannot fail here; the value object normalises
        // spacing and case so the unique index sees one form of one number.
        $iban = Iban::fromString($validated['iban']);

        $account ??= new PaymentBankAccount;

        $account->fill($validated + ['iban' => $iban->value()])->save();

        return $this->json(['data' => $account->toCustomerArray()], $account->wasRecentlyCreated ? 201 : 200);
    }

    // --- internals -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function row(BankTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'reference' => $transfer->reference,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'expected_minor' => $transfer->expected_minor,
            'received_minor' => $transfer->received_minor,
            'shortfall_minor' => $transfer->shortfallMinor(),
            'currency' => $transfer->currency,
            'customer_email' => $transfer->intent?->user?->email,
            'bank_account' => $transfer->bankAccount?->bank_name,
            'value_date' => $transfer->value_date?->toDateString(),
            'expires_at' => $transfer->expires_at?->toIso8601String(),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'is_decidable' => $transfer->status->isDecidable(),
            'receipt_count' => $transfer->receipts()->count(),
        ];
    }

    private function authorise(Request $request, Permission $permission): void
    {
        $user = $this->user($request);

        abort_unless($this->access->hasPermission($user, $permission), 403);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
