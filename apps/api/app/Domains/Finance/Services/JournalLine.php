<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Enums\LedgerAccount;

/**
 * One side of one journal entry.
 *
 * Debit and credit are separate constructors rather than a signed amount, because a signed
 * amount makes direction a matter of interpretation and every report then has to agree on
 * which sign means what. `JournalLine::debit(...)` says it once, where it is written.
 */
final readonly class JournalLine
{
    private function __construct(
        public LedgerAccount $account,
        public int $debitMinor,
        public int $creditMinor,
        public ?string $sellerId = null,
        public ?string $memo = null,
    ) {}

    public static function debit(LedgerAccount $account, int $amountMinor, ?string $sellerId = null, ?string $memo = null): self
    {
        return new self($account, $amountMinor, 0, $sellerId, $memo);
    }

    public static function credit(LedgerAccount $account, int $amountMinor, ?string $sellerId = null, ?string $memo = null): self
    {
        return new self($account, 0, $amountMinor, $sellerId, $memo);
    }

    public function amountMinor(): int
    {
        return $this->debitMinor !== 0 ? $this->debitMinor : $this->creditMinor;
    }

    /** The same line the other way round, for a reversal. */
    public function reversed(): self
    {
        return new self(
            $this->account,
            $this->creditMinor,
            $this->debitMinor,
            $this->sellerId,
            $this->memo,
        );
    }
}
