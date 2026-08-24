<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Domains\Sellers\Models\SellerAgreement;
use App\Domains\Sellers\Models\SellerAgreementAcceptance;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerBankAccount;
use App\Domains\Sellers\Models\SellerStatusHistory;
use Database\Seeders\SellerAgreementsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(SellerAgreementsSeeder::class);

    $this->applicant = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function applicationPayload(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Atlas Mobilya A.Ş.',
        'display_name' => 'Atlas Mobilya',
        'legal_form' => 'anonim_sirket',
        'contact_email' => 'satis@atlas.example',
        'contact_phone' => '+905551112233',
    ], $overrides);
}

it('creates a draft application', function (): void {
    $response = $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application', applicationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.status', ApplicationStatus::Draft->value)
        ->assertJsonPath('data.is_editable', true);

    expect(SellerApplication::query()->where('applicant_user_id', $this->applicant->getKey())->count())->toBe(1);
});

it('refuses a second open application from the same applicant', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload())->assertCreated();

    // Two live applications make "which one did we approve" unanswerable.
    $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application', applicationPayload())
        ->assertStatus(422);
});

it('requires a verified e-mail before applying', function (): void {
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->postJson('/api/v1/seller/application', applicationPayload())
        ->assertForbidden();
});

it('reports an empty checklist for a new application', function (): void {
    $response = $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application', applicationPayload())
        ->assertCreated();

    expect($response->json('meta.can_submit'))->toBeFalse()
        ->and($response->json('meta.completion_percent'))->toBeLessThan(100);

    $steps = collect($response->json('meta.checklist'));

    // Company details are satisfied by the creation payload itself; nothing else is.
    expect($steps->firstWhere('step', 'company')['completed'])->toBeTrue()
        ->and($steps->firstWhere('step', 'bank_account')['completed'])->toBeFalse()
        ->and($steps->firstWhere('step', 'agreements')['completed'])->toBeFalse();
});

it('saves the legal entity section', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/legal-entity', [
            'legal_name' => 'Atlas Mobilya Anonim Şirketi',
            'tax_office' => 'Kadıköy',
            'tax_number' => '1234567890',
        ])
        ->assertOk()
        ->assertJsonPath('data.legal_entity.tax_number', '1234567890');
});

it('rejects a tax number that is not ten digits', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/legal-entity', [
            'legal_name' => 'Atlas',
            'tax_number' => '123',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_number');
});

it('stores the IBAN encrypted and returns only the masked value', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $response = $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/bank-account', [
            'account_holder' => 'Atlas Mobilya A.Ş.',
            'bank_name' => 'Demo Bank',
            'iban' => 'TR33 0006 1005 1978 6457 8413 26',
        ])
        ->assertOk();

    expect($response->json('data.bank_accounts.0.iban_masked'))->toBe('**** **** **** 1326');

    // The plaintext must not appear anywhere in the response.
    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('TR330006100519786457841326');

    // Nor may the raw column hold it.
    $stored = SellerBankAccount::query()->firstOrFail();
    $raw = DB::table('seller_bank_accounts')
        ->where('id', $stored->getKey())
        ->value('iban_encrypted');

    expect($raw)->not->toContain('TR330006100519786457841326')
        ->and($stored->iban()?->value())->toBe('TR330006100519786457841326');
});

it('rejects an IBAN that fails the checksum', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    // One digit transposed from a valid IBAN — exactly the mistake people make typing one.
    $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/bank-account', [
            'account_holder' => 'Atlas',
            'iban' => 'TR330006100519786457841327',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('iban');
});

it('refuses to submit an incomplete application and says what is missing', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $response = $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application/submit')
        ->assertStatus(422);

    expect($response->json('errors.status.0'))->toContain('Eksik adımlar');

    expect(SellerApplication::query()->firstOrFail()->status)->toBe(ApplicationStatus::Draft);
});

it('submits a complete application', function (): void {
    $application = SellerApplication::factory()
        ->complete()
        ->create(['applicant_user_id' => $this->applicant->getKey()]);

    $response = $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application/submit')
        ->assertOk();

    expect($response->json('data.status'))->toBe(ApplicationStatus::Submitted->value)
        ->and($application->fresh()->submitted_at)->not->toBeNull();
});

