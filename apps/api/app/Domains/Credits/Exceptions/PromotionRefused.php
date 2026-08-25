<?php

declare(strict_types=1);

namespace App\Domains\Credits\Exceptions;

use RuntimeException;

/**
 * A promotion code was not accepted.
 *
 * Two of the three messages are deliberately identical. A code that does not exist, a
 * campaign that has ended and a budget that has run out all produce the same sentence,
 * because telling them apart turns this endpoint into an oracle: an attacker trying
 * dictionary words would learn which ones are real live campaigns, and could then watch
 * for one to open.
 *
 * "Already redeemed" is different and safe to say plainly. The person asking has already
 * proved they know the code, so nothing is disclosed — and being told "you have used
 * this" instead of "invalid code" is the difference between a customer understanding
 * what happened and one opening a support ticket.
 */
final class PromotionRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }

    /** Unknown, expired, not started, or out of budget — one answer for all four. */
    public static function unusable(): self
    {
        return new self('Bu kod geçerli değil.', 'unusable');
    }

    public static function alreadyRedeemed(): self
    {
        return new self('Bu kodu zaten kullandınız.', 'already_redeemed');
    }

    public static function notEligible(): self
    {
        return new self('Bu kampanya yalnızca yeni hesaplar için geçerli.', 'not_eligible');
    }
}
