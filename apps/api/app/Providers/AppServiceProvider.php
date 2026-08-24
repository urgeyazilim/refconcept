<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Actions\AuthenticateUser;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Identity\Policies\UserAddressPolicy;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Identity\Services\EmailVerificationService;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Policies\OrganizationPolicy;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Policies\ProductPolicy;
use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Policies\SellerApplicationPolicy;
use App\Domains\Sellers\Policies\SellerPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Permission lookups are memoised per request, so the service must be a
        // singleton — a fresh instance per injection would defeat the cache.
        $this->app->singleton(AccessControl::class);

        // Lifetimes are configuration, not constants buried in constructors.
        $this->app->bind(EmailVerificationService::class, fn (): EmailVerificationService => new EmailVerificationService(
            (int) config('refconcept.security.email_verification.ttl_minutes', 1440),
        ));

        $this->app->bind(PasswordResetService::class, fn (): PasswordResetService => new PasswordResetService(
            (int) config('refconcept.security.password_reset.ttl_minutes', 60),
        ));

        $this->app->bind(AuthenticateUser::class, fn (): AuthenticateUser => new AuthenticateUser(
            (int) config('refconcept.security.tokens.ttl_days', 30),
        ));
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureSanctum();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Named limiters used by the auth routes.
     *
     * Credential endpoints are keyed by e-mail **and** IP together. Keying by e-mail
     * alone would let an attacker lock a victim out by failing their login on purpose;
     * keying by IP alone would let a botnet spread attempts across addresses.
     */
    private function configureRateLimiting(): void
    {
        $limits = (array) config('refconcept.security.rate_limits', []);

        RateLimiter::for('auth-login', fn (Request $request): Limit => Limit::perMinute(
            (int) ($limits['login'] ?? 5)
        )->by($this->credentialKey($request)));

        RateLimiter::for('auth-register', fn (Request $request): Limit => Limit::perMinute(
            (int) ($limits['register'] ?? 5)
        )->by((string) $request->ip()));

        RateLimiter::for('auth-password-reset', fn (Request $request): Limit => Limit::perMinute(
            (int) ($limits['password_reset'] ?? 3)
        )->by($this->credentialKey($request)));

        RateLimiter::for('auth-verification-resend', fn (Request $request): Limit => Limit::perMinute(
            (int) ($limits['verification_resend'] ?? 3)
        )->by((string) ($request->user()?->getKey() ?? $request->ip())));
    }

    private function credentialKey(Request $request): string
    {
        $email = mb_strtolower((string) $request->input('email'));

        return sha1($email.'|'.$request->ip());
    }

    private function configureModels(): void
    {
        /*
         * Strict mode outside production turns three classes of silent bug into
         * immediate failures: lazy-loading a relation inside a loop, assigning an
         * attribute that does not exist, and reading one that was never selected.
         * Left off in production so a missed case degrades rather than 500s.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        // Anything mass-assignable must be listed explicitly on the model.
        Model::unguard(false);

        /*
         * Models live under app/Domains/<Domain>/Models, so Laravel's default guess
         * (Database\Factories\Domains\Identity\Models\UserFactory) finds nothing.
         * Factories stay in one flat namespace keyed by class name instead.
         */
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    private function configureSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureAuthorization(): void
    {
        /*
         * Policies are registered explicitly: models live under app/Domains/* rather
         * than app/Models, so Laravel's naming convention would silently find none —
         * and a policy that is never consulted is an authorization hole that no test
         * of the policy class itself would catch.
         */
        Gate::policy(UserAddress::class, UserAddressPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(SellerApplication::class, SellerApplicationPolicy::class);
        Gate::policy(Seller::class, SellerPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);

        /*
         * Super admin bypass. Returning null (not false) lets every other check run
         * normally; returning true short-circuits. Deliberately the only blanket
         * override in the system.
         */
        Gate::before(function (User $user, string $ability): ?bool {
            return app(AccessControl::class)->isSuperAdmin($user) ? true : null;
        });
    }
}
