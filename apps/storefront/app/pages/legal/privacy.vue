<script setup lang="ts">
useHead({ title: 'Aydınlatma Metni' })

const config = useRuntimeConfig()
const version = '2026-01'
</script>

<template>
  <article class="rc-container max-w-[var(--rc-prose)] py-16">
    <p class="text-xs tracking-wide text-muted uppercase">Sürüm {{ version }}</p>
    <h1 class="mt-3 text-3xl font-medium">KVKK Aydınlatma Metni</h1>

    <RcAlert tone="warning" class="mt-8" title="Taslak metin">
      Bu sayfa yer tutucudur. Yayına çıkmadan önce hukuk incelemesinden geçmiş nihai
      metinle değiştirilmesi gerekir — bu, go-live öncesi harici bir bağımlılık olarak
      <code>13_PROGRESS_STATE.md</code> içinde takip edilmektedir.
    </RcAlert>

    <div class="mt-8 space-y-6 leading-relaxed text-ink-secondary">
      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Veri sorumlusu</h2>
        <p>
          {{ config.public.appName }}, 6698 sayılı Kişisel Verilerin Korunması Kanunu
          kapsamında veri sorumlusu sıfatıyla hareket eder.
        </p>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">İşlenen veriler</h2>
        <ul class="list-disc space-y-1 pl-5">
          <li>Kimlik ve iletişim bilgileri (ad, soyad, e-posta, telefon)</li>
          <li>Teslimat ve fatura adresleri</li>
          <li>Yüklediğiniz oda görselleri ve tasarım tercihleri</li>
          <li>Sipariş, ödeme ve fatura kayıtları</li>
          <li>Güvenlik kayıtları (giriş denemeleri, IP adresi, cihaz bilgisi)</li>
        </ul>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">İşleme amaçları</h2>
        <p>
          Hesabınızın oluşturulması, yapay zekâ destekli tasarım hizmetinin sunulması,
          ürün eşleştirme, sipariş ve ödeme süreçlerinin yürütülmesi, yasal
          yükümlülüklerin yerine getirilmesi ve platform güvenliğinin sağlanması.
        </p>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Haklarınız</h2>
        <p>
          Kanunun 11. maddesi uyarınca verilerinize erişme, düzeltme, silme ve işlemeye
          itiraz etme haklarına sahipsiniz. Talepleriniz için hesabınız üzerinden
          bizimle iletişime geçebilirsiniz.
        </p>
      </section>
    </div>

    <NuxtLink
      to="/auth/register"
      class="mt-10 inline-block text-sm text-ink underline underline-offset-4"
    >
      Kayıt ekranına dön
    </NuxtLink>
  </article>
</template>
