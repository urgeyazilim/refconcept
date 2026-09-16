<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The renderer is shown the room's other photographs, and told what they are.
 *
 * A customer photographed the room from four corners and the picture came back with a
 * radiator and a window painted across the wall their television unit stands on. The
 * reading had seen the radiator — in another photograph — and named it among the fixtures
 * to preserve; the renderer, looking at a wall with no radiator on it and told to keep one,
 * painted one. Version 8 carries the other photographs as references, and the fourth rule
 * no longer says every image after the first is a product: the role list says which are.
 */
return new class extends Migration
{
    private const TASKS = ['image_render_draft', 'image_render_premium'];

    private const VERSION = 8;

    public function up(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $previous = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', '<', self::VERSION)
                ->orderByDesc('version')
                ->first();

            if ($previous === null) {
                continue;
            }

            $exists = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', self::VERSION)
                ->exists();

            if ($exists) {
                continue;
            }

            $id = (string) Str::uuid7();

            DB::table('prompt_versions')->insert([
                'id' => $id,
                'template_id' => $template->id,
                'version' => self::VERSION,
                'status' => 'published',
                'published_at' => now(),
                'temperature_bps' => $previous->temperature_bps ?? 2_000,
                'system_prompt' => $this->amend((string) $previous->system_prompt),
                'user_template' => $previous->user_template,
                'response_schema' => $previous->response_schema,
                'change_note' => 'Odanın diğer fotoğrafları referans olarak veriliyor; "ilk görselden sonraki her görsel üründür" kuralı rol listesine bağlandı.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_task_routes')
                ->where('task', $code)
                ->update(['prompt_version_id' => $id, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::TASKS as $code) {
            $template = DB::table('prompt_templates')->where('code', $code)->first();

            if ($template === null) {
                continue;
            }

            $previous = DB::table('prompt_versions')
                ->where('template_id', $template->id)
                ->where('version', '<', self::VERSION)
                ->orderByDesc('version')
                ->first();

            if ($previous !== null) {
                DB::table('ai_task_routes')
                    ->where('task', $code)
                    ->update(['prompt_version_id' => $previous->id, 'updated_at' => now()]);
            }
        }
    }

    /**
     * The fourth rule, rewritten to follow the role list, and a sixth about references.
     */
    private function amend(string $system): string
    {
        $old = "DÖRDÜNCÜ KURAL — ÜRÜNLER GERÇEK ÜRÜNLERDİR.\n"
            ."İlk görselden sonraki her görsel, odaya konacak gerçek bir üründür. Biçim, renk ve\n"
            .'malzeme olarak onlara sadık kal; yerlerine benzerlerini uydurma.';

        $new = "DÖRDÜNCÜ KURAL — ÜRÜNLER GERÇEK ÜRÜNLERDİR.\n"
            ."Görsellerin sırası ve anlamı listesinde \"Yerleştirilecek ürün\" denen her görsel, odaya\n"
            ."konacak gerçek bir üründür. Biçim, renk ve malzeme olarak onlara sadık kal; yerlerine\n"
            ."benzerlerini uydurma.\n"
            ."\n"
            ."ALTINCI KURAL — REFERANS GÖRSELLER ÇİZİLMEZ.\n"
            ."Listede \"REFERANS\" denen görseller aynı odanın başka açılardan fotoğraflarıdır. Onlara\n"
            ."yalnızca odayı tanımak için bak: hangi duvarda kapı, pencere, radyatör, klima var; zemin\n"
            ."ve duvar rengi nedir. Kadraj, bakış açısı ve düzenlenecek görsel HER ZAMAN ilk görseldir.\n"
            ."Referans görsellerdeki eşyaları ve o duvarlardaki öğeleri ilk görselin duvarlarına taşıma;\n"
            .'ilk görselde görünmeyen bir duvar için hiçbir şey çizme.';

        return str_contains($system, $old) ? str_replace($old, $new, $system) : $system."\n\n".$new;
    }
};
