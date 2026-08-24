<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Style;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Models\ProductModeration;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A published catalogue for local development and staging.
 *
 * An empty catalogue makes the storefront impossible to judge and the filters
 * impossible to exercise: every grid renders the empty state, every price sort has
 * nothing to sort, and "does this look right" cannot be answered. So this seeds real
 * listings — photographed, priced, measured, approved — across both demo sellers.
 *
 * Two things are done the long way on purpose. Imagery is uploaded to the public disk
 * and recorded as ProductMedia, exactly as a seller's upload would be, so the demo
 * catalogue exercises the same storage path as production rather than pointing at a
 * static asset only this seeder knows about. And approval is written as a real
 * moderation decision, so the audit trail of a demo listing is not empty in a way no
 * live listing ever would be.
 *
 * Never runs in production (see {@see DatabaseSeeder}).
 */
final class DemoCatalogSeeder extends Seeder
{
    private const ASSET_DIR = __DIR__.'/assets/products';

    /**
     * The demo catalogue.
     *
     * Prices are minor units. Dimensions are millimetres. Both are the units the rest
     * of the system uses, so nothing here has to be converted on its way in.
     *
     * @var list<array{
     *     seller: string,
     *     category: string,
     *     brand: string,
     *     style: string,
     *     image: string,
     *     name: string,
     *     description: string,
     *     sku: string,
     *     variant: string,
     *     list: int,
     *     sale: int|null,
     *     stock: int,
     *     width: int,
     *     depth: int,
     *     height: int|null,
     *     color: string,
     *     material: string
     * }>
     */
    private const PRODUCTS = [
        [
            'seller' => 'atlas-mobilya',
            'category' => 'kanepe',
            'brand' => 'arden',
            'style' => 'modern',
            'image' => 'kanepe-boucle',
            'name' => 'Arden Bouclé Üç Kişilik Kanepe',
            'description' => "Yumuşak bouclé kumaşla kaplanmış, modüler üç kişilik oturma grubu.\n\nMasif kayın iskelet ve yüksek yoğunluklu sünger dolgu, uzun yıllar formunu koruyacak şekilde tasarlandı. Sırt minderleri çıkarılabilir, kılıflar yıkanabilir.\n\nOturma yüksekliği 43 cm; küçük ve orta ölçekli oturma odaları için uygundur.",
            'sku' => 'ARD-KNP-320',
            'variant' => 'Ekru bouclé · 220 cm',
            'list' => 4_890_000,
            'sale' => 4_390_000,
            'stock' => 6,
            'width' => 2200,
            'depth' => 950,
            'height' => 780,
            'color' => 'cream',
            'material' => 'boucle',
        ],
        [
            'seller' => 'atlas-mobilya',
            'category' => 'koltuk',
            'brand' => 'arden',
            'style' => 'modern',
            'image' => 'koltuk-keten',
            'name' => 'Arden Keten Tekli Koltuk',
            'description' => "Alçak kavisli sırtı ve konik ceviz ayaklarıyla, okuma köşeleri için tasarlanmış tekli koltuk.\n\nTaş rengi keten kumaş, doğal dokusunu koruyan bir dokuma ile üretildi. Kolçak yüksekliği kitap okurken dirsek desteği verecek şekilde hesaplandı.",
            'sku' => 'ARD-KLT-110',
            'variant' => 'Taş keten',
            'list' => 1_890_000,
            'sale' => null,
            'stock' => 12,
            'width' => 780,
            'depth' => 820,
            'height' => 740,
            'color' => 'stone',
            'material' => 'linen',
        ],
        [
            'seller' => 'atlas-mobilya',
            'category' => 'sehpa',
            'brand' => 'nordhem',
            'style' => 'scandinavian',
            'image' => 'sehpa-mese',
            'name' => 'Nordhem Masif Meşe Yuvarlak Sehpa',
            'description' => "Tek parça masif meşeden, hafifçe konikleşen kaide ayaklı yuvarlak sehpa.\n\nYüzey doğal yağ ile bitirildi; zamanla koyulaşarak patina kazanır. Kaide tasarımı, çevresine oturan kişilerin ayaklarına yer bırakır.",
            'sku' => 'NRD-SHP-090',
            'variant' => 'Doğal meşe · Ø90 cm',
            'list' => 1_240_000,
            'sale' => null,
            'stock' => 8,
            'width' => 900,
            'depth' => 900,
            'height' => 420,
            'color' => 'oak',
            'material' => 'solid-oak',
        ],
        [
            'seller' => 'atlas-mobilya',
            'category' => 'yemek-masasi',
            'brand' => 'nordhem',
            'style' => 'scandinavian',
            'image' => 'yemek-masasi',
            'name' => 'Nordhem Altı Kişilik Meşe Yemek Masası',
            'description' => "Sade kare ayaklı, masif meşe yemek masası. Altı kişilik kullanım için 180 cm uzunluğunda.\n\nTabla kalınlığı 30 mm; ek yerleri görünmeyecek şekilde birleştirildi. Yağlı bitiş, ıslak bezle günlük temizliğe uygundur.",
            'sku' => 'NRD-YMK-180',
            'variant' => 'Doğal meşe · 180 cm',
            'list' => 3_450_000,
            'sale' => 2_990_000,
            'stock' => 4,
            'width' => 1800,
            'depth' => 900,
            'height' => 750,
            'color' => 'oak',
            'material' => 'solid-oak',
        ],
        [
            'seller' => 'atlas-mobilya',
            'category' => 'sandalye',
            'brand' => 'arden',
            'style' => 'modern',
            'image' => 'sandalye-boucle',
            'name' => 'Arden Bouclé Yemek Sandalyesi',
            'description' => "Kavisli bouclé gövdesi sırtı sararak destek verir; ince meşe ayaklar masanın altında yer kaplamaz.\n\nTek tek ya da altılı set olarak satın alınabilir. İstiflenemez.",
            'sku' => 'ARD-SND-045',
            'variant' => 'Krem bouclé',
            'list' => 640_000,
            'sale' => null,
            'stock' => 40,
            'width' => 480,
            'depth' => 520,
            'height' => 810,
            'color' => 'cream',
            'material' => 'boucle',
        ],
        [
            'seller' => 'atlas-mobilya',
            'category' => 'kitaplik',
            'brand' => 'nordhem',
            'style' => 'minimal',
            'image' => 'kitaplik-mese',
            'name' => 'Nordhem Açık Raflı Meşe Kitaplık',
            'description' => "Beş yatay raflı, arkası açık masif meşe kitaplık. Oda bölücü olarak da kullanılabilir.\n\nRaf aralıkları standart kitap yüksekliklerine göre belirlendi; en alt raf büyük boy sanat kitapları için daha yüksektir.",
            'sku' => 'NRD-KTP-200',
            'variant' => 'Doğal meşe · 200 cm',
            'list' => 2_180_000,
            'sale' => null,
            'stock' => 5,
            'width' => 900,
            'depth' => 320,
            'height' => 2000,
            'color' => 'oak',
            'material' => 'solid-oak',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'hali',
            'brand' => 'kavim',
            'style' => 'warm-contemporary',
            'image' => 'hali-yun',
            'name' => 'Kavim El Dokuma Yün Halı',
            'description' => "Anadolu dokuma geleneğinden gelen, elde dokunmuş saf yün halı.\n\nKum ve taupe tonlarında ince geometrik dokusu, desenden çok yüzey hissi verir. Her halı elde dokunduğu için ölçülerde birkaç santimetrelik farklar olabilir.",
            'sku' => 'KVM-HAL-240',
            'variant' => 'Kum · 240×170 cm',
            'list' => 2_760_000,
            'sale' => 2_290_000,
            'stock' => 3,
            'width' => 2400,
            'depth' => 1700,
            'height' => null,
            'color' => 'sand',
            'material' => 'wool',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'lambader',
            'brand' => 'vela-studio',
            'style' => 'modern',
            'image' => 'lambader-linen',
            'name' => 'Vela Pirinç Gövdeli Lambader',
            'description' => "Fırçalanmış pirinç gövde ve sıcak kırık beyaz keten şapkalı zemin lambası.\n\nKademeli dimmer anahtarı, akşam okuma ışığından ortam aydınlatmasına kadar ayarlanabilir. E27 duy, ampul dahil değildir.",
            'sku' => 'VLA-LMB-160',
            'variant' => 'Pirinç · 160 cm',
            'list' => 890_000,
            'sale' => null,
            'stock' => 15,
            'width' => 400,
            'depth' => 400,
            'height' => 1600,
            'color' => 'brass',
            'material' => 'brass',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'tavan-aydinlatma',
            'brand' => 'vela-studio',
            'style' => 'modern',
            'image' => 'tavan-aydinlatma',
            'name' => 'Vela Pileli Keten Sarkıt',
            'description' => "El işçiliğiyle pileli keten şapka ve fırçalanmış pirinç bağlantı.\n\nYemek masası üzerinde tek başına ya da üçlü dizilim hâlinde kullanılabilir. Askı yüksekliği montaj sırasında ayarlanabilir.",
            'sku' => 'VLA-SRK-050',
            'variant' => 'Kırık beyaz · Ø50 cm',
            'list' => 1_120_000,
            'sale' => null,
            'stock' => 9,
            'width' => 500,
            'depth' => 500,
            'height' => 420,
            'color' => 'cream',
            'material' => 'linen',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'yatak',
            'brand' => 'meridyen',
            'style' => 'classic',
            'image' => 'yatak-keten',
            'name' => 'Meridyen Keten Başlıklı Çift Kişilik Karyola',
            'description' => "Alçak dolgulu başlığı ve taş rengi keten kaplamasıyla sade bir karyola.\n\n160×200 cm yatak ölçüsüne uygundur. Baza dahil değildir; karyola altı temizlik için 20 cm boşluk bırakır.",
            'sku' => 'MRD-YTK-160',
            'variant' => 'Taş keten · 160×200 cm',
            'list' => 3_980_000,
            'sale' => null,
            'stock' => 4,
            'width' => 1720,
            'depth' => 2120,
            'height' => 1100,
            'color' => 'stone',
            'material' => 'linen',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'komodin',
            'brand' => 'nordhem',
            'style' => 'scandinavian',
            'image' => 'komodin-mese',
            'name' => 'Nordhem İki Çekmeceli Meşe Komodin',
            'description' => "Gömme parmak kulplu, iki çekmeceli masif meşe komodin.\n\nÇekmeceler tam açılır ray üzerinde çalışır ve yumuşak kapanır. Karyola yüksekliğine uygun 55 cm yükseklik.",
            'sku' => 'NRD-KMD-045',
            'variant' => 'Doğal meşe',
            'list' => 890_000,
            'sale' => 740_000,
            'stock' => 14,
            'width' => 450,
            'depth' => 400,
            'height' => 550,
            'color' => 'oak',
            'material' => 'solid-oak',
        ],
        [
            'seller' => 'nova-yasam',
            'category' => 'ayna',
            'brand' => 'loft-co',
            'style' => 'industrial',
            'image' => 'ayna-pirinc',
            'name' => 'Loft & Co. İnce Pirinç Çerçeveli Yuvarlak Ayna',
            'description' => "İnce fırçalanmış pirinç çerçeveli, 90 cm çapında yuvarlak ayna.\n\nDuvara asılabilir ya da konsol üzerine yaslanabilir. Arka montaj aparatı ürünle birlikte gelir.",
            'sku' => 'LFT-AYN-090',
            'variant' => 'Pirinç · Ø90 cm',
            'list' => 1_340_000,
            'sale' => null,
            'stock' => 7,
            'width' => 900,
            'depth' => 40,
            'height' => 900,
            'color' => 'brass',
            'material' => 'brass',
        ],
    ];

