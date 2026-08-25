<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiJobCredits;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Enums\ReservationStatus;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;

/**
 * Where the AI gateway meets the wallet.
 *
 * Phase 6 recorded what a job would cost and charged nothing; this is the loop closing.
 * The sequence being asserted is hold-then-settle, and the reason it is a hold rather
 * than a charge is the case in the middle of this file: a render that failed because a
 * provider timed out is our problem, not the customer's, and billing them for it is the
 * fastest way to lose them.
 */
beforeEach(function (): void {
    FakeAiProvider::reset();

    $this->ledger = app(CreditLedger::class);
    $this->dispatcher = app(AiJobDispatcher::class);

    $this->user = User::factory()->create();

    // Two credits per render, so a charge and a refund are distinguishable from a
    // balance that merely looks plausible.
    makeAiRoute(AiTask::ImageRenderDraft, ['credit_cost' => 2, 'max_attempts' => 1]);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('holds the cost when the job is queued, before anything runs', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    Queue::fake();

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    $wallet = $this->ledger->walletFor($this->user);

    /*
     * Nothing is spent yet — the balance is untouched and two credits are simply not
     * available to anything else. Charging up front would mean refunding every failure,
     * and a refund on a statement reads like an apology for something that went wrong
     * rather than like nothing having happened.
     */
    expect($wallet->balance)->toBe(10)
        ->and($wallet->reserved)->toBe(2)
        ->and($wallet->available())->toBe(8);

    $reservation = CreditReservation::query()->where('reference', 'ai-job:'.$job->getKey())->firstOrFail();

    expect($reservation->amount)->toBe(2)
        ->and($reservation->status)->toBe(ReservationStatus::Held)
        ->and($reservation->subject_id)->toBe($job->getKey());
});

it('turns the hold into a charge when the job succeeds', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    // The sync queue runs the job to completion, which is the path a real worker takes.
    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    $wallet = $this->ledger->walletFor($this->user);

    expect($job->fresh()?->status)->toBe(AiJobStatus::Succeeded)
        ->and($wallet->balance)->toBe(8)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(2)
        ->and($this->ledger->reconcile($wallet))->toBe(8);
});

it('gives the credits back when the provider fails', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    // Not retryable, so the job fails on its first and only attempt.
    FakeAiProvider::scriptFailure(AiFailureKind::InvalidRequest);

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    $wallet = $this->ledger->walletFor($this->user);

    expect($job->fresh()?->status)->toBe(AiJobStatus::Failed)
        // Whole balance intact, nothing held, nothing consumed. A failed render costs the
        // customer nothing at all.
        ->and($wallet->balance)->toBe(10)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(0);

    $reservation = CreditReservation::query()->where('reference', 'ai-job:'.$job->getKey())->firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Released);
});

it('charges once even when the gateway needed three attempts', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    makeAiRoute(AiTask::ImageRenderDraft, ['credit_cost' => 2, 'max_attempts' => 3]);

    FakeAiProvider::scriptFailure(AiFailureKind::Timeout);
    FakeAiProvider::scriptFailure(AiFailureKind::Timeout);

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    $wallet = $this->ledger->walletFor($this->user);

    /*
     * Three calls to a provider, one charge. A customer must not pay three times because
     * a provider was flaky — the retry is our decision and our cost.
     */
    expect($job->fresh()?->attempts)->toBe(3)
        ->and($wallet->balance)->toBe(8)
        ->and($wallet->lifetime_consumed)->toBe(2);
});

it('refuses to queue anything a customer cannot afford', function (): void {
    $this->ledger->grant($this->user, 1, CreditLotSource::Promotion, 'Hoş geldin');

    Queue::fake();

    /*
     * Refused at the door, not queued and failed. Somebody with one credit should be told
     * so while they are still looking at the button, not handed a job id and a failure
     * four seconds later.
     */
    expect(fn () => $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user))
        ->toThrow(InsufficientCredits::class);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(1)
        ->and($wallet->reserved)->toBe(0)
        // And no litter: the job row is removed rather than left in the customer's
        // history as something that never ran.
        ->and(AiJob::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('gives the credits back when a customer cancels', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    Queue::fake();

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    expect($this->ledger->walletFor($this->user)->reserved)->toBe(2);

    $this->dispatcher->cancel($job);

    $wallet = $this->ledger->walletFor($this->user);

    // Nothing ran, so nothing is owed.
    expect($wallet->balance)->toBe(10)
        ->and($wallet->reserved)->toBe(0);
});

it('gives the credits back when the worker dies mid-job', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    Queue::fake();

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    // A deploy that restarted the queue, or a fatal error. The gateway never wrote
    // anything, so without this the hold would sit there until the sweeper found it.
    $this->dispatcher->markCrashed($job->fresh(), new RuntimeException('worker killed'));

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(10)
        ->and($wallet->reserved)->toBe(0);
});

it('holds nothing for a task that costs nothing', function (): void {
    makeAiRoute(AiTask::ProductQueryRewrite, ['credit_cost' => 0]);

    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    $this->dispatcher->dispatch(AiTask::ProductQueryRewrite, ['prompt' => 'gri kanepe'], $this->user);

    $wallet = $this->ledger->walletFor($this->user);

    /*
     * A search-query rewrite is paid for out of the platform's budget, not a customer's
     * wallet. A reservation of zero would be a row that exists only to be released.
     */
    expect($wallet->balance)->toBe(10)
        ->and($wallet->reserved)->toBe(0)
        ->and(CreditReservation::query()->count())->toBe(0);
});

it('holds nothing when there is nobody to charge', function (): void {
    // An internal job — catalogue enrichment, a nightly re-tagging run — has no owner.
    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon']);

    expect($job->credit_cost)->toBe(2)
        ->and(CreditReservation::query()->count())->toBe(0);
});

it('settles a job exactly once however many times settle is called', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    $job = $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    // A duplicate queue delivery, which every driver produces eventually.
    app(AiJobCredits::class)->settle($job->fresh());
    app(AiJobCredits::class)->settle($job->fresh());

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(8)
        ->and($wallet->lifetime_consumed)->toBe(2);
});

it('shows the render on the customer statement in their own language', function (): void {
    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    $this->dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'salon'], $this->user);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/credits/transactions')
        ->assertOk();

    $entries = collect($response->json('data'));

    // "Görsel üretimi (taslak)", not a job id — and the hold does not appear at all.
    expect($entries->pluck('type')->all())->toBe(['consume', 'purchase'])
        ->and($entries->first()['description'])->toBe(AiTask::ImageRenderDraft->label())
        ->and($entries->first()['amount'])->toBe(-2);
});
