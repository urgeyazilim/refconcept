<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Requests;

use App\Domains\Products\Enums\StockPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates one seller offer.
 *
 * Prices arrive as **integers of minor units**, never as decimals. Accepting
 * "489.90" would mean parsing a decimal at the edge of the system, and every parser
 * that touches money is a place a kuruş can go missing. The client formats for
 * display; the wire carries the exact integer.
 */
final class StoreSkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is checked in the controller, which has the product.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $skuId = $this->route('sku')?->getKey();

        return [
            'sku' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9._-]+$/',
            ],
            'barcode' => ['nullable', 'string', 'max:60'],
            'variant_label' => ['nullable', 'string', 'max:160'],

            // From the config rather than a second list here. The platform supports TRY and
            // says so in one place; a hardcoded list is how a currency nobody supports
            // ends up accepted by a form.
            'currency' => [
                'sometimes', 'string', 'size:3',
                Rule::in((array) config('refconcept.money.supported_currencies', ['TRY'])),
            ],

            'list_price_minor' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'sale_price_minor' => ['nullable', 'integer', 'min:0', 'max:99999999999'],

            // Basis points: 20% is 2000. A percentage float would reintroduce rounding
            // error into every order total.
            'tax_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'],

            'stock_policy' => ['sometimes', Rule::enum(StockPolicy::class)],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            'dimensions' => ['sometimes', 'array'],
            'dimensions.width_mm' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'dimensions.height_mm' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'dimensions.depth_mm' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'dimensions.weight_g' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'dimensions.package_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'dimensions.assembly_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $list = $this->integer('list_price_minor');
                $sale = $this->input('sale_price_minor');

                // A sale price above the list price shows the customer a negative
                // discount, which reads as a bug in the storefront rather than a typo
                // in the seller's form.
                if ($sale !== null && (int) $sale > $list) {
                    $validator->errors()->add(
                        'sale_price_minor',
                        'İndirimli fiyat liste fiyatından yüksek olamaz.',
                    );
                }

                // Tracked stock without a quantity is the state that oversells.
                if (
                    $this->input('stock_policy', 'track') === StockPolicy::Track->value
                    && $this->input('stock_quantity') === null
                    && ! $this->isMethod('PATCH')
                ) {
                    $validator->errors()->add(
                        'stock_quantity',
                        'Stok takipli seçenekler için stok adedi zorunludur.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.regex' => 'SKU kodu yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'list_price_minor.required' => 'Liste fiyatı zorunludur.',
            'list_price_minor.integer' => 'Fiyat, kuruş cinsinden tam sayı olmalıdır (48.900,00 ₺ = 4890000).',
        ];
    }
}
