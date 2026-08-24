<?php

declare(strict_types=1);

namespace App\Support\ValueObjects;

use InvalidArgumentException;

/**
 * A validated IBAN.
 *
 * Constructed only from a value that passes the ISO 13616 mod-97 check, so anything
 * holding an `Iban` is holding a structurally valid account number. That matters
 * because this value is where money leaves the platform: a transposed digit becomes a
 * failed payout at best and somebody else's account at worst, and the check catches
 * exactly the single-character and transposition errors people actually make when
 * typing one in.
 */
final readonly class Iban
{
    /** Turkish IBANs are 26 characters; the ISO maximum across all countries is 34. */
    private const MAX_LENGTH = 34;

    private const MIN_LENGTH = 15;

    private function __construct(private string $normalised) {}

    /**
     * @throws InvalidArgumentException when the value is not a structurally valid IBAN
     */
    public static function fromString(string $input): self
    {
        $normalised = self::normalise($input);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $normalised)) {
            throw new InvalidArgumentException('IBAN formatı geçersiz.');
        }

        $length = strlen($normalised);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('IBAN uzunluğu geçersiz.');
        }

        if (! self::passesMod97($normalised)) {
            throw new InvalidArgumentException('IBAN doğrulama basamağı hatalı.');
        }

        return new self($normalised);
    }

    /** Rehydrates a value that was already validated before storage. */
    public static function fromStored(string $stored): self
    {
        return new self(self::normalise($stored));
    }

    public static function isValid(string $input): bool
    {
        try {
            self::fromString($input);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function value(): string
    {
        return $this->normalised;
    }

    public function countryCode(): string
    {
        return substr($this->normalised, 0, 2);
    }

    public function last4(): string
    {
        return substr($this->normalised, -4);
    }

    /**
     * A keyed hash used to spot the same account being registered twice.
     *
     * Keyed with the application key rather than a bare SHA-256: an unkeyed digest of
     * a value with this little entropy could be brute-forced back to the IBAN from a
     * leaked table, which would defeat encrypting it in the first place.
     */
    public function fingerprint(): string
    {
        return hash_hmac('sha256', $this->normalised, (string) config('app.key'));
    }

    /** Grouped in fours, the way banks print it. */
    public function formatted(): string
    {
        return trim(chunk_split($this->normalised, 4, ' '));
    }

    public function masked(): string
    {
        return '**** **** **** '.$this->last4();
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->normalised, $other->normalised);
    }

    private static function normalise(string $input): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $input) ?? '');
    }

    /**
     * ISO 13616 check: move the first four characters to the end, replace letters
     * with two-digit numbers (A=10 … Z=35), and require the result mod 97 to be 1.
     * The number is far larger than PHP's integer range, so it is reduced piecewise.
     */
    private static function passesMod97(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = (($remainder * 10) + (int) $digit) % 97;
        }

        return $remainder === 1;
    }
}
