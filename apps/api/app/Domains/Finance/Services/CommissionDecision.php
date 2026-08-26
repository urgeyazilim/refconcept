<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

/**
 * A rate, and the reason for it.
 *
 * The reason travels with the number because "why is my commission 14%" is the question a
 * seller asks most often, and "because of the September campaign" is an answer somebody
 * can act on where a bare 1400 is not.
 */
final readonly class CommissionDecision
{
    public function __construct(
        public int $rateBps,
        /** platform · category · seller · seller_category · campaign · fallback */
        public string $scope,
        public ?string $ruleId = null,
        public ?string $label = null,
    ) {}

    public function reason(): string
    {
        return match ($this->scope) {
            'campaign' => $this->label ?? 'Kampanya oranı',
            'seller_category' => 'Satıcı ve kategoriye özel oran',
            'seller' => 'Satıcıya özel oran',
            'category' => 'Kategori oranı',
            'platform' => 'Platform varsayılanı',
            default => 'Tanımlı kural bulunamadı; varsayılan oran uygulandı',
        };
    }
}
