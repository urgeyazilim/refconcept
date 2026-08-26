<?php

declare(strict_types=1);

namespace App\Domains\Finance\Enums;

/**
 * The chart of accounts, as named in 06_SECURITY_PAYMENT_FINANCE_RULES.md.
 *
 * An enum rather than free strings because an account code is the one thing in a journal
 * that must never be invented at a call site. A typo in `REVENUE:COMMISSION` does not fail
 * — it silently opens a second revenue account and splits the platform's income between
 * two of them, and nobody notices until the two are added up separately.
 *
 * `SellerPayable` is per-seller: the account row carries the seller id rather than the
 * code interpolating it, because a code that has to be parsed to be understood is a code
 * somebody will parse wrongly.
 */
enum LedgerAccount: string
{
    /** Money the provider is holding for us, before it reaches the bank. */
    case CashProvider = 'ASSET:CASH_PROVIDER';

    /** Money in the platform's own bank account. */
    case Bank = 'ASSET:BANK';

    /** What we owe one seller. Per-seller. */
    case SellerPayable = 'LIABILITY:SELLER_PAYABLE';

    /** What we owe a customer who is due a refund. */
    case CustomerRefund = 'LIABILITY:CUSTOMER_REFUND';

    /** The platform's cut. */
    case Commission = 'REVENUE:COMMISSION';

    /** Credit packages sold. */
    case CreditRevenue = 'REVENUE:CREDIT';

    case GatewayExpense = 'EXPENSE:PAYMENT_GATEWAY';

    case AiExpense = 'EXPENSE:AI';

    /** In flight between a customer's payment and its allocation. */
    case PaymentClearing = 'CLEARING:PAYMENT';

    /** In flight between an approved settlement and the money leaving. */
    case PayoutClearing = 'CLEARING:PAYOUT';

    public function type(): string
    {
        return match ($this) {
            self::CashProvider, self::Bank => 'asset',
            self::SellerPayable, self::CustomerRefund => 'liability',
            self::Commission, self::CreditRevenue => 'revenue',
            self::GatewayExpense, self::AiExpense => 'expense',
            self::PaymentClearing, self::PayoutClearing => 'clearing',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CashProvider => 'Sağlayıcıdaki nakit',
            self::Bank => 'Banka',
            self::SellerPayable => 'Satıcıya borç',
            self::CustomerRefund => 'Müşteriye iade borcu',
            self::Commission => 'Komisyon geliri',
            self::CreditRevenue => 'Kredi geliri',
            self::GatewayExpense => 'Ödeme sağlayıcı gideri',
            self::AiExpense => 'Yapay zekâ gideri',
            self::PaymentClearing => 'Ödeme ara hesabı',
            self::PayoutClearing => 'Hakediş ara hesabı',
        };
    }

    /** Whether this account is kept separately per seller. */
    public function isPerSeller(): bool
    {
        return $this === self::SellerPayable;
    }

    /**
     * Which way a balance normally runs.
     *
     * Assets and expenses grow on the debit side; liabilities, revenue and clearing on the
     * credit side. Used to turn a column of debits and credits into a number somebody can
     * read without knowing double-entry.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type(), ['asset', 'expense'], true);
    }
}
