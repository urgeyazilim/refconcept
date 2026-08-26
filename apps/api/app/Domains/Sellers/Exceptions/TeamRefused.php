<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Exceptions;

use RuntimeException;

/**
 * A refused change to a seller's team.
 *
 * Carries its own status, because the reasons are not the same kind of thing. Somebody
 * who already belongs is a 409 — the request was fine and the world already agrees with
 * it — while a role that does not exist is a 422, because the request itself was wrong.
 * A controller inventing an answer would get that distinction wrong sooner or later.
 *
 * Every message is written for the person who pressed the button, in the words they would
 * use. "Bu hesap zaten ekibinizde" is something a seller can act on; a constraint name is
 * not.
 */
final class TeamRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function alreadyAMember(): self
    {
        return new self('Bu hesap zaten ekibinizde.', 409);
    }

    public static function notAMember(): self
    {
        return new self('Bu hesap ekibinizde değil.', 404);
    }

    /**
     * The refusal that saves somebody their account: a company with no owner is a company
     * where nobody can add one back.
     */
    public static function lastOwner(): self
    {
        return new self(
            'Tek yetkili sizsiniz. Önce başka bir hesabı yetkili yapın, sonra kendinizi çıkarın.',
            409,
        );
    }

    public static function unknownRole(string $role): self
    {
        return new self(sprintf('"%s" bir satıcı rolü değil.', $role), 422);
    }

    public static function noAccount(string $email): self
    {
        return new self(
            sprintf('%s adresiyle bir hesap bulunamadı. Önce bu adresle kayıt olması gerekiyor.', $email),
            422,
        );
    }

    /** One person, one seller: isolation in this platform is written per organization. */
    public static function belongsElsewhere(): self
    {
        return new self('Bu hesap başka bir satıcının ekibinde.', 409);
    }
}
