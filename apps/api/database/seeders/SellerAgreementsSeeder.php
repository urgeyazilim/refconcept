<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Sellers\Models\SellerAgreement;
use Illuminate\Database\Seeder;

/**
 * Publishes the agreement versions in force.
 *
 * Idempotent by (code, version): re-running never edits an existing version, because
 * a seller may already have accepted it and that acceptance records the text's
 * checksum. Changing terms means adding a new version here, not editing an old one.
 *
 * The bodies below are placeholders pending legal review — tracked as an external
 * go-live dependency in 13_PROGRESS_STATE.md.
 */
final class SellerAgreementsSeeder extends Seeder
{
    public function run(): void
    {
        $agreements = [
            [
                'code' => 'marketplace_terms',
                'version' => '2026-01',
                'title' => 'Pazar Yeri Satıcı Sözleşmesi',
                'is_mandatory' => true,
                'body' => $this->marketplaceTerms(),
            ],
            [
                'code' => 'commission_schedule',
                'version' => '2026-01',
                'title' => 'Komisyon ve Hakediş Esasları',
                'is_mandatory' => true,
                'body' => $this->commissionSchedule(),
            ],
            [
                'code' => 'data_processing',
                'version' => '2026-01',
                'title' => 'Veri İşleme Eki (KVKK)',
                'is_mandatory' => true,
                'body' => $this->dataProcessing(),
            ],
        ];

        foreach ($agreements as $agreement) {
            SellerAgreement::query()->firstOrCreate(
                ['code' => $agreement['code'], 'version' => $agreement['version']],
                [
                    'title' => $agreement['title'],
                    'body' => $agreement['body'],
                    'is_mandatory' => $agreement['is_mandatory'],
                    'effective_from' => now()->startOfDay(),
                ],
            );
        }

        $this->command?->info('Seller agreements published: '.count($agreements));
    }

    private function marketplaceTerms(): string
    {
        return <<<'TEXT'
        1. TARAFLAR VE KONU
        Bu sözleşme, RefConcept pazar yeri hizmetini işleten platform ile platformda ürün
        satmak üzere başvuran satıcı arasındadır. RefConcept aracı hizmet sağlayıcıdır;
        satış sözleşmesinin tarafı satıcı ile alıcıdır.

        2. SATICININ YÜKÜMLÜLÜKLERİ
        Satıcı; sunduğu ürün bilgilerinin, görsellerinin, stok ve fiyat verilerinin doğru
        ve güncel olmasından sorumludur. Mevzuata aykırı, taklit veya satışı yasaklı ürün
        listelenemez.

        3. SİPARİŞ VE TESLİMAT
        Satıcı, kendisine iletilen siparişleri ilan ettiği termin süresi içinde hazırlar ve
        kargoya verir. Teslimat, ayıplı ürün ve garanti yükümlülükleri satıcıya aittir.

        4. İADE VE CAYMA
        Tüketicinin cayma hakkı saklıdır. İade süreçleri platform üzerinden yürütülür ve
        iade onaylandığında ilgili tutar satıcı hakedişinden mahsup edilir.

        5. ASKIYA ALMA
        Platform; mevzuata aykırılık, tekrarlanan teslimat başarısızlığı veya tüketici
        şikâyetlerinin yoğunlaşması hâlinde satıcı hesabını gerekçesini bildirerek askıya
        alabilir. Askıya alma, mevcut siparişlere ilişkin yükümlülükleri ortadan kaldırmaz.

        6. YÜRÜRLÜK
        Bu sözleşme, satıcının elektronik ortamda onayı ile yürürlüğe girer.
        TEXT;
    }

    private function commissionSchedule(): string
    {
        return <<<'TEXT'
        1. KOMİSYON
        Platform, satış bedeli üzerinden sözleşmede belirtilen oranda komisyon alır.
        Komisyon oranı sipariş anında dondurulur; sonradan yapılan oran değişiklikleri
        geçmiş siparişleri etkilemez.

        2. HAKEDİŞ
        Satıcı hakedişi; ödemenin tahsil edilmiş, teslimatın tamamlanmış ve iade/itiraz
        süresinin dolmuş olması koşullarının tamamı sağlandığında ödemeye hazır hâle gelir.

        3. MAHSUP
        İade, iptal ve tüketici lehine sonuçlanan itirazlara ilişkin tutarlar ile kargo ve
        hizmet bedelleri hakedişten mahsup edilir.

        4. ÖDEME DÖNEMİ
        Hakedişler, platformda ilan edilen dönemler hâlinde satıcının bildirdiği banka
        hesabına aktarılır.

        5. DÜZELTME
        Hatalı bir finansal kayıt geriye dönük değiştirilmez; ters kayıt ile düzeltilir.
        TEXT;
    }

    private function dataProcessing(): string
    {
        return <<<'TEXT'
        1. KAPSAM
        Bu ek, satıcının platform üzerinden eriştiği kişisel verilerin 6698 sayılı Kanun
        kapsamında işlenmesine ilişkin esasları düzenler.

        2. AMAÇLA SINIRLILIK
        Satıcı; alıcıya ait ad, adres ve iletişim bilgilerini yalnızca siparişin
        teslimi, faturalandırılması ve iade süreçlerinin yürütülmesi amacıyla işler.
        Pazarlama amacıyla kullanılamaz, üçüncü kişilerle paylaşılamaz.

        3. GÜVENLİK
        Satıcı, eriştiği verileri yetkisiz erişime karşı korumakla ve veri ihlali
        hâlinde platformu gecikmeksizin bilgilendirmekle yükümlüdür.

        4. SAKLAMA VE İMHA
        Veriler, ilgili mevzuatın öngördüğü saklama süresi sonunda imha edilir.

        5. DENETİM
        Platform, bu ekteki yükümlülüklere uyumu denetleme hakkını saklı tutar.
        TEXT;
    }
}
