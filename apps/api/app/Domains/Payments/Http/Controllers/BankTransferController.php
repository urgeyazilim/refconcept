<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use App\Domains\Payments\Services\BankTransferService;
use App\Domains\Payments\Services\ReceiptStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's side of a bank transfer.
 *
 * A transfer is reached through its payment, never by its own id, and the payment is
 * checked against the caller first — the reference is short and typable, which is exactly
 * what makes it guessable.
 */
final class BankTransferController
{
    public function __construct(
        private readonly BankTransferService $transfers,
        private readonly ReceiptStorage $receipts,
    ) {}

    /** The accounts on offer, before a payment exists. */
    public function accounts(Request $request): JsonResponse
    {
        $currency = (string) $request->query('currency', 'TRY');

        return $this->json([
            'data' => $this->transfers->accounts($currency)
                ->map(fn (PaymentBankAccount $account): array => $account->toCustomerArray())
                ->values()
                ->all(),
        ]);
    }

    /** Where to pay, and how it is going. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $transfer = $this->ownedTransfer($request, $reference);

        return $this->json(['data' => $transfer->toCustomerArray()]);
    }

    /**
     * The customer says they have sent it.
     *
     * A claim, not a confirmation: it moves the transfer to review and nothing is
     * released. A receipt is a picture, and pictures are easy to make.
     */
    public function submit(Request $request, string $reference): JsonResponse
    {
        $transfer = $this->ownedTransfer($request, $reference);

        $this->transfers->markSubmitted($transfer);

        return $this->json(['data' => $transfer->fresh()?->toCustomerArray()]);
    }

    public function uploadReceipt(Request $request, string $reference): JsonResponse
    {
        $transfer = $this->ownedTransfer($request, $reference);

        $request->validate([
            'file' => ['required', 'file', 'max:8192', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ]);

        $file = $request->file('file');

        abort_unless($file !== null, 422);

        $this->receipts->store($transfer, $file, $this->user($request));
        $this->transfers->markSubmitted($transfer);

        return $this->json(['data' => $transfer->fresh()?->toCustomerArray()], 201);
    }

    // --- internals -----------------------------------------------------------

    private function ownedTransfer(Request $request, string $reference): BankTransfer
    {
        $user = $this->user($request);

        $transfer = BankTransfer::query()
            ->with(['bankAccount', 'intent'])
            ->where('reference', $reference)
            ->first();

        if ($transfer === null || $transfer->intent?->user_id !== $user->getKey()) {
            // 404 either way: whether somebody else's reference exists is not a thing to
            // confirm to a stranger typing guesses.
            throw CheckoutRefused::transferNotFound();
        }

        return $transfer;
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
