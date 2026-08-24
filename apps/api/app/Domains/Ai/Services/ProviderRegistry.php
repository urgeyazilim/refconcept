<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Contracts\AiProvider as AiProviderContract;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Providers\GoogleAiProvider;
use App\Domains\Ai\Providers\OpenAiProvider;
use RuntimeException;

/**
 * Turns a `driver` string into the adapter that implements it.
 *
 * A single place, because the alternative is a `match` in the gateway that grows a
 * branch every time a provider is added — and a second one in whatever else needs an
 * adapter later.
 *
 * Adapters are resolved from the container rather than constructed here, so a test can
 * bind a double and so an adapter can take dependencies without this class knowing
 * what they are.
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<AiProviderContract>> */
    private const DRIVERS = [
        'fake' => FakeAiProvider::class,
        'openai' => OpenAiProvider::class,
        'google' => GoogleAiProvider::class,
    ];

    public function for(AiProvider $provider): AiProviderContract
    {
        $class = self::DRIVERS[$provider->driver] ?? null;

        if ($class === null) {
            /*
             * A configuration error rather than a provider error, and said so: an
             * operator who picked a driver that does not exist should be told that,
             * not shown a stack trace from an HTTP client.
             */
            throw new RuntimeException(sprintf(
                'No adapter is registered for the "%s" driver. Known drivers: %s.',
                $provider->driver,
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        return app($class);
    }

    /**
     * Whether a driver name is one this build can actually serve.
     *
     * Used when an operator saves a provider, so the mistake is caught on the form
     * rather than on the first job.
     */
    public function knows(string $driver): bool
    {
        return array_key_exists($driver, self::DRIVERS);
    }

    /** @return array<int, string> */
    public function drivers(): array
    {
        return array_keys(self::DRIVERS);
    }
}
