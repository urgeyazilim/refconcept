<?php

declare(strict_types=1);

namespace App\Domains\Projects\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A new design version cannot be created for the reason given.
 *
 * Carries a machine-readable code alongside the message, because the callers want
 * different things from it: the API turns it into a validation error the customer
 * reads, and the design engine in Phase 8 branches on the code to decide whether to
 * refund reserved credits.
 */
final class DesignVersionRefused extends RuntimeException
{
    /**
     * Named `reason` rather than `code`: PHP's own Exception already has a `$code`
     * property, and redeclaring it as readonly is a fatal error rather than an
     * override.
     */
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function roomHasNoPhotograph(): self
    {
        return new self(
            'room_missing_photo',
            'Tasarım oluşturmadan önce odanın fotoğrafını yüklemeniz gerekiyor.',
        );
    }

    public static function parentNotReady(): self
    {
        return new self(
            'parent_not_ready',
            'Yalnızca tamamlanmış bir tasarımdan yeni sürüm türetilebilir.',
        );
    }

    public static function parentBelongsElsewhere(): self
    {
        return new self(
            'parent_mismatch',
            'Seçilen sürüm bu tasarıma ait değil.',
        );
    }

    public static function projectArchived(): self
    {
        return new self(
            'project_archived',
            'Arşivlenmiş bir projede yeni tasarım oluşturulamaz.',
        );
    }

    public static function alreadyFinished(): self
    {
        return new self(
            'version_finished',
            'Tamamlanmış bir sürüm değiştirilemez; yeni bir sürüm oluşturun.',
        );
    }

    public function toValidationException(string $field = 'design'): ValidationException
    {
        return ValidationException::withMessages([$field => [$this->getMessage()]]);
    }
}
