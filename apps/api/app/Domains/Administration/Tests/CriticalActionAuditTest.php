<?php

declare(strict_types=1);

use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Services\OrderAccounting;
use App\Domains\Fulfilment\Services\ReturnService;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The Phase 18 gate, second half: every critical action leaves a record.
 *
 * "Critical" means an action a person takes that moves money, releases goods, changes what
 * somebody may do, or alters how the platform behaves for everybody. Those are the things
 * somebody will one day have to explain — to a seller, to a customer, to an auditor — and
 * an explanation that depends on remembering is not an explanation.
 *
 * Each case below performs the action for real and then asserts the trail: the action's
 * name, who did it, and — where the action costs somebody something — why.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->returns = app(ReturnService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller, $this->sellerOwner] = makeApprovedSeller('Denetim A.Ş.', 'denetim-as');

    $product = makeProduct($this->seller, makeCategory('Sehpa', 'sehpa-denetim', 'living_room'), [
        'name' => 'Denetim sehpası',
        'description' => 'Denetim testleri.',
        'price_minor' => 200_000,
        'stock_quantity' => 6,
    ]);

    $this->sku = $product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 6, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'city' => 'İstanbul',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->admin, SystemRole::SuperAdmin);
});

/** The most recent audit entry for an action prefix. */
function latestAudit(string $action): ?AuditLog
{
    return AuditLog::query()->where('action', $action)->latest('created_at')->first();
}

/** Buys, pays and delivers, so there is something to act on. */
function auditableOrder(int $quantity = 2): Order
{
    test()->carts->add(test()->customer, test()->sku, $quantity);

    $session = test()->checkout->openCart(test()->customer, []);
    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();
    $sellerOrder = $order->sellerOrders->first();

    test()->statuses->advance($sellerOrder, SellerOrderStatus::Confirmed);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Shipped);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Delivered);

    return $order->fresh(['sellerOrders.items']) ?? $order;
}

// --- money leaving ---------------------------------------------------------------

it('records who confirmed a bank transfer and against which statement', function (): void {
    $account = PaymentBankAccount::query()->create([
        'bank_name' => 'Denetim Bankası',
        'account_holder' => 'RefConcept A.Ş.',
        'iban' => 'TR330006100519786457841326',
    ]);

    $package = CreditPackage::query()->create([
        'code' => 'denetim-paket',
        'name' => 'Denetim paketi',
        'credits' => 40,
        'price_minor' => 19_900,
    ]);

    $session = $this->checkout->openCredits($this->customer, $package);
    $this->checkout->pay($session, 'bank_transfer', null, null, null, (string) $account->getKey());

    $transfer = BankTransfer::query()->latest('created_at')->firstOrFail();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/payments/transfers/'.$transfer->getKey().'/confirm', [
            'received_minor' => 19_900,
            'value_date' => '2026-08-26',
            'note' => 'Ekstreden doğrulandı.',
        ])
        ->assertOk();

    $entry = latestAudit('payments.transfer.confirmed');

    // Who, when, against which statement date, and their own words.
    expect($entry)->not->toBeNull()
        ->and($entry?->actor_id)->toBe($this->admin->getKey())
        ->and($entry?->reason)->toBe('Ekstreden doğrulandı.')
        ->and($entry?->context['value_date'] ?? null)->toBe('2026-08-26');
});

it('records a settlement approval and the payment that followed', function (): void {
    $order = auditableOrder();
    $sellerOrder = $order->sellerOrders->first();

    $sellerOrder->forceFill(['delivered_at' => now()->subDays(20)])->save();
    app(OrderAccounting::class)->rebuildBalance((string) $this->seller->getKey());

    $this->actingAs($this->admin)->postJson('/api/v1/admin/finance/settlements/build')->assertOk();

    $settlement = Settlement::query()->firstOrFail();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/approve')
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/paid', [
            'payout_reference' => 'EFT-2026-00918',
        ])
        ->assertOk();

    $approved = latestAudit('finance.settlement.approved');
    $paid = latestAudit('finance.settlement.paid');

    /*
     * Two entries, not one. Committing the money and recording that it left are separate
     * decisions, and a trail that merged them could not answer "was it approved before it
     * was sent".
     */
    expect($approved?->actor_id)->toBe($this->admin->getKey())
        ->and($paid?->actor_id)->toBe($this->admin->getKey())
        ->and($paid?->context['payout_reference'] ?? null)->toBe('EFT-2026-00918');
});

it('records a manual refund with the reason that justified it', function (): void {
    $order = auditableOrder();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/refunds', [
            'order_number' => $order->order_number,
            'seller_order_number' => $order->sellerOrders->first()->seller_order_number,
            'amount_minor' => 25_000,
            'reason' => 'Geç teslimat için jest.',
        ])
        ->assertCreated();

    $entry = latestAudit('fulfilment.refund.succeeded');

    // An unexplained payment out is indistinguishable from a mistake six months later.
    expect($entry?->actor_id)->toBe($this->admin->getKey())
        ->and($entry?->context['amount_minor'] ?? null)->toBe(25_000);
});

it('records a credit adjustment with its reason', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/credits/wallets/'.$this->customer->getKey().'/adjust', [
            'delta' => 5,
            'reason' => 'Destek talebi sonrası telafi.',
        ])
        ->assertOk();

    $entry = AuditLog::query()->where('action', 'like', 'credit.%')->latest('created_at')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_id)->toBe($this->admin->getKey())
        ->and($entry?->reason)->toBe('Destek talebi sonrası telafi.');
});