    public function run(): void
    {
        $operator = User::query()->where('email', 'operator@refconcept.local')->first();

        if ($operator === null) {
            $this->command?->warn('Demo catalogue skipped: demo accounts have not been seeded.');

            return;
        }

        $created = 0;

        foreach (self::PRODUCTS as $definition) {
            if ($this->seedProduct($definition, $operator)) {
                $created++;
            }
        }

        // Only what was actually created is claimed. Reporting the remainder as
        // "already present" would report a missing seller or category as a success.
        $this->command?->info(sprintf(
            'Demo catalogue: %d of %d products published.',
            $created,
            count(self::PRODUCTS),
        ));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return bool whether the product was created by this run
     */
    private function seedProduct(array $definition, User $operator): bool
    {
        $slug = Str::slug((string) $definition['name']);

        if (Product::query()->where('slug', $slug)->exists()) {
            return false;
        }

        $organization = Organization::query()->where('slug', $definition['seller'])->first();
        $seller = $organization === null
            ? null
            : Seller::query()->where('organization_id', $organization->getKey())->first();

        if ($seller === null) {
            $this->command?->warn("Seller '{$definition['seller']}' not found; skipping {$definition['name']}.");

            return false;
        }

        $category = Category::query()->where('slug', $definition['category'])->first();

        if ($category === null) {
            $this->command?->warn("Category '{$definition['category']}' not found; skipping {$definition['name']}.");

            return false;
        }

        DB::transaction(function () use ($definition, $slug, $organization, $seller, $category, $operator): void {
            $product = Product::query()->create([
                'organization_id' => $organization->getKey(),
                'primary_category_id' => $category->getKey(),
                'brand_id' => Brand::query()->where('slug', $definition['brand'])->value('id'),
                'style_id' => Style::query()->where('code', $definition['style'])->value('id'),
                'name' => $definition['name'],
                'slug' => $slug,
                'description' => $definition['description'],
                'created_by' => $organization->owner_user_id,
            ]);

            $this->attachAttributes($product, $definition);
            $this->attachMedia($product, (string) $definition['image'], (string) $definition['name']);

            $sku = ProductSku::query()->create([
                'product_id' => $product->getKey(),
                'seller_id' => $seller->getKey(),
                'sku' => $definition['sku'],
                'variant_label' => $definition['variant'],
                'currency' => 'TRY',
                'list_price_minor' => $definition['list'],
                'sale_price_minor' => $definition['sale'],
                'tax_rate_bps' => 2000,
                'stock_policy' => 'track',
                'stock_quantity' => $definition['stock'],
                'lead_time_days' => 5,
            ]);

            $sku->dimensions()->create([
                'width_mm' => $definition['width'],
                'depth_mm' => $definition['depth'],
                'height_mm' => $definition['height'],
                'assembly_required' => ($definition['height'] ?? 0) > 1000,
            ]);

            $this->publish($product, $sku, $operator);
        });

        return true;
    }

    /**
     * Marks the listing approved and on sale.
     *
     * Written directly rather than through ProductModerationWorkflow: the workflow
     * insists on a real reviewer walking the state machine from draft to approved, and
     * a seeder impersonating that would produce a status history claiming decisions
     * that never happened. The moderation record below is honest about what this is.
     */
    private function publish(Product $product, ProductSku $sku, User $operator): void
    {
        $product->forceFill([
            'moderation_status' => ModerationStatus::Approved,
            'status' => ProductStatus::Active,
            'published_at' => now(),
        ])->save();

        $sku->forceFill(['status' => SkuStatus::Active])->save();

        ProductModeration::query()->create([
            'product_id' => $product->getKey(),
            'decision' => 'approved',
            'reason' => 'Demo veri kümesi: geliştirme ortamı için önceden onaylandı.',
            'decided_by' => $operator->getKey(),
            'decided_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function attachAttributes(Product $product, array $definition): void
    {
        foreach (['color' => $definition['color'], 'material' => $definition['material']] as $code => $value) {
            $attribute = Attribute::query()->where('code', $code)->with('values')->first();

            if ($attribute === null) {
                continue;
            }

            $option = $attribute->values->firstWhere('value', $value)
                // A demo product whose colour is not in the vocabulary would be filtered
                // out of the very screens it exists to populate, so it falls back rather
                // than being left blank.
                ?? $attribute->values->first();

            if ($option === null) {
                continue;
            }

            $product->attributeValues()->create([
                'product_id' => $product->getKey(),
                'attribute_id' => $attribute->getKey(),
                'attribute_value_id' => $option->getKey(),
            ]);
        }
    }

    /**
     * Uploads the committed photograph to the public disk as the product's cover.
     */
    private function attachMedia(Product $product, string $image, string $alt): void
    {
        $source = self::ASSET_DIR.'/'.$image.'.webp';

        if (! is_file($source)) {
            $this->command?->warn("Image '{$image}.webp' is missing; {$alt} will have no cover.");

            return;
        }

        $disk = (string) config('refconcept.storage.public_disk', config('filesystems.default'));
        $path = sprintf('product-media/%s/%s.webp', $product->getKey(), Str::uuid7()->toString());

        $contents = file_get_contents($source);

        if ($contents === false) {
            return;
        }

        Storage::disk($disk)->put($path, $contents, 'public');

        $size = @getimagesize($source);

        ProductMedia::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'image',
            'disk' => $disk,
            'storage_path' => $path,
            'original_name' => $image.'.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => (int) filesize($source),
            'width' => $size === false ? null : (int) $size[0],
            'height' => $size === false ? null : (int) $size[1],
            'alt_text' => $alt,
            'position' => 0,
        ]);
    }
}
