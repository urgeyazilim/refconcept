<?php

declare(strict_types=1);

use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Enums\BankTransferStatus;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The endpoints either side of a transfer: the customer's and finance's.
 *
 * Two things are worth testing here that the service tests cannot reach — that a reference
 * belonging to somebody else is a 404 rather than a 403, and that reading a payment and
 * settling one are genuinely separate grants.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    $this->operator = User::factory()->create();
    $this->operator->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->operator, SystemRole::Operator);

    $this->analyst = User::factory()->create();
    $this->analyst->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->analyst, SystemRole::Analyst);

    $this->account = PaymentBankAccount::query()->create([
        'bank_name' => 'Test Bankası',
        'account_holder' => 'RefConcept A.Ş.',
        'iban' => 'TR330006100519786457841326',
        'currency' => 'TRY',
        'note' => 'Açıklama alanına yalnızca referans kodunu yazın.',
    ]);

    $this->package = CreditPackage::query()->create([
        'code' => 'havale-http',
        'name' => 'Havale HTTP paketi',
        'credits' => 60,
        'price_minor' => 24_900,
        'currency' => 'TRY',
    ]);
});

/** Starts a credit checkout paid by transfer and returns its reference. */
function openTransferCheckout(): string
{
    test()->actingAs(test()->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => test()->package->getKey()])
        ->assertCreated();

    test()->actingAs(test()->customer)
        ->postJson('/api/v1/checkout/pay', [
            'purpose' => 'credits',
            'gateway' => 'bank_transfer',
            'bank_account_id' => test()->account->getKey(),
        ])
        ->assertCreated();

    return (string) BankTransfer::query()->latest('created_at')->value('reference');
}

it('publishes the accounts before anybody signs in', function (): void {
    // A customer deciding how to pay should be able to see the options first.
    $response = $this->getJson('/api/v1/bank-transfers/accounts')->assertOk();

    expect($response->json('data.0.iban'))->toContain('TR33')
        // Grouped in fours: the number is copied by eye into a phone, and an unbroken run
        // of twenty-six characters is how a digit gets dropped.
        ->and($response->json('data.0.iban'))->toContain(' ');
});

it('gives the customer their reference and where to send it', function (): void {
    $reference = openTransferCheckout();

    $response = $this->actingAs($this->customer)
        ->getJson('/api/v1/bank-transfers/'.$reference)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.status'))->toBe('awaiting_transfer')
        ->and($response->json('data.expected_minor'))->toBe(24_900)
        ->and($response->json('data.bank_account.iban'))->toContain('TR33')
        ->and($response->json('data.message'))->toContain('referans');
});

it('will not show one customer another reference', function (): void {
    $reference = openTransferCheckout();

    $stranger = User::factory()->create();
    $stranger->forceFill(['email_verified_at' => now()])->save();

    // 404, not 403: the reference is short and typable by design, which is exactly what
    // makes it guessable, and confirming that one exists is a gift to somebody guessing.
    $this->actingAs($stranger)
        ->getJson('/api/v1/bank-transfers/'.$reference)
        ->assertNotFound();
});

it('takes a receipt and moves the transfer into review', function (): void {
    Storage::fake('s3');

    $reference = openTransferCheckout();

    $response = $this->actingAs($this->customer)
        ->post('/api/v1/bank-transfers/'.$reference.'/receipts', [
            'file' => UploadedFile::fake()->create('dekont.pdf', 40, 'application/pdf'),
        ])
        ->assertCreated();

    /*
     * Under review, not confirmed. A receipt is a picture, and pictures are easy to make;
     * nothing is released until somebody has seen the money in a statement.
     */
    expect($response->json('data.status'))->toBe('under_review')
        ->and($response->json('data.receipt_count'))->toBe(1);

    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    // The path never appears in a response — it is a private file reached only through a
    // signed link after a check.
    expect($response->json('data'))->not->toHaveKey('storage_path')
        ->and($transfer->receipts()->first()?->storage_path)->toContain('payment-receipts/');
});

it('refuses a file that is not a receipt', function (): void {
    Storage::fake('s3');

    $reference = openTransferCheckout();

    $this->actingAs($this->customer)
        ->post('/api/v1/bank-transfers/'.$reference.'/receipts', [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])
        ->assertStatus(422);
});

// --- finance ------------------------------------------------------------------

it('shows finance the queue and lets them settle it', function (): void {
    $reference = openTransferCheckout();
    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    $queue = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/payments/transfers')
        ->assertOk();

    expect($queue->json('data.0.reference'))->toBe($reference)
        ->and($queue->json('data.0.is_decidable'))->toBeTrue();

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', [
            'received_minor' => 24_900,
            'value_date' => '2026-08-25',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    // Confirming released the credits, through the same fulfilment path a card uses.
    expect(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(60);
});

it('blocks a second confirmation', function (): void {
    $reference = openTransferCheckout();
    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    $body = ['received_minor' => 24_900, 'value_date' => '2026-08-25'];

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', $body)
        ->assertOk();

    // Two operators, two stale screens. The second is told what happened rather than
    // allowed through to release an order twice.
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', $body)
        ->assertStatus(409);

    expect(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(60);
});

it('states a shortfall rather than rounding it away', function (): void {
    $reference = openTransferCheckout();
    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', [
            'received_minor' => 24_800,
            'value_date' => '2026-08-25',
        ])
        ->assertOk();

    expect($response->json('data.status'))->toBe(BankTransferStatus::ShortPaid->value)
        ->and($response->json('data.shortfall_minor'))->toBe(100)
        // A hundred kuruş short is still short: nothing is released.
        ->and(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(0);
});

it('demands a reason before refusing a transfer', function (): void {
    $reference = openTransferCheckout();
    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/reject', [])
        ->assertStatus(422);

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/reject', [
            'reason' => 'Ekstrede eşleşen bir kayıt yok.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('lets an analyst read a payment but not settle one', function (): void {
    $reference = openTransferCheckout();
    $transfer = BankTransfer::query()->where('reference', $reference)->firstOrFail();

    $this->actingAs($this->analyst)
        ->getJson('/api/v1/admin/payments/transfers')
        ->assertOk();

    /*
     * The split that matters: answering "did it arrive" is a support job, and deciding
     * that it did releases goods and cannot be undone.
     */
    $this->actingAs($this->analyst)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', [
            'received_minor' => 24_900,
            'value_date' => '2026-08-25',
        ])
        ->assertForbidden();
});

it('keeps customers out of finance entirely', function (): void {
    $this->actingAs($this->customer)
        ->getJson('/api/v1/admin/payments/transfers')
        ->assertForbidden();
});

it('checksum-validates a receiving account before saving it', function (): void {
    // A mistyped receiving IBAN sends every customer's money somewhere else, so it is
    // validated rather than merely length-checked.
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/payments/bank-accounts', [
            'bank_name' => 'Yanlış Banka',
            'account_holder' => 'RefConcept A.Ş.',
            'iban' => 'TR330006100519786457841327',
        ])
        ->assertStatus(422);
});
