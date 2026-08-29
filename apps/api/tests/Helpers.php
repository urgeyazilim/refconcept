<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Enums\StockPolicy;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Shared test helpers
|--------------------------------------------------------------------------
| Pest test files share one global function namespace, so a helper defined in two
| suites is a fatal redeclare. These live here, loaded once through composer's
| autoload-dev files, and are available to every suite.
|
| They exist because setting up a seller is genuinely involved — organization,
| membership, role grant and seller row — and repeating it inline buries the
| behaviour each test is actually about.
*/

if (! function_exists('grantPlatformRole')) {
    /**
     * Grants a platform-scoped role.
     *
     * There is no HTTP endpoint for this on purpose, so tests take the same route the
     * console command does.
     */
    function grantPlatformRole(User $user, SystemRole $role): UserRole
    {
        $model = Role::query()
            ->where('slug', $role->value)
            ->where('scope', $role->scope()->value)
            ->firstOrFail();

        return UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $model->getKey(),
            'organization_id' => null,
            'granted_at' => now(),
        ]);
    }
}

if (! function_exists('makeApprovedSeller')) {
    /**
     * Creates an approved seller with an owner who can act inside it.
     *
     * Membership and a role grant are both created, because either alone is useless:
     * membership without a grant authorises nothing, a grant without membership never
     * matches.
     *
     * @return array{0: Seller, 1: User}
     */
    function makeApprovedSeller(string $name, string $slug, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();

        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => OrganizationType::Seller,
            'status' => OrganizationStatus::Active,
            'owner_user_id' => $owner->getKey(),
        ]);

        OrganizationUser::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $role = Role::query()
            ->where('slug', SystemRole::SellerOwner->value)
            ->where('scope', SystemRole::SellerOwner->scope()->value)
            ->firstOrFail();

        UserRole::query()->create([
            'user_id' => $owner->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $organization->getKey(),
            'granted_at' => now(),
        ]);

        $seller = Seller::query()->create([
            'organization_id' => $organization->getKey(),
            'seller_code' => 'RC-'.strtoupper(substr(md5($slug.microtime()), 0, 6)),
            'display_name' => $name,
        ]);

        return [$seller, $owner];
    }
}

if (! function_exists('makeAiRoute')) {
    /**
     * Builds a complete, working route for one task.
     *
     * Written out rather than reached for through the seeder on purpose. The seeder
     * points routes at whichever provider has a key on file, which makes it a fine way
     * to start a development database and a terrible one to build a test on: the same
     * assertions would exercise a different provider depending on whose `.env` ran them.
     *
     * Everything here goes through the fake provider, and the numbers — the cost
     * ceiling, the attempt count — are arguments so that a test about a cost ceiling
     * says what ceiling it means.
     *
     * @param  array<string, mixed>  $attributes  overrides for the route row
     * @return array{0: AiTaskRoute, 1: AiModel}
     */
    function makeAiRoute(AiTask $task, array $attributes = [], bool $withFallback = false): array
    {
        $provider = AiProvider::query()->firstOrCreate(
            ['code' => 'fake'],
            ['name' => 'Test sağlayıcı', 'driver' => 'fake', 'is_active' => true],
        );

        // A credential, because a real adapter refuses without one and a test route that
        // only works for the fake would hide that the moment somebody swaps the driver.
        $provider->credentials()->firstOrCreate(
            ['label' => 'test'],
            ['secret_encrypted' => 'test-key-0000000000', 'secret_hint' => '0000', 'is_active' => true],
        );

        $modality = $task->modality();

        $primary = AiModel::query()->firstOrCreate(
            ['provider_id' => $provider->getKey(), 'code' => 'fake-primary-'.$modality->value],
            [
                'name' => 'Fake birincil',
                'modality' => $modality,
                'max_output_tokens' => 1_000,
                'supports_structured_output' => true,
                'supports_image_input' => true,
                'is_active' => true,
            ],
        );

        $fallback = null;

        if ($withFallback) {
            $fallback = AiModel::query()->firstOrCreate(
                ['provider_id' => $provider->getKey(), 'code' => 'fake-fallback-'.$modality->value],
                [
                    'name' => 'Fake yedek',
                    'modality' => $modality,
                    'max_output_tokens' => 1_000,
                    'supports_structured_output' => true,
                    'supports_image_input' => true,
                    'is_active' => true,
                ],
            );
        }

        $route = AiTaskRoute::query()->updateOrCreate(
            ['task' => $task->value],
            [
                'primary_model_id' => $primary->getKey(),
                'fallback_model_id' => $fallback?->getKey(),
                'timeout_seconds' => 30,
                'max_attempts' => 2,
                'credit_cost' => 1,
                'max_cost_micros' => 500_000,
                'max_concurrency' => 5,
                'is_active' => true,
                ...$attributes,
            ],
        );

        return [$route, $primary];
    }
}

if (! function_exists('makeAiJob')) {
    /**
     * A queued job, ready for the gateway to pick up.
     *
     * @param  array<string, mixed>  $input
     */
    function makeAiJob(AiTask $task, array $input = [], ?User $user = null): AiJob
    {
        return AiJob::query()->create([
            'task' => $task,
            'input' => $input === [] ? ['prompt' => 'Test istemi'] : $input,
            'user_id' => $user?->getKey(),
        ]);
    }
}

if (! function_exists('makeCategory')) {
    /** A category in the tree, with its materialised path. */
    function makeCategory(string $name, string $slug, ?string $roomType): Category
    {
        $category = Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'position' => 0,
            'is_active' => true,
            'room_type' => $roomType,
        ]);

        // The materialised path is maintained by the taxonomy service rather than mass
        // assigned; a root category is its own slug.
        $category->forceFill(['path' => $slug, 'depth' => 0])->save();

        return $category;
    }

}

if (! function_exists('makeProduct')) {
    /**
     * An approved, purchasable product with one offer.
     *
     * @param  array<string, mixed>  $attributes
     */
    function makeProduct(object $seller, Category $category, array $attributes): Product
    {
        $product = Product::query()->create([
            'organization_id' => $seller->organization_id,
            'primary_category_id' => $category->getKey(),
            'name' => $attributes['name'],
            'slug' => Str::slug($attributes['name']).'-'.Str::lower(Str::random(6)),
            'product_type' => 'simple',
            'description' => $attributes['description'] ?? null,
        ]);

        $product->forceFill([
            'status' => ProductStatus::Active,
            'moderation_status' => ModerationStatus::Approved,
            'published_at' => now(),
        ])->save();

        $sku = ProductSku::query()->create([
            'product_id' => $product->getKey(),
            'seller_id' => $seller->getKey(),
            'sku' => Str::upper(Str::random(10)),
            'currency' => 'TRY',
            'list_price_minor' => $attributes['price_minor'],
            'tax_rate_bps' => 2_000,
            'stock_policy' => StockPolicy::Track,
            'stock_quantity' => $attributes['stock_quantity'] ?? 10,
        ]);

        $sku->forceFill(['status' => SkuStatus::Active])->save();

        if (($attributes['width_mm'] ?? null) !== null) {
            ProductDimension::query()->create([
                'sku_id' => $sku->getKey(),
                'width_mm' => $attributes['width_mm'],
                'height_mm' => 850,
                'depth_mm' => 900,
            ]);
        }

        return $product->fresh(['skus.dimensions', 'primaryCategory']);
    }
}
