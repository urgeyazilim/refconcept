<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teaches the render step that it is editing a photograph, not inventing a room.
 *
 * The first prompt said "generate a photorealistic interior image" and listed a room type, a
 * style and a plan. The model did exactly that: it drew a room. A very nice room, belonging
 * to nobody. The customer who had uploaded their own living room got back a stranger's, and
 * the shopping list underneath it named furniture that was not in the picture.
 *
 * Two things changed alongside this prompt, and it only makes sense with them:
 *
 *  - the room photograph is now sent with the request, first in the list of images;
 *  - product matching now runs *before* the render, so the products in the picture are the
 *    products in the basket.
 *
 * A new version rather than an edit: published prompt versions are immutable, by a database
 * trigger rather than by convention, because a design generated last month has to remain
 * explainable by the prompt that generated it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->prompts() as $task => $prompt) {
            $template = DB::table('prompt_templates')->where('code', $task)->first();

            if ($template === null) {
                continue;
            }

            $latest = (int) DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->max('version');

            /*
             * A re-run must not stack a third identical version — but it must still make
             * sure the route points at it. Those are two different things, and conflating
             * them is exactly how the first attempt at this migration did nothing: the
             * version was published, the check saw it and stopped, and the route quietly
             * kept using the old prompt.
             */
            $existing = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('user_template', $prompt['template'])
                ->value('id');

            $id = is_string($existing) ? $existing : (string) Str::uuid7();

            if (is_string($existing)) {
                DB::table('ai_task_routes')
                    ->where('task', $task)
                    ->update(['prompt_version_id' => $id, 'updated_at' => now()]);

                continue;
            }

            DB::table('prompt_versions')->insert([
                'id' => $id,
                'template_id' => $template->id,
                'version' => $latest + 1,
                'system_prompt' => $prompt['system'],
                'user_template' => $prompt['template'],
                'temperature_bps' => 7000,
                'status' => 'published',
                'change_note' => 'Render artık müşterinin kendi oda fotoğrafını düzenliyor ve '
                    .'eşleşen ürünlerin görsellerini referans alıyor.',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * And the route is repointed at it.
             *
             * A route pins an exact prompt version rather than following the newest, which
             * is the right design — a prompt change is a behaviour change and should not
             * arrive by surprise on somebody else's deploy. It also means publishing a new
             * version *on its own does nothing at all*, which is the trap this migration
             * fell into on its first attempt: the version was there, the route still used
             * the old one, and the render kept drawing a stranger's room.
             */
            DB::table('ai_task_routes')
                ->where('task', $task)
                ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Intentionally empty. Rolling back would restore a prompt that draws somebody
        // else's room, and the older version is still on record for any design that used it.
    }

    /**
     * @return array<string, array{system: string, template: string}>
     */
    private function prompts(): array
    {
        /*
         * The instruction that matters most is the first one: this is a photograph of a real
         * room and it is being *edited*. Everything else — the plan, the palette, the
         * products — is what to put into it.
         *
         * "Do not change the architecture" is said in plain terms rather than left to be
         * inferred. A model told to redesign a room will happily move a window if nobody
         * mentions that the window is load-bearing reality.
         */
        $system = <<<'TXT'
            Sana verilen ilk görsel, müşterinin gerçek odasının fotoğrafıdır. Bu fotoğrafı
            DÜZENLE. Yeni bir oda çizme.

            Odayı olduğu gibi koru: duvarların açısı ve rengi, pencerenin konumu ve boyutu,
            kapılar, radyatörler, zemin kaplaması, kamera açısı ve perspektif birebir aynı
            kalsın. Müşteri sonuçta kendi odasını tanımalı.

            Yalnızca mobilya, tekstil, aydınlatma ve dekorasyon ekle.

            İlk görselden sonra gelen görseller, odaya yerleştirilecek gerçek ürünlerdir.
            Bunları biçim, renk ve malzeme olarak sadık biçimde sahneye yerleştir; yerlerine
            benzerlerini uydurma.
            TXT;

        $template = <<<'TXT'
            Oda: {{ room_type }}
            Stil: {{ style }}
            Yerleşim planı: {{ plan }}
            Renk paleti: {{ palette }}
            Korunacak mimari öğeler: {{ preserve }}
            Görsellerin sırası ve anlamı: {{ image_roles }}
            Müşterinin isteği: {{ instruction }}
            TXT;

        return [
            'image_render_draft' => [
                'system' => $system."\n\nHızlı önizleme kalitesi yeterli.",
                'template' => $template,
            ],
            'image_render_premium' => [
                'system' => $system."\n\nYüksek çözünürlük, gerçekçi malzeme dokuları ve "
                    .'ince ışık geçişleri kullan.',
                'template' => $template,
            ],
        ];
    }
};
