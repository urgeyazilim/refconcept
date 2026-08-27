<?php

declare(strict_types=1);

namespace App\Domains\Projects\Exceptions;

use RuntimeException;

/**
 * A generation could not be completed.
 *
 * Every message here is written for the customer rather than for a log, because that is
 * where it ends up: on the version's `failure_reason` and from there on the screen. The
 * distinction that matters to somebody staring at a failed render is whether it is worth
 * pressing the button again, so `isRetryable` is carried alongside the sentence.
 *
 * What is deliberately never in these messages is the provider's own words. "Rate limit
 * exceeded for gpt-image-1 in org-abc123" tells a customer nothing they can act on and
 * tells a competitor something they would like to know.
 */
final class DesignGenerationFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $stage,
        public readonly bool $isRetryable,
    ) {
        parent::__construct($message);
    }

    public static function roomHasNoPhotograph(): self
    {
        return new self(
            'Bu oda için önce bir fotoğraf yüklemeniz gerekiyor.',
            'analysis',
            // Not retryable in the sense the word matters here: pressing again changes
            // nothing until the customer does something.
            isRetryable: false,
        );
    }

    /**
     * The AI gateway would not take the request at all.
     *
     * A paused task or an unrouted one. The gateway's own message is already written for
     * a customer, so it is passed through rather than replaced — telling somebody their
     * render broke when an operator switched the feature off would be a lie with a
     * support ticket attached.
     */
    public static function unavailable(string $message): self
    {
        return new self($message, 'queued', isRetryable: false);
    }

    public static function analysisFailed(string $reason): self
    {
        return new self(
            'Oda fotoğrafı okunamadı: '.$reason.'. Daha aydınlık bir fotoğrafla tekrar deneyin.',
            'analysis',
            isRetryable: true,
        );
    }

    public static function planFailed(string $reason): self
    {
        return new self(
            'Yerleşim planı hazırlanamadı: '.$reason.'. Lütfen tekrar deneyin.',
            'plan',
            isRetryable: true,
        );
    }

    public static function renderFailed(string $reason): self
    {
        return new self(
            'Görsel üretilemedi: '.$reason.'. Lütfen tekrar deneyin.',
            'render',
            isRetryable: true,
        );
    }

    /**
     * The model answered, and there was no picture in the answer.
     *
     * Distinct from a provider failure because it means something different operationally:
     * the call succeeded, the money was spent, and the pipeline still has nothing to save.
     */
    public static function renderProducedNoImage(): self
    {
        return new self(
            'Görsel üretimi tamamlandı ancak bir görsel dönmedi. Lütfen tekrar deneyin.',
            'render',
            isRetryable: true,
        );
    }

    public static function renderCouldNotBeSaved(string $reason): self
    {
        return new self(
            'Üretilen görsel kaydedilemedi: '.$reason,
            'save',
            isRetryable: true,
        );
    }

    /**
     * The plan was sound and the catalogue had none of it.
     *
     * Refused rather than rendered. The alternative is a picture of a room furnished
     * entirely with things that cannot be bought, which is a worse outcome than no picture:
     * it looks like the product working, and the customer only discovers otherwise when the
     * shopping list underneath it is empty.
     */
    public static function nothingToPlace(): self
    {
        return new self(
            'Bu plandaki ürünlerin hiçbiri katalogda bulunamadı, bu yüzden görsel '
            .'üretilmedi. Farklı bir stil veya bütçe ile tekrar deneyebilirsiniz.',
            'match',
            isRetryable: false,
        );
    }

    public static function planHadNothingUsable(): self
    {
        return new self(
            'Bu oda için uygun bir yerleşim bulunamadı. Oda ölçülerini ve kısıtları '
            .'kontrol edip tekrar deneyin.',
            'plan',
            isRetryable: false,
        );
    }
}
