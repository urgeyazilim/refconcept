<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerBankAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The Phase 21 gate, first half: the security rules the platform claims are actually true.
 *
 * Every case here is a rule from `06_SECURITY_PAYMENT_FINANCE_RULES.md` written as a
 * property rather than as a review note. A rule that lives only in a document is a rule
 * somebody breaks in a hurry six months from now with nothing to stop them — and the
 * breakage is invisible until it is exploited.
 *
 * The searches are deliberately over the source tree rather than over behaviour. "No card
 * number ever reaches our code" cannot be proved by calling an endpoint; it can be proved
 * by there being nowhere for one to go.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Every PHP file under app/, so a rule can be asserted about the codebase itself.
 *
 * @return list<string>
 */
function sourceFiles(string $subPath = ''): array
{
    $root = base_path('app'.($subPath === '' ? '' : '/'.$subPath));

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// --- card data --------------------------------------------------------------------

it('has nowhere for a card number to be stored', function (): void {
    /*
     * The rule is absolute: PAN, CVV and expiry never enter the codebase. Not encrypted,
     * not "temporarily", not in a variable. A tokenising gateway means we never hold them,
     * and the way to keep that true is for there to be no column and no field named for
     * one.
     */
    $forbidden = ['card_number', 'pan', 'cvv', 'cvc', 'card_cvv', 'expiry_month', 'expiry_year'];

    $offenders = [];

    foreach (sourceFiles() as $path) {
        // The tests themselves may name these words — this file does.
        if (str_contains($path, DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $contents = file_get_contents($path) ?: '';

        foreach ($forbidden as $needle) {
            if (preg_match('/[\'"]'.preg_quote($needle, '/').'[\'"]\s*=>/', $contents) === 1) {
                $offenders[] = basename($path).' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([], 'kart verisi alanı bulundu: '.implode(', ', $offenders));
});

it('has no migration that creates a column for card data', function (): void {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
        $contents = file_get_contents($path) ?: '';

        foreach (['card_number', 'cvv', 'cvc', 'expiry_month', 'expiry_year'] as $needle) {
            if (str_contains($contents, "'".$needle."'")) {
                $offenders[] = basename($path).' → '.$needle;
            }
        }
    }

    // A column is worse than a variable: a variable is gone at the end of the request.
    expect($offenders)->toBe([], 'kart verisi kolonu bulundu: '.implode(', ', $offenders));
});

// --- privilege escalation ---------------------------------------------------------

it('exposes no HTTP route that grants a platform role', function (): void {
    $suspicious = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (preg_match('#(roles?|permissions?)(/|$)#i', $uri) === 1
            && ! in_array('GET', $route->methods(), true)) {
            $suspicious[] = implode('|', $route->methods()).' '.$uri;
        }
    }

    /*
     * The ability to make somebody a super admin is not something a compromised session
     * should have, however well guarded the endpoint is. Roles are granted by console
     * command only, and this test is what keeps that true when somebody adds a
     * "user management" screen in a hurry.
     */
    expect($suspicious)->toBe([], 'rol veren uç bulundu: '.implode(', ', $suspicious));
});

it('keeps the super admin bypass away from a customer private work', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($superAdmin, SystemRole::SuperAdmin);

    $customer = User::factory()->create();
    $customer->forceFill(['email_verified_at' => now()])->save();

    $project = Project::query()->create([
        'user_id' => $customer->getKey(),
        'name' => 'Gizli ev projesi',
    ]);

    /*
     * The one place the bypass must not reach.
     *
     * A super admin can do anything operational — refund, suspend, settle — and none of
     * that is a reason to read somebody's home, their room photographs or the designs they
     * are still deciding about. Support that needs a customer's project asks the customer.
     */
    expect($superAdmin->can('view', $project))->toBeFalse()
        ->and($superAdmin->can('update', $project))->toBeFalse();
});

// --- what a response may carry ----------------------------------------------------

it('never returns a plaintext IBAN', function (): void {
    $owner = User::factory()->create();
    $owner->forceFill(['email_verified_at' => now()])->save();

    $iban = 'TR330006100519786457841326';

    $application = SellerApplication::query()->create([
        'applicant_user_id' => $owner->getKey(),
        'company_name' => 'Gizlilik Mobilya A.Ş.',
        'display_name' => 'Gizlilik Mobilya',
        'legal_form' => 'anonim_sirket',
        'contact_email' => $owner->email,
        'contact_phone' => '+905551112233',
    ]);

    $this->actingAs($owner)
        ->putJson('/api/v1/seller/application/sections/bank-account', [
            'account_holder' => 'Gizlilik Mobilya A.Ş.',
            'bank_name' => 'Demo Bank',
            'iban' => $iban,
        ])
        ->assertOk();

    $response = $this->actingAs($owner)->getJson('/api/v1/seller/application')->assertOk();

    /*
     * Encrypted at rest and never echoed back — only the last four. A payout account is
     * the one field on a seller's file whose disclosure costs them money rather than
     * privacy, and a screen that redisplays it has published it to everybody who can
     * open that screen.
     */
    expect($response->content())->not->toContain($iban)
        ->and($response->content())->toContain('1326');

    // And not in the database either.
    expect(SellerBankAccount::query()->first()?->getRawOriginal('iban'))
        ->not->toBe($iban);

    unset($application);
});

it('sends the headers that stop a page from being framed or sniffed', function (): void {
    $response = $this->getJson('/api/v1/catalog/products');

    /*
     * A JSON API is not a page, but it is reachable from a browser — and an API that can
     * be framed or content-sniffed is an API that can be used against the person signed
     * into it.
     */
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('never lets a private response be cached by a proxy', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($user, SystemRole::SuperAdmin);

    // Somebody else's figures arriving from a shared cache would be worse than a slow page.
    foreach (['/api/v1/admin/analytics/overview', '/api/v1/admin/audit', '/api/v1/admin/orders'] as $path) {
        $this->actingAs($user)
            ->getJson($path)
            ->assertHeader('Cache-Control', 'no-store, private');
    }
});

// --- the audit trail --------------------------------------------------------------

it('refuses to let anybody edit or delete the audit trail', function (): void {
    $user = User::factory()->create();

    AuditLog::query()->create([
        'actor_id' => $user->getKey(),
        'action' => 'test.readiness',
        'auditable_type' => User::class,
        'auditable_id' => $user->getKey(),
    ]);

    /*
     * Enforced by a database trigger rather than by a model. A record that the application
     * can rewrite is a record that says whatever the last person to touch it wanted it to
     * say — including the person the record is about.
     */
    expect(fn () => DB::statement(
        "UPDATE audit_logs SET action = 'tampered'",
    ))->toThrow(QueryException::class);

    expect(fn () => DB::statement('DELETE FROM audit_logs'))
        ->toThrow(QueryException::class);
});

it('keeps every append-only table append-only', function (): void {
    /*
     * The full list from 08_DATABASE_AND_DOMAIN_RULES.md. Checked as a set rather than one
     * at a time, because the failure mode is a table added to the list in the document and
     * not in the database.
     */
    $tables = [
        'audit_logs', 'price_history', 'stock_movements', 'prompt_versions',
        'credit_transactions', 'payment_transactions', 'order_status_history',
        'ledger_entries', 'ledger_lines',
    ];

    $unprotected = [];

    foreach ($tables as $table) {
        $triggers = DB::select(
            'SELECT trigger_name FROM information_schema.triggers WHERE event_object_table = ?',
            [$table],
        );

        if ($triggers === []) {
            $unprotected[] = $table;
        }
    }

    expect($unprotected)->toBe([], 'append-only koruması olmayan tablolar: '.implode(', ', $unprotected));
});

// --- secrets ----------------------------------------------------------------------

it('keeps every real credential out of the example environment file', function (): void {
    /*
     * The rule is "no production secrets in git", and it is worth being precise about what
     * that catches. The local stack's MinIO password is committed on purpose: it is the
     * password of a container listening on localhost, it has to match in two files for a
     * fresh checkout to work at all, and hiding it would break the bootstrap while
     * protecting nothing.
     *
     * What must never appear is a credential that means something *somewhere else* — a
     * provider key, a token, a signed blob. Those have recognisable shapes, and a file in
     * a repository is forever: rotating a key that has been cloned is the only fix, and
     * only if somebody notices.
     */
    $shapes = [
        'Google AI' => '/AIza[0-9A-Za-z_\-]{20,}/',
        'OpenAI' => '/\bsk-[A-Za-z0-9]{20,}/',
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'Stripe' => '/\b[sr]k_(live|test)_[A-Za-z0-9]{16,}/',
        'JWT' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\./',
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    $leaks = [];

    foreach ([base_path('.env.example'), base_path('../../.env.example')] as $path) {
        if (! is_file($path)) {
            continue;
        }

        $contents = file_get_contents($path) ?: '';

        foreach ($shapes as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $leaks[] = basename(dirname($path)).'/.env.example → '.$label;
            }
        }
    }

    expect($leaks)->toBe([], 'örnek dosyada gerçek kimlik bilgisi: '.implode(', ', $leaks));
});

it('keeps a real credential out of every committed file', function (): void {
    /*
     * The same shapes, over the source tree. The example file is the obvious place to leak
     * a key; a debugging line in a service is the likely one.
     */
    $shapes = [
        '/AIza[0-9A-Za-z_\-]{20,}/',
        '/\bsk-[A-Za-z0-9]{20,}/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    $offenders = [];

    foreach (sourceFiles() as $path) {
        $contents = file_get_contents($path) ?: '';

        foreach ($shapes as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = basename($path);
            }
        }
    }

    expect(array_unique($offenders))->toBe([], 'kaynak dosyada anahtar bulundu: '.implode(', ', $offenders));
});

it('never writes an AI provider key into a stored request', function (): void {
    $offenders = [];

    foreach (sourceFiles('Domains/Ai') as $path) {
        if (str_contains($path, DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $contents = file_get_contents($path) ?: '';

        // A logged request that carries its own Authorization header publishes the key to
        // everybody who can read the observability screen.
        if (preg_match('/[\'"]Authorization[\'"]\s*=>.*\$(request|payload|log)/i', $contents) === 1) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'sağlayıcı anahtarı loglanıyor olabilir: '.implode(', ', $offenders));
});
