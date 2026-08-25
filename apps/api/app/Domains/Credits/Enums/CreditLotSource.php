<?php

declare(strict_types=1);

namespace App\Domains\Credits\Enums;

/**
 * Where a batch of credits came from.
 *
 * Kept on the lot rather than inferred from the transaction that created it, because a
 * lot outlives that transaction's usefulness: months later the question is "which of my
 * credits are about to expire and where did they come from", and answering it should not
 * require joining back through the ledger.
 */
enum CreditLotSource: string
{
    case Purchase = 'purchase';
    case Grant = 'grant';
    case Promotion = 'promotion';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Satın alma',
            self::Grant => 'Tanımlama',
            self::Promotion => 'Promosyon',
            self::Refund => 'İade',
            self::Adjustment => 'Düzeltme',
        };
    }

    public function transactionType(): CreditTransactionType
    {
        return match ($this) {
            self::Purchase => CreditTransactionType::Purchase,
            self::Grant => CreditTransactionType::Grant,
            self::Promotion => CreditTransactionType::Promotion,
            self::Refund => CreditTransactionType::Refund,
            self::Adjustment => CreditTransactionType::Adjustment,
        };
    }
}
