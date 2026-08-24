<?php

declare(strict_types=1);

use App\Support\ValueObjects\Iban;

/**
 * The IBAN checksum is the last line of defence before a payout leaves the platform,
 * so it is tested against the mistakes people actually make: a mistyped digit, two
 * digits swapped, and a truncated value.
 */
it('accepts a valid Turkish IBAN', function (): void {
    $iban = Iban::fromString('TR330006100519786457841326');

    expect($iban->value())->toBe('TR330006100519786457841326')
        ->and($iban->countryCode())->toBe('TR')
        ->and($iban->last4())->toBe('1326');
});

it('ignores spaces and hyphens, the way people paste them', function (): void {
    expect(Iban::fromString('TR33 0006 1005 1978 6457 8413 26')->value())
        ->toBe('TR330006100519786457841326')
        ->and(Iban::fromString('tr33-0006-1005-1978-6457-8413-26')->value())
        ->toBe('TR330006100519786457841326');
});

it('rejects a single mistyped digit', function (): void {
    // Last digit 6 -> 7. Structurally perfect, checksum wrong.
    expect(Iban::isValid('TR330006100519786457841327'))->toBeFalse();
});

it('rejects two transposed digits', function (): void {
    // 13|26 -> 12|36 at the end: the classic typing error a length check misses.
    expect(Iban::isValid('TR330006100519786457841236'))->toBeFalse();
});

it('rejects a truncated value', function (): void {
    expect(Iban::isValid('TR3300061005197864'))->toBeFalse();
});

it('rejects a value that does not start with a country code', function (): void {
    expect(Iban::isValid('1234567890123456789012345'))->toBeFalse();
});

it('throws with a message a user can act on', function (): void {
    expect(fn () => Iban::fromString('TR330006100519786457841327'))
        ->toThrow(InvalidArgumentException::class, 'IBAN doğrulama basamağı hatalı.');
});

it('masks everything but the last four digits', function (): void {
    expect(Iban::fromString('TR330006100519786457841326')->masked())
        ->toBe('**** **** **** 1326');
});

it('formats in groups of four the way banks print it', function (): void {
    expect(Iban::fromString('TR330006100519786457841326')->formatted())
        ->toBe('TR33 0006 1005 1978 6457 8413 26');
});

it('produces a stable fingerprint for the same account written differently', function (): void {
    $spaced = Iban::fromString('TR33 0006 1005 1978 6457 8413 26');
    $plain = Iban::fromString('TR330006100519786457841326');

    // Duplicate detection has to survive formatting differences.
    expect($spaced->fingerprint())->toBe($plain->fingerprint())
        ->and($spaced->equals($plain))->toBeTrue();
});

it('does not leak the account number through the fingerprint', function (): void {
    $iban = Iban::fromString('TR330006100519786457841326');

    expect($iban->fingerprint())->not->toContain('786457841326')
        ->and(strlen($iban->fingerprint()))->toBe(64);
});

it('accepts valid IBANs from other countries', function (string $candidate): void {
    expect(Iban::isValid($candidate))->toBeTrue();
})->with([
    'DE89370400440532013000',
    'GB82WEST12345698765432',
    'NL91ABNA0417164300',
]);
