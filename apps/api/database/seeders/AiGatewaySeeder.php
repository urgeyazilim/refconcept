<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Models\PromptTemplate;
use App\Domains\Ai\Models\PromptVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Makes the AI gateway usable the moment the database exists.
 *
 * Every one of the twelve tasks gets a route, and every route gets a published prompt.
 * A task with no route is a feature that fails the first time somebody touches it, and
 * finding that out one task at a time as each phase lands is a slow way to discover
 * twelve identical omissions.
 *
 * Idempotent throughout — `updateOrCreate` keyed on natural identifiers — because this
 * runs on every `db:seed` and a second run must not produce a second copy of a route
 * whose whole point is that there is exactly one per task.
 *
 * **The prompts are seeded as published.** That is a deliberate exception to "published
 * versions are created by a person": there has to be a working prompt for the very
 * first job, and a system whose out-of-the-box state is broken teaches everybody to
 * ignore its warnings. Once somebody improves one, the ordinary rules apply — the
 * published row is immutable and the improvement is version 2.
 */
final class AiGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $fake = $this->seedFakeProvider();
        $google = $this->seedGoogleProvider();
        $openai = $this->seedOpenAiProvider();

        $models = [
            'fake-text' => $this->model($fake, 'fake-text-1', 'Fake Metin', AiModality::Text, structured: true),
            'fake-vision' => $this->model($fake, 'fake-vision-1', 'Fake Görsel Anlama', AiModality::Vision, structured: true, imageInput: true),
            'fake-image' => $this->model($fake, 'fake-image-1', 'Fake Görsel Üretimi', AiModality::Image),
            'fake-embedding' => $this->model($fake, 'fake-embedding-1', 'Fake Vektör', AiModality::Embedding, maxOutputTokens: null),
        ];

        $models['gemini-text'] = $this->model(
            $google,
            // Verified against ListModels *and* a real generateContent call before being
            // written here. 'gemini-3-pro' was seeded once and does not exist: every room
            // analysis failed with 'Geçersiz istek', which reads to a customer like their
            // photograph was the problem.
            'gemini-2.5-pro',
            'Gemini 2.5 Pro',
            AiModality::Vision,
            structured: true,
            imageInput: true,
            contextTokens: 1_000_000,
            maxOutputTokens: 8_192,
        );

        $models['gemini-image'] = $this->model(
            $google,
            'gemini-3-pro-image',
            'Gemini 3 Pro Image',
            AiModality::Image,
            maxOutputTokens: 8_192,
        );

        /*
         * Rates are seeded for the Google models and not for the fake ones. A fake
         * model that costs nothing is honest — no money changes hands — and it keeps
         * the cost-cap tests explicit about the rate they are testing against rather
         * than inheriting one from here.
         */
        $models['gemini-embedding'] = $this->model(
            $google,
            // The model this key can actually reach; `text-embedding-004` is retired.
            // A model code that does not exist fails on the first job with a message that
            // reads like an outage, so it is worth checking against ListModels when this
            // is changed.
            'gemini-embedding-001',
            'Gemini Text Embedding',
            AiModality::Embedding,
            contextTokens: 2_048,
            maxOutputTokens: null,
        );

        $this->rate($models['gemini-text'], inputPerMillion: 1_250_000, outputPerMillion: 10_000_000);
        // Embeddings have no output tokens to speak of and are an order of magnitude
        // cheaper than generation, which is what makes embedding a whole catalogue viable.
        $this->rate($models['gemini-embedding'], inputPerMillion: 25_000, outputPerMillion: 0);
        $this->rate($models['gemini-image'], inputPerMillion: 1_250_000, outputPerMillion: 0, perImage: 30_000);

        /*
         * The model that edits the customer's photograph, and what it costs.
         *
         * Eight dollars per million image input tokens, five per million text, thirty per
         * million image output. A real edit measured 6,021 image and 1,198 text tokens in,
         * 5,488 image tokens out — about twenty-two cents, roughly seven times a Gemini
         * render.
         *
         * Recorded rather than absorbed. The platform prices credits off these rows, and a
         * render that costs seven times what the pricing assumes is a business losing money
         * on every design without anything on a screen saying so.
         */
        $models['gpt-image'] = $this->model(
            $openai,
            'gpt-image-2',
            'GPT Image 2',
            AiModality::Image,
            imageInput: true,
            // Rejected by this model rather than ignored, so it is never sent.
            maxOutputTokens: null,
        );

        $this->rate($models['gpt-image'], inputPerMillion: 8_000_000, outputPerMillion: 30_000_000);

        /*
         * With no Google key on file the Gemini models exist but cannot be called, so
         * routing to them would ship a build whose every AI feature fails on first use.
         * The simulator takes over as primary instead: the routes are real, the shapes
         * are real, and an operator who later adds a key repoints them from the console.
         */
        $googleUsable = $google->activeCredential() !== null;

        foreach ($this->taskPlan() as $task => $plan) {
            $version = $this->seedPrompt(AiTask::from($task), $plan);

            $primaryName = $googleUsable
                ? (string) $plan['primary']
                : (string) ($plan['fallback'] ?? $plan['primary']);

            $fallbackName = $googleUsable && isset($plan['fallback'])
                ? (string) $plan['fallback']
                : null;

            $primary = $models[$primaryName] ?? null;

            if ($primary === null) {
                continue;
            }

            $this->route(
                AiTask::from($task),
                $primary,
                $fallbackName === null ? null : ($models[$fallbackName] ?? null),
                $version,
                $plan,
            );
        }
    }

    // --- providers -----------------------------------------------------------

    private function seedFakeProvider(): AiProvider
    {
        return AiProvider::query()->updateOrCreate(
            ['code' => 'fake'],
            [
                'name' => 'Yerel Simülatör',
                'driver' => 'fake',
                'is_active' => true,
            ],
        );
    }

    /**
     * OpenAI, for the one job it is measurably better at.
     *
     * The render edits a photograph of a customer's home, and the whole product rests on
     * the result still being their home. Tested on a room with French doors, tall windows
     * down one side and a warm wooden floor: `gpt-image-2` returned the same room; the
     * alternative returned a different one. That is not a matter of taste — a preview of a
     * stranger's flat is worth nothing however handsome it is.
     *
     * Seeded the same way as Google and just as tolerant of a missing key: no key is an
     * ordinary state on a fresh clone, and the routes fall back on their own.
     */
    private function seedOpenAiProvider(): AiProvider
    {
        $provider = AiProvider::query()->updateOrCreate(
            ['code' => 'openai'],
            [
                'name' => 'OpenAI',
                'driver' => 'openai',
                'is_active' => true,
            ],
        );

        $key = (string) config('services.openai.key', '');

        if ($key === '') {
            $this->command?->warn('OPENAI_API_KEY tanımlı değil; görsel üretimi Google tarafında kalıyor.');

            return $provider;
        }

        DB::transaction(function () use ($provider, $key): void {
            $provider->credentials()->update(['is_active' => false]);

            $provider->credentials()->updateOrCreate(
                ['label' => 'environment'],
                [
                    'secret_encrypted' => $key,
                    'secret_hint' => mb_substr($key, -4),
                    'is_active' => true,
                ],
            );
        });

        return $provider;
    }

    /**
     * Registers Google, and gives it the key from the environment if one is there.
     *
     * The key is read from configuration, never written to a migration or a fixture, and
     * the model's cast encrypts it on the way in. What ends up in the database is
     * ciphertext plus the last four characters, so a database dump on its own is not a
     * usable key.
     */
    private function seedGoogleProvider(): AiProvider
    {
        $provider = AiProvider::query()->updateOrCreate(
            ['code' => 'google'],
            [
                'name' => 'Google Generative AI',
                'driver' => 'google',
                'is_active' => true,
            ],
        );

        $key = (string) config('services.google_ai.key', '');

        if ($key === '') {
            // No key is a perfectly ordinary state — a fresh clone, a CI run — and the
            // gateway routes to the fake provider, so nothing is broken by it.
            $this->command?->warn('GOOGLE_AI_API_KEY tanımlı değil; AI rotaları simülatöre bağlandı.');

            return $provider;
        }

        DB::transaction(function () use ($provider, $key): void {
            $provider->credentials()->update(['is_active' => false]);

            $provider->credentials()->updateOrCreate(
                ['label' => 'environment'],
                [
                    'secret_encrypted' => $key,
                    'secret_hint' => mb_substr($key, -4),
                    'is_active' => true,
                ],
            );
        });

        return $provider;
    }

    private function model(
        AiProvider $provider,
        string $code,
        string $name,
        AiModality $modality,
        bool $structured = false,
        bool $imageInput = false,
        ?int $contextTokens = null,
        ?int $maxOutputTokens = 4_096,
    ): AiModel {
        return AiModel::query()->updateOrCreate(
            ['provider_id' => $provider->getKey(), 'code' => $code],
            [
                'name' => $name,
                'modality' => $modality,
                'context_tokens' => $contextTokens,
                'max_output_tokens' => $maxOutputTokens,
                'supports_structured_output' => $structured,
                'supports_image_input' => $imageInput,
                'is_active' => true,
            ],
        );
    }

    private function rate(
        AiModel $model,
        int $inputPerMillion,
        int $outputPerMillion,
        int $perImage = 0,
    ): void {
        // Only if there is no open rate already: re-seeding must not close a rate an
        // operator recorded and replace it with the list price shipped in this file.
        if ($model->costRates()->whereNull('effective_to')->exists()) {
            return;
        }

        $model->costRates()->create([
            'currency' => 'USD',
            'input_micros_per_million_tokens' => $inputPerMillion,
            'output_micros_per_million_tokens' => $outputPerMillion,
            'micros_per_image' => $perImage,
            'micros_per_request' => 0,
            'effective_from' => now()->startOfDay(),
        ]);
    }

    // --- prompts and routes ---------------------------------------------------

    /**
     * @param  array<string, mixed>  $plan
     */
    private function seedPrompt(AiTask $task, array $plan): ?PromptVersion
    {
        if (! isset($plan['prompt'])) {
            return null;
        }

        /** @var array{system: string, template: string, schema?: array<string, mixed>} $prompt */
        $prompt = $plan['prompt'];

        $template = PromptTemplate::query()->updateOrCreate(
            ['code' => $task->value],
            [
                'name' => $task->label(),
                'task' => $task,
                'description' => $plan['description'] ?? null,
            ],
        );

        $existing = $template->versions()->where('version', 1)->first();

        if ($existing instanceof PromptVersion) {
            // Version 1 is published and therefore immutable — by a database trigger, not
            // by this check. Returning it rather than attempting an update is what keeps
            // a re-seed from becoming an error.
            return $existing;
        }

        $version = PromptVersion::query()->create([
            'template_id' => $template->getKey(),
            'version' => 1,
            'system_prompt' => $prompt['system'],
            'user_template' => $prompt['template'],
            'response_schema' => $prompt['schema'] ?? null,
            'temperature_bps' => (int) ($plan['temperature_bps'] ?? 7000),
            'change_note' => 'İlk sürüm (kurulum).',
        ]);

        $version->forceFill(['status' => 'published', 'published_at' => now()])->save();

        return $version;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function route(
        AiTask $task,
        AiModel $primary,
        ?AiModel $fallback,
        ?PromptVersion $version,
        array $plan,
    ): void {
        AiTaskRoute::query()->updateOrCreate(
            ['task' => $task->value],
            [
                'primary_model_id' => $primary->getKey(),
                'fallback_model_id' => $fallback?->getKey(),
                'prompt_version_id' => $version?->getKey(),

                /*
                 * Interactive tasks get a shorter timeout and fewer attempts. Somebody
                 * watching a search box would rather be told it failed than wait ninety
                 * seconds for a third attempt at something that is not going to work.
                 */
                'timeout_seconds' => (int) ($plan['timeout'] ?? ($task->isInteractive() ? 20 : 90)),
                'max_attempts' => (int) ($plan['attempts'] ?? ($task->isInteractive() ? 2 : 3)),
                'credit_cost' => (int) ($plan['credits'] ?? 1),
                'max_cost_micros' => (int) ($plan['max_cost_micros'] ?? 500_000),
                'max_concurrency' => (int) ($plan['concurrency'] ?? 5),
                'is_active' => true,
            ],
        );
    }

    /**
     * The shipped configuration for every task.
     *
     * Prompts are in Turkish because the customers are, and because a model asked in
     * Turkish answers Turkish product descriptions and room notes better than one asked
     * in English and told to reply in Turkish.
     *
     * Schemas list only the keys the application actually reads. A schema that describes
     * everything a model might usefully say is a schema that fails a job over a field
     * nothing consumes.
     *
     * @return array<string, array<string, mixed>>
     */
    private function taskPlan(): array
    {
        return [
            AiTask::RoomAnalysis->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-vision',
                'credits' => 1,
                'temperature_bps' => 2000,
                'description' => 'Oda fotoğrafını yapısal bir tanıma çevirir.',
                'prompt' => [
                    'system' => 'Sen bir iç mimarlık asistanısın. Sana verilen oda fotoğrafını incele ve '
                        .'yalnızca istenen JSON yapısında yanıt ver. Tahmin ettiğin ölçüleri kesinmiş gibi '
                        .'sunma; emin olmadığın alanları warnings içinde belirt.',
                    'template' => "Oda türü ipucu: {{ room_type }}\n"
                        ."Kullanıcı notu: {{ notes }}\n"
                        ."Bildirilen ölçüler (mm): {{ dimensions }}\n\n"
                        .'Fotoğraftaki sabit öğeleri (pencere, kapı, radyatör, kolon), taşınabilir '
                        .'eşyaları ve yüzeyleri çıkar.',
                    'schema' => [
                        'required' => ['room_type', 'fixed_elements', 'surfaces'],
                        'properties' => [
                            'room_type' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'style' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'dominant_colors' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'fixed_elements' => ['type' => 'array'],
                            'movable_objects' => ['type' => 'array'],
                            'surfaces' => ['type' => 'object'],
                            'measurement_quality' => ['type' => 'string'],
                            'warnings' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],

            AiTask::DesignPlan->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 1,
                'temperature_bps' => 6000,
                'description' => 'Görsel üretilmeden önce ne nereye gidecek kararını verir.',
                'prompt' => [
                    'system' => 'Sen bir iç mimarsın. Verilen oda analizine ve kısıtlara uyan, uygulanabilir '
                        .'bir yerleşim planı üret. Sabit öğeleri asla kaldırma. Yalnızca JSON döndür.',
                    'template' => "Oda analizi: {{ analysis }}\n"
                        ."Kısıtlar: {{ constraints }}\n"
                        ."Bütçe (kuruş): {{ budget_minor }}\n"
                        ."İstenen stil: {{ style }}\n",
                    'schema' => [
                        'required' => ['style', 'placements'],
                        'properties' => [
                            'style' => ['type' => 'string'],
                            'palette' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'placements' => ['type' => 'array'],
                            'notes' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],

            AiTask::ImageRenderDraft->value => [
                'primary' => 'gemini-image',
                'fallback' => 'fake-image',
                'credits' => 2,
                'max_cost_micros' => 200_000,
                'concurrency' => 3,
                'description' => 'Hızlı, düşük maliyetli önizleme görseli.',
                'prompt' => [
                    'system' => 'Fotogerçekçi bir iç mekân görseli üret.',
                    'template' => "Oda: {{ room_type }}\nStil: {{ style }}\nPlan: {{ plan }}\n"
                        .'Mevcut pencere, kapı ve radyatör konumlarını koru.',
                ],
            ],

            AiTask::ImageRenderPremium->value => [
                'primary' => 'gemini-image',
                'fallback' => 'fake-image',
                'credits' => 6,
                'max_cost_micros' => 900_000,
                'concurrency' => 2,
                'description' => 'Yüksek kaliteli, müşteriye sunulacak görsel.',
                'prompt' => [
                    'system' => 'Fotogerçekçi, yüksek çözünürlüklü bir iç mekân görseli üret. '
                        .'Doğal ışık, gerçekçi malzeme dokuları.',
                    'template' => "Oda: {{ room_type }}\nStil: {{ style }}\nPlan: {{ plan }}\n"
                        ."Palet: {{ palette }}\n"
                        .'Mevcut mimari öğeleri birebir koru.',
                ],
            ],

            AiTask::ImageEdit->value => [
                'primary' => 'gemini-image',
                'fallback' => 'fake-image',
                'credits' => 3,
                'max_cost_micros' => 600_000,
                'concurrency' => 3,
                'description' => 'Var olan bir görselde noktasal değişiklik.',
                'prompt' => [
                    'system' => 'Verilen görselde yalnızca istenen değişikliği yap; geri kalan her şeyi koru.',
                    'template' => "İstenen değişiklik: {{ instruction }}\n",
                ],
            ],

            AiTask::ObjectExtraction->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-vision',
                'credits' => 1,
                'temperature_bps' => 1000,
                'description' => 'Üretilen görselin içindeki mobilyaları tek tek çıkarır.',
                'prompt' => [
                    'system' => 'Görseldeki mobilya ve dekor öğelerini listele. Koordinatları 0-1 aralığında '
                        .'normalize edilmiş [x1, y1, x2, y2] olarak ver. Yalnızca JSON döndür.',
                    'template' => "Oda türü: {{ room_type }}\n",
                    'schema' => [
                        'required' => ['objects'],
                        'properties' => [
                            'objects' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],

            AiTask::ProductTagging->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-vision',
                'credits' => 0,
                'temperature_bps' => 1000,
                'description' => 'Satıcı görselinden ürün etiketleri üretir.',
                'prompt' => [
                    'system' => 'Ürün görselinden stil, renk ve malzeme etiketleri çıkar. Yalnızca JSON döndür.',
                    'template' => "Ürün adı: {{ name }}\nKategori: {{ category }}\n",
                    'schema' => [
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'color' => ['type' => 'string'],
                            'material' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],

            AiTask::TextEmbedding->value => [
                'primary' => 'gemini-embedding',
                'fallback' => 'fake-embedding',
                'credits' => 0,
                'timeout' => 20,
                'attempts' => 2,
                'concurrency' => 20,
                'max_cost_micros' => 20_000,
                'description' => 'Ürün açıklamasını aramaya uygun bir vektöre çevirir.',
                /*
                 * No prompt template. An embedding has no instructions — the text *is* the
                 * input — and inventing a wrapper would only push every vector in the
                 * catalogue in the same direction.
                 */
            ],

            AiTask::ProductQueryRewrite->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 0,
                'temperature_bps' => 3000,
                'description' => 'Serbest metin aramasını katalog diline çevirir.',
                'prompt' => [
                    'system' => 'Kullanıcının arama cümlesini, mobilya kataloğunda arama yapmaya uygun '
                        .'kısa bir sorguya çevir. Yalnızca sorguyu yaz.',
                    'template' => '{{ query }}',
                ],
            ],

            AiTask::ProductMatchRerank->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 0,
                'temperature_bps' => 1000,
                'description' => 'Aday ürünleri tasarıma uygunluğa göre yeniden sıralar.',
                'prompt' => [
                    'system' => 'Verilen tasarım planına göre aday ürünleri uygunluk sırasına diz. '
                        .'Yalnızca JSON döndür.',
                    'template' => "Plan: {{ plan }}\nAdaylar: {{ candidates }}\n",
                    'schema' => [
                        'required' => ['ranking'],
                        'properties' => ['ranking' => ['type' => 'array']],
                    ],
                ],
            ],

            AiTask::BudgetOptimize->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 1,
                'temperature_bps' => 2000,
                'description' => 'Sepeti bütçeye sığdıracak değişiklikleri önerir.',
                'prompt' => [
                    'system' => 'Verilen ürün listesini bütçeye sığdır. Tutarların tamamı kuruş cinsindendir; '
                        .'ondalık kullanma. Yalnızca JSON döndür.',
                    'template' => "Bütçe (kuruş): {{ budget_minor }}\nÜrünler: {{ items }}\n",
                    'schema' => [
                        'required' => ['within_budget', 'total_minor'],
                        'properties' => [
                            'within_budget' => ['type' => 'boolean'],
                            'total_minor' => ['type' => 'integer'],
                            'substitutions' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],

            AiTask::SupportAssist->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 0,
                'temperature_bps' => 4000,
                'description' => 'Destek ekibine yanıt taslağı hazırlar.',
                'prompt' => [
                    'system' => 'RefConcept destek asistanısın. Kısa, açık ve Türkçe yanıt ver. '
                        .'Emin olmadığın konularda kesin konuşma; müşteriyi yanıltma.',
                    'template' => "Müşteri sorusu: {{ question }}\nSipariş bağlamı: {{ context }}\n",
                ],
            ],

            AiTask::CatalogEnrichment->value => [
                'primary' => 'gemini-text',
                'fallback' => 'fake-text',
                'credits' => 0,
                'temperature_bps' => 5000,
                'description' => 'Eksik ürün açıklamalarını tamamlar.',
                'prompt' => [
                    'system' => 'Ürün için kısa, doğru ve abartısız bir açıklama yaz. '
                        .'Sahip olmadığı özellikleri uydurma.',
                    'template' => "Ürün: {{ name }}\nKategori: {{ category }}\nÖzellikler: {{ attributes }}\n",
                ],
            ],
        ];
    }
}
