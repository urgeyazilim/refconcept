<?php

declare(strict_types=1);

namespace App\Domains\Ai\Console;

use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks each provider whether the models we are configured to call actually exist.
 *
 * This command exists because of a real outage. `gemini-3-pro` was seeded, Google does not
 * serve it, and every room analysis failed with the provider's `invalid_request` — which the
 * platform renders to a customer as *"Oda fotoğrafı okunamadı. Daha aydınlık bir fotoğrafla
 * tekrar deneyin."* A perfectly good message for the failure it was written for, and a lie
 * about this one: the photograph was fine, and the customer retook it and failed again.
 *
 * Nothing in the codebase could have caught that. A model code is a string we send to
 * somebody else, and the only authority on whether it is valid is them.
 *
 *   php artisan refconcept:verify-ai-models
 *
 * Deliberately **not** part of the test suite. It needs the network and a live API key, and
 * a suite that fails when a third party is having a bad morning is a suite people learn to
 * ignore. Run it after changing a model, and before a release.
 */
final class VerifyAiModelsCommand extends Command
{
    protected $signature = 'refconcept:verify-ai-models';

    protected $description = 'Check every configured model code against its provider';

    public function handle(): int
    {
        $models = AiModel::query()->with('provider')->where('is_active', true)->get();

        if ($models->isEmpty()) {
            $this->warn('Aktif model yok.');

            return self::SUCCESS;
        }

        $available = null;
        $failures = 0;

        foreach ($models as $model) {
            $provider = $model->provider?->code;

            if ($provider === 'fake') {
                $this->line(sprintf('  <fg=gray>—</> %-24s yerel sahte sağlayıcı', $model->code));

                continue;
            }

            if ($provider !== 'google') {
                // Only Google publishes a list we can check today. Saying so is better than
                // reporting a pass for something that was never examined.
                $this->line(sprintf('  <fg=yellow>?</> %-24s %s için doğrulama yok', $model->code, $provider ?? 'bilinmeyen'));

                continue;
            }

            $available ??= $this->googleModels();

            if ($available === null) {
                $this->error('Google model listesi alınamadı. Anahtar ve ağ erişimini kontrol edin.');

                return self::FAILURE;
            }

            if (in_array($model->code, $available, true)) {
                $this->line(sprintf('  <fg=green>✔</> %-24s %s', $model->code, $model->name));

                continue;
            }

            $failures++;

            $this->line(sprintf('  <fg=red>✖</> %-24s sağlayıcıda yok', $model->code));

            // The near misses, because a wrong model code is almost always a version away
            // from a right one and reading the whole list is not help.
            $suggestions = array_slice(array_filter(
                $available,
                static fn (string $candidate): bool => str_starts_with($candidate, explode('-', $model->code)[0] ?? ''),
            ), 0, 6);

            if ($suggestions !== []) {
                $this->line('      bunlar var: '.implode(', ', $suggestions));
            }
        }

        if ($failures > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d model sağlayıcıda yok. Bu modellere yönlenen her iş "Geçersiz istek" ile başarısız olur.',
                $failures,
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✔ Yapılandırılmış her model sağlayıcıda mevcut.');

        return self::SUCCESS;
    }

    private function googleBaseUrl(): string
    {
        $configured = AiProvider::query()->where('code', 'google')->value('base_url');

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * @return list<string>|null the codes, or null when the list could not be read
     */
    private function googleModels(): ?array
    {
        $key = (string) config('services.google_ai.key', '');

        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)->get(
                // The provider row's own base URL when it has one, so a proxy or a pinned
                // API version is honoured here exactly as it is on a real call.
                rtrim($this->googleBaseUrl(), '/').'/models',
                ['key' => $key, 'pageSize' => 200],
            );
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var list<array<string, mixed>> $models */
        $models = (array) $response->json('models', []);

        return array_values(array_map(
            // The API returns `models/gemini-2.5-pro`; we store the bare code.
            static fn (array $model): string => str_replace('models/', '', (string) ($model['name'] ?? '')),
            $models,
        ));
    }
}