it('locks the application once submitted', function (): void {
    SellerApplication::factory()
        ->complete()
        ->create(['applicant_user_id' => $this->applicant->getKey()]);

    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application/submit')->assertOk();

    // Editing after submission would change what the reviewer is looking at.
    $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/legal-entity', [
            'legal_name' => 'Değiştirilmiş Unvan',
            'tax_number' => '9999999999',
        ])
        ->assertForbidden();
});

it('records status history for every transition', function (): void {
    SellerApplication::factory()
        ->complete()
        ->create(['applicant_user_id' => $this->applicant->getKey()]);

    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application/submit')->assertOk();

    $history = SellerStatusHistory::query()->get();

    expect($history)->toHaveCount(1)
        ->and($history->first()->from_status)->toBe(ApplicationStatus::Draft->value)
        ->and($history->first()->to_status)->toBe(ApplicationStatus::Submitted->value)
        ->and($history->first()->changed_by)->toBe($this->applicant->getKey());
});

it('accepts an agreement and records the text checksum', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $agreement = SellerAgreement::query()->firstOrFail();

    $this->actingAs($this->applicant)
        ->postJson("/api/v1/seller/agreements/{$agreement->getKey()}/accept")
        ->assertCreated();

    $acceptance = SellerAgreementAcceptance::query()->firstOrFail();

    // The checksum is what proves which text was on screen if the row is disputed.
    expect($acceptance->body_checksum)->toBe(hash('sha256', $agreement->body))
        ->and($acceptance->accepted_by)->toBe($this->applicant->getKey());
});

it('treats a repeated acceptance as a no-op rather than an error', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());
    $agreement = SellerAgreement::query()->firstOrFail();

    $this->actingAs($this->applicant)->postJson("/api/v1/seller/agreements/{$agreement->getKey()}/accept")->assertCreated();

    // A double-clicked button must not produce a 500 against an immutable table.
    $this->actingAs($this->applicant)->postJson("/api/v1/seller/agreements/{$agreement->getKey()}/accept")->assertOk();

    expect(SellerAgreementAcceptance::query()->count())->toBe(1);
});

it('refuses to modify an acceptance once recorded', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());
    $agreement = SellerAgreement::query()->firstOrFail();
    $this->actingAs($this->applicant)->postJson("/api/v1/seller/agreements/{$agreement->getKey()}/accept");

    $acceptance = SellerAgreementAcceptance::query()->firstOrFail();

    expect(fn () => $acceptance->update(['ip_address' => '1.2.3.4']))
        ->toThrow(RuntimeException::class);
});

it('lets the applicant withdraw a draft', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $this->actingAs($this->applicant)
        ->postJson('/api/v1/seller/application/withdraw')
        ->assertOk();

    expect(SellerApplication::query()->firstOrFail()->status)->toBe(ApplicationStatus::Withdrawn);
});

it('requires a national id rather than a tax number for an individual seller', function (): void {
    $this->actingAs($this->applicant)->postJson('/api/v1/seller/application', applicationPayload());

    $this->actingAs($this->applicant)->putJson('/api/v1/seller/application/sections/tax-profile', [
        'taxpayer_type' => 'individual',
        'default_vat_rate_bps' => 2000,
    ])->assertOk();

    $response = $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/legal-entity', [
            'legal_name' => 'Bireysel Satıcı',
            'tax_number' => '1234567890',
        ])
        ->assertOk();

    $steps = collect($response->json('meta.checklist'));

    // A company number does not satisfy an individual's identity requirement.
    expect($steps->firstWhere('step', 'legal_entity')['completed'])->toBeFalse();

    $response = $this->actingAs($this->applicant)
        ->putJson('/api/v1/seller/application/sections/legal-entity', [
            'legal_name' => 'Bireysel Satıcı',
            'national_id' => '12345678901',
        ])
        ->assertOk();

    expect(collect($response->json('meta.checklist'))->firstWhere('step', 'legal_entity')['completed'])->toBeTrue();
});
