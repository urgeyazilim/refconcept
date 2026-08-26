<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Models\LedgerAccountRow;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerLine;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only thing that writes to the ledger.
 *
 * Three rules, all of them the difference between a ledger and a table of numbers:
 *
 *  1. **Every entry balances.** Checked here with a sentence a developer can act on, and
 *     again by a deferred constraint trigger in the database that no caller can route
 *     around.
 *
 *  2. **Nothing is ever edited.** A mistake is corrected by a reversing entry, so both the
 *     mistake and the correction stay visible. {@see reverse()} is the only way to undo
 *     anything, and it writes rather than deletes.
 *
 *  3. **The same event posts once.** Payment confirmations arrive more than once as a
 *     matter of course. The idempotency key is derived from the event by the caller, and a
 *     second attempt returns the first entry instead of doubling the platform's revenue.
 *
 * Accounts are created on demand rather than seeded, because per-seller payable accounts
 * cannot be known in advance and a seeder that has to run before a seller can be paid is a
 * deployment step somebody will forget.
 */
final class Ledger
{
    /**
     * Posts one balanced journal entry.
     *
     * @param  list<JournalLine>  $lines
     *
     * @throws InvalidArgumentException when the lines do not balance
     */
    public function post(
        string $type,
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
        string $currency = 'TRY',
        ?User $actor = null,
    ): LedgerEntry {
        if ($lines === []) {
            throw new InvalidArgumentException('Bir yevmiye kaydı en az bir satır içermeli.');
        }

        $debit = array_sum(array_map(static fn (JournalLine $line): int => $line->debitMinor, $lines));
        $credit = array_sum(array_map(static fn (JournalLine $line): int => $line->creditMinor, $lines));

        if ($debit !== $credit) {
            /*
             * Caught here so the message names the figures. The database trigger would
             * refuse it too, but at commit time and with a message about a table.
             */
            throw new InvalidArgumentException(sprintf(
                'Yevmiye kaydı denk değil: borç %d, alacak %d.',
                $debit,
                $credit,
            ));
        }

        if ($idempotencyKey !== null) {
            $existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use (
                $type, $description, $lines, $referenceType, $referenceId, $idempotencyKey, $currency, $actor
            ): LedgerEntry {
                $entry = LedgerEntry::query()->create([
                    'type' => $type,
                    'description' => mb_substr($description, 0, 300),
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'currency' => $currency,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actor?->getKey(),
                    'posted_at' => now(),
                ]);

                $this->writeLines($entry, $lines, $currency);

                return $entry;
            });
        } catch (QueryException $e) {
            /*
             * Two callers posted the same event at the same instant and the unique index
             * settled it. The loser reports the winner's entry — one event, one journal.
             */
            if ($idempotencyKey !== null) {
                $winner = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($winner !== null) {
                    return $winner;
                }
            }

            throw $e;
        }
    }

    /**
     * Undoes an entry by writing its opposite.
     *
     * Never a delete and never an edit. A refund, a mis-posted commission and a cancelled
     * order all end up here, and afterwards the journal shows what happened *and* that it
     * was undone — which is what somebody reading it six months later needs.
     */
    public function reverse(LedgerEntry $entry, string $reason, ?User $actor = null): LedgerEntry
    {
        $entry->loadMissing(['lines.account']);

        $existing = LedgerEntry::query()->where('reverses_entry_id', $entry->getKey())->first();

        if ($existing !== null) {
            // Reversing twice would re-post the original. Once is enough, and a caller
            // retrying after a timeout means to reverse once.
            return $existing;
        }

        $lines = [];

        foreach ($entry->lines as $line) {
            $account = $line->account;

            if ($account === null) {
                continue;
            }

            $code = LedgerAccount::from($account->code);

            $lines[] = $line->debit_minor > 0
                ? JournalLine::credit($code, $line->debit_minor, $line->seller_id, $line->memo)
                : JournalLine::debit($code, $line->credit_minor, $line->seller_id, $line->memo);
        }

        return DB::transaction(function () use ($entry, $lines, $reason, $actor): LedgerEntry {
            $reversal = LedgerEntry::query()->create([
                'type' => $entry->type.'.reversal',
                'description' => mb_substr($reason, 0, 300),
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'reverses_entry_id' => $entry->getKey(),
                'currency' => $entry->currency,
                'idempotency_key' => 'reversal:'.$entry->getKey(),
                'created_by' => $actor?->getKey(),
                'posted_at' => now(),
            ]);

            $this->writeLines($reversal, $lines, $entry->currency);

            return $reversal;
        });
    }

    /**
     * The account for a code, created if this is the first time it is needed.
     *
     * Per-seller accounts cannot be seeded, and a seeder that has to run before a seller
     * can be paid is a deployment step somebody will forget on the day it matters.
     */
    public function account(LedgerAccount $code, ?string $sellerId = null, string $currency = 'TRY'): LedgerAccountRow
    {
        $sellerId = $code->isPerSeller() ? $sellerId : null;

        $existing = LedgerAccountRow::query()
            ->where('code', $code->value)
            ->where('currency', $currency)
            ->where(fn ($query) => $sellerId === null
                ? $query->whereNull('seller_id')
                : $query->where('seller_id', $sellerId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LedgerAccountRow::query()->create([
            'code' => $code->value,
            'type' => $code->type(),
            'name' => $code->label(),
            'seller_id' => $sellerId,
            'currency' => $currency,
        ]);
    }

    /**
     * What an account holds, in the direction it normally runs.
     *
     * A liability with a credit balance reads as a positive number owed, rather than as a
     * negative asset — which is what somebody who does not do double-entry for a living
     * expects to see.
     */
    public function balanceOf(LedgerAccount $code, ?string $sellerId = null, string $currency = 'TRY'): int
    {
        $account = $this->account($code, $sellerId, $currency);

        $totals = LedgerLine::query()
            ->where('account_id', $account->getKey())
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit_minor), 0) AS debit, COALESCE(SUM(credit_minor), 0) AS credit')
            ->first();

        $debit = (int) ($totals->debit ?? 0);
        $credit = (int) ($totals->credit ?? 0);

        return $code->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * What one seller's lines add up to in an account.
     *
     * Different from {@see balanceOf()} for the shared accounts: commission all lands in
     * one revenue account, but every line keeps the seller it came from, so "what did we
     * earn from this shop" is a query rather than a reconstruction from order tables.
     */
    public function sellerTotal(LedgerAccount $code, string $sellerId, string $currency = 'TRY'): int
    {
        $account = $this->account($code, $code->isPerSeller() ? $sellerId : null, $currency);

        $totals = LedgerLine::query()
            ->where('account_id', $account->getKey())
            ->where('seller_id', $sellerId)
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit_minor), 0) AS debit, COALESCE(SUM(credit_minor), 0) AS credit')
            ->first();

        $debit = (int) ($totals->debit ?? 0);
        $credit = (int) ($totals->credit ?? 0);

        return $code->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * Whether the whole journal balances.
     *
     * The invariant the finance suite exists to assert. If this ever returns false, no
     * report built on the ledger means anything, so it is worth being able to ask cheaply.
     */
    public function isBalanced(): bool
    {
        $totals = LedgerLine::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit_minor), 0) AS debit, COALESCE(SUM(credit_minor), 0) AS credit')
            ->first();

        return (int) ($totals->debit ?? 0) === (int) ($totals->credit ?? 0);
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function writeLines(LedgerEntry $entry, array $lines, string $currency): void
    {
        foreach ($lines as $line) {
            $account = $this->account($line->account, $line->sellerId, $currency);

            LedgerLine::query()->create([
                'entry_id' => $entry->getKey(),
                'account_id' => $account->getKey(),
                'debit_minor' => $line->debitMinor,
                'credit_minor' => $line->creditMinor,
                'currency' => $currency,
                'seller_id' => $line->sellerId,
                'memo' => $line->memo,
            ]);
        }
    }
}