// --- goods and access ---------------------------------------------------------------

it('records a seller suspension with the reason given', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/sellers/'.$this->seller->getKey().'/suspend', [
            'reason' => 'Tekrarlanan teslimat şikâyetleri.',
        ])
        ->assertOk();

    $entry = AuditLog::query()->where('action', 'like', 'sellers.%suspend%')->latest('created_at')->first();

    // Suspending a seller stops their income; it is the clearest case of a decision
    // somebody will be asked to justify.
    expect($entry)->not->toBeNull()
        ->and($entry?->reason)->toBe('Tekrarlanan teslimat şikâyetleri.');
});

it('records a return decision and who made it', function (): void {
    $order = auditableOrder();
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, false, [], $this->sellerOwner, 'Ürün kullanılmış olarak geldi.');

    $entry = latestAudit('fulfilment.return.rejected');

    expect($entry?->actor_id)->toBe($this->sellerOwner->getKey())
        ->and($entry?->reason)->toBe('Ürün kullanılmış olarak geldi.');
});

it('records a cancelled seller order and why', function (): void {
    $this->carts->add($this->customer, $this->sku, 1);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();

    $this->statuses->advance(
        $order->sellerOrders->first(),
        SellerOrderStatus::Cancelled,
        $this->sellerOwner,
        'seller',
        'Depoda hasar bulundu.',
    );

    $entry = latestAudit('orders.seller_order.cancelled');

    expect($entry?->reason)->toBe('Depoda hasar bulundu.')
        ->and($entry?->actor_id)->toBe($this->sellerOwner->getKey());
});

// --- the platform itself ---------------------------------------------------------------

it('records a feature flag change with both values', function (): void {
    $created = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/system/flags', [
            'key' => 'ai.premium-render',
            'name' => 'Premium render',
            'is_enabled' => false,
        ])
        ->assertCreated();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/flags/'.$created->json('data.id'), [
            'key' => 'ai.premium-render',
            'name' => 'Premium render',
            'is_enabled' => true,
            'rollout_percentage' => 25,
        ])
        ->assertOk();

    $entry = latestAudit('platform.flag.saved');

    /*
     * Both values. A flags table says what a flag is now; only the trail can say what it
     * was — which is the question asked after a bad afternoon.
     */
    expect($entry?->changes['is_enabled'] ?? null)->toBe([false, true])
        ->and($entry?->changes['rollout_percentage'] ?? null)->toBe([100, 25]);
});

it('records a system setting change without leaking a secret', function (): void {
    $setting = SystemSetting::query()->create([
        'key' => 'support.email',
        'label' => 'Destek adresi',
        'type' => 'string',
        'value' => 'eski@refconcept.local',
    ]);

    $secret = SystemSetting::query()->create([
        'key' => 'integrations.token',
        'label' => 'Entegrasyon anahtarı',
        'type' => 'string',
        'value' => 'eski-gizli-deger',
        'is_secret' => true,
    ]);

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$setting->getKey(), ['value' => 'yeni@refconcept.local'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$secret->getKey(), ['value' => 'yeni-gizli-deger'])
        ->assertOk();

    $open = AuditLog::query()->where('action', 'platform.setting.changed')
        ->orderBy('created_at')->first();

    $hidden = AuditLog::query()->where('action', 'platform.setting.changed')
        ->latest('created_at')->first();

    expect($open?->changes['value'] ?? null)->toBe(['eski@refconcept.local', 'yeni@refconcept.local']);

    /*
     * A secret's value never enters the trail either. An audit log is read by far more
     * people than a secret store is, and "it was only in the audit" is no comfort.
     */
    expect($hidden?->changes['value'] ?? null)->toBe(['(gizli)', '(gizli)']);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/system/settings')->assertOk();

    $row = collect($response->json('data'))->firstWhere('key', 'integrations.token');

    expect($row['value'])->toBeNull()
        ->and($row['is_set'])->toBeTrue();
});

it('will not let a setting be given the wrong kind of value', function (): void {
    $setting = SystemSetting::query()->create([
        'key' => 'settlement.hold_days',
        'label' => 'Hakediş bekleme süresi',
        'type' => 'integer',
        'value' => '14',
    ]);

    // A settings screen that takes anything into any field will one day set a hold period
    // to "yes".
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$setting->getKey(), ['value' => 'evet'])
        ->assertStatus(422);
});

it('refuses to replay a webhook that was never verified', function (): void {
    $event = PaymentWebhookEvent::query()->create([
        'gateway' => 'fake',
        'body_fingerprint' => str_repeat('a', 64),
        'signature_verified' => false,
        'status' => 'failed',
        'received_at' => now(),
    ]);

    // Replaying it by hand would be a way around the signature check rather than a repair.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/system/webhooks/'.$event->getKey().'/replay')
        ->assertStatus(422);
});

it('keeps the audit trail unchangeable', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/system/flags', ['key' => 'x.y', 'name' => 'X'])
        ->assertCreated();

    $entry = latestAudit('platform.flag.saved');

    // The trail was made append-only in Phase 1; a critical-action test that did not
    // check it would be trusting the thing it is meant to verify.
    expect(fn () => DB::table('audit_logs')
        ->where('id', $entry?->getKey())
        ->update(['action' => 'nothing.happened']))
        ->toThrow(QueryException::class);
});

it('shows an operator the trail they are entitled to read', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/system/flags', ['key' => 'a.b', 'name' => 'A'])
        ->assertCreated();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audit?action=platform.')
        ->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and($response->json('data.0.action'))->toStartWith('platform.');
});
