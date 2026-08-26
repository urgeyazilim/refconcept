<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Enums\SettlementStatus;
use App\Domains\Finance\Exceptions\SettlementRefused;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Models\SettlementItem;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Building, approving and paying a payout run.
 *
 * Three steps, and the split between them is the whole design. Building is arithmetic and
 * can be re-run; approving is a decision that commits the money and cannot be taken back
 * casually; paying is a person recording that a transfer actually left. Collapsing them
 * into one action would mean a mistake in the arithmetic becomes a bank transfer.
 *
 * The ledger is only touched at the last two steps, and never edited afterwards:
 *
 *   approve  debit  LIABILITY:SELLER_PAYABLE   the amount leaves what we owe
 *            credit CLEARING:PAYOUT            and sits in flight
 *
 *   pay      debit  CLEARING:PAYOUT            it stops being in flight
 *            credit ASSET:BANK                 and leaves the bank
 *
 * A draft posts nothing, which is why re-running the builder is safe.
 */
final class SettlementService
{
    public function __construct(
        private readonly SettlementEligibility $eligibility,
        private readonly Ledger $ledger,
        private readonly OrderAccounting $accounting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Builds a draft from everything currently eligible.
     *
     * Returns the existing draft when there is one rather than making a second — a seller
     * with two open settlements is a seller whose orders can end up in both, and the
     * partial unique index would refuse the second anyway.
     *
     * @throws SettlementRefused
     */
    public function build(Seller $seller, string $currency = 'TRY'): Settlement
    {
        $existing = Settlement::query()
            ->where('seller_id', $seller->getKey())
            ->where('currency', $currency)
            ->open()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $orders = $this->eligibility->eligible((string) $seller->getKey(), $currency);

        if ($orders->isEmpty()) {
            throw SettlementRefused::nothingEligible();
        }

        $settlement = DB::transaction(function () use ($seller, $currency, $orders): Settlement {
            $settlement = Settlement::query()->create([
                'reference' => $this->reference(),
                'seller_id' => $seller->getKey(),
                'currency' => $currency,
                // The period is what the run actually covers, taken from the orders in it
                // rather than from a calendar: a fortnightly cycle with nothing in the
                // first week should not claim to cover it.
                'period_start' => $orders->first()?->delivered_at?->toDateString() ?? now()->toDateString(),
                'period_end' => $orders->last()?->delivered_at?->toDateString() ?? now()->toDateString(),
            ]);

            $gross = 0;
            $commission = 0;
            $net = 0;

            foreach ($orders as $sellerOrder) {
                $payable = $sellerOrder->payableMinor();

                SettlementItem::query()->create([
                    'settlement_id' => $settlement->getKey(),
                    'seller_order_id' => $sellerOrder->getKey(),
                    'gross_minor' => $sellerOrder->total_minor,
                    'commission_minor' => $sellerOrder->commission_minor,
                    'net_minor' => $payable,
                ]);

                $gross += $sellerOrder->total_minor;
                $commission += $sellerOrder->commission_minor;
                $net += $payable;
            }

            $settlement->forceFill([
                'gross_minor' => $gross,
                'commission_minor' => $commission,
                'net_minor' => $net,
            ])->save();

            return $settlement;
        });

        $this->accounting->rebuildBalance((string) $seller->getKey(), $currency);

        return $settlement->fresh(['items']) ?? $settlement;
    }

    /**
     * Commits the money.
     *
     * From here the amount is out of the seller's available balance and in a clearing
     * account, so it cannot be counted twice or swept into a second settlement while
     * somebody is at a bank making the transfer.
     *
     * @throws SettlementRefused
     */
    public function approve(Settlement $settlement, User $operator, ?string $note = null): Settlement
    {
        $approved = DB::transaction(function () use ($settlement, $operator, $note): Settlement {
            /** @var Settlement $locked */
            $locked = Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SettlementStatus::Draft) {
                // Two operators on two stale screens. The second is told what happened
                // rather than allowed through to post a second journal.
                throw SettlementRefused::alreadyDecided($locked->status->label());
            }

            if ($locked->net_minor <= 0) {
                throw SettlementRefused::nothingToPay();
            }

            $locked->forceFill([
                'status' => SettlementStatus::Approved,
                'approved_by' => $operator->getKey(),
                'approved_at' => now(),
                'note' => $note ?? $locked->note,
            ])->save();

            $this->ledger->post(
                type: 'settlement.approved',
                description: 'Hakediş onaylandı: '.$locked->reference,
                lines: [
                    JournalLine::debit(
                        LedgerAccount::SellerPayable,
                        $locked->net_minor,
                        (string) $locked->seller_id,
                        $locked->reference,
                    ),
                    JournalLine::credit(
                        LedgerAccount::PayoutClearing,
                        $locked->net_minor,
                        (string) $locked->seller_id,
                        $locked->reference,
                    ),
                ],
                referenceType: 'settlement',
                referenceId: (string) $locked->getKey(),
                idempotencyKey: 'settlement-approved:'.$locked->getKey(),
                currency: $locked->currency,
                actor: $operator,
            );

            return $locked;
        });

        $this->accounting->rebuildBalance((string) $approved->seller_id, $approved->currency);

        $this->audit->record(
            action: 'finance.settlement.approved',
            subject: $approved,
            context: ['reference' => $approved->reference, 'net_minor' => $approved->net_minor],
            reason: $note,
            actor: $operator,
        );

        return $approved;
    }

