<?php

declare(strict_types=1);

namespace App\Domains\Products\Http\Requests;

use App\Domains\Products\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:250'],
            'description' => ['nullable', 'string', 'max:20000'],

            // Only leaf categories accept listings: a product filed under "Mobilya"
            // rather than "Kanepe" cannot be matched or filtered usefully.
            'primary_category_id' => [
                'required',
                'uuid',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],

            'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')],
            'style_id' => ['nullable', 'uuid', Rule::exists('styles', 'id')],
            'product_type' => ['sometimes', Rule::in(['simple', 'variant', 'bundle'])],
            'organization_id' => ['sometimes', 'uuid'],

            'seo_title' => ['nullable', 'string', 'max:250'],
            'seo_description' => ['nullable', 'string', 'max:500'],

            'attributes' => ['sometimes', 'array'],
            'attributes.*.code' => ['required', 'string', 'max:80'],
            'attributes.*.value' => ['required'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ürün adı zorunludur.',
            'primary_category_id.required' => 'Kategori seçimi zorunludur.',
        ];
    }
}