    /**
     * Records that the transfer left.
     *
     * A person who has seen it, with the bank's own reference. Until then it has not left,
     * whatever the screen says — and a settlement marked paid on optimism is a seller
     * asking where their money is with our own record saying it was sent.
     *
     * @throws SettlementRefused
     */
    public function markPaid(Settlement $settlement, User $operator, string $payoutReference): Settlement
    {
        $paid = DB::transaction(function () use ($settlement, $operator, $payoutReference): Settlement {
            /** @var Settlement $locked */
            $locked = Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SettlementStatus::Approved) {
                throw SettlementRefused::notApproved($locked->status->label());
            }

            $locked->forceFill([
                'status' => SettlementStatus::Paid,
                'paid_by' => $operator->getKey(),
                'paid_at' => now(),
                'payout_reference' => $payoutReference,
            ])->save();

            $this->ledger->post(
                type: 'settlement.paid',
                description: 'Hakediş ödendi: '.$locked->reference,
                lines: [
                    JournalLine::debit(
                        LedgerAccount::PayoutClearing,
                        $locked->net_minor,
                        (string) $locked->seller_id,
                        $payoutReference,
                    ),
                    JournalLine::credit(
                        LedgerAccount::Bank,
                        $locked->net_minor,
                        memo: $locked->reference,
                    ),
                ],
                referenceType: 'settlement',
                referenceId: (string) $locked->getKey(),
                idempotencyKey: 'settlement-paid:'.$locked->getKey(),
                currency: $locked->currency,
                actor: $operator,
            );

            return $locked;
        });

        $this->accounting->rebuildBalance((string) $paid->seller_id, $paid->currency);

        $this->audit->record(
            action: 'finance.settlement.paid',
            subject: $paid,
            context: ['reference' => $paid->reference, 'payout_reference' => $payoutReference],
            actor: $operator,
        );

        return $paid;
    }

    /**
     * Abandons a run.
     *
     * A draft simply goes away; an approved one has to be unwound in the ledger, which is
     * a reversing entry rather than a delete — the money was committed and the record of
     * committing it stays.
     *
     * @throws SettlementRefused
     */
    public function cancel(Settlement $settlement, User $operator, string $reason): Settlement
    {
        $cancelled = DB::transaction(function () use ($settlement, $operator, $reason): Settlement {
            /** @var Settlement $locked */
            $locked = Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isFinal()) {
                throw SettlementRefused::alreadyDecided($locked->status->label());
            }

            if ($locked->status === SettlementStatus::Approved) {
                $entry = LedgerEntry::query()
                    ->where('idempotency_key', 'settlement-approved:'.$locked->getKey())
                    ->first();

                if ($entry !== null) {
                    $this->ledger->reverse($entry, 'Hakediş iptal edildi: '.$reason, $operator);
                }
            }

            $locked->forceFill([
                'status' => SettlementStatus::Cancelled,
                'note' => $reason,
            ])->save();

            // The items go with it: those orders become eligible again, which is the whole
            // point of cancelling rather than paying zero.
            SettlementItem::query()->where('settlement_id', $locked->getKey())->delete();

            return $locked;
        });

        $this->accounting->rebuildBalance((string) $cancelled->seller_id, $cancelled->currency);

        $this->audit->record(
            action: 'finance.settlement.cancelled',
            subject: $cancelled,
            context: ['reference' => $cancelled->reference],
            reason: $reason,
            actor: $operator,
        );

        return $cancelled;
    }

    /**
     * Builds a draft for every seller that has something eligible.
     *
     * Run on a schedule. Sellers with nothing are skipped silently rather than given an
     * empty settlement to look at.
     *
     * @return list<Settlement>
     */
    public function buildAll(string $currency = 'TRY'): array
    {
        $built = [];

        foreach (Seller::query()->where('status', 'active')->cursor() as $seller) {
            try {
                $built[] = $this->build($seller, $currency);
            } catch (SettlementRefused) {
                continue;
            }
        }

        return $built;
    }

    /**
     * A reference an operator can quote to a bank.
     *
     * Retried on collision rather than assumed unique: the odds are remote, and the unique
     * index would otherwise turn one into a 500 in the middle of a payout run.
     */
    private function reference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = sprintf('HK-%s-%s', now()->format('Ym'), Str::upper(Str::random(6)));

            if (! Settlement::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new QueryException('pgsql', 'settlement reference', [], new RuntimeException('Hakediş referansı üretilemedi.'));
    }
}
