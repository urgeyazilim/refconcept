<script setup lang="ts">
useHead({ title: 'Kullanım Koşulları' })

const version = '2026-01'
</script>

<template>
  <article class="rc-container max-w-[var(--rc-prose)] py-16">
    <p class="text-xs tracking-wide text-muted uppercase">Sürüm {{ version }}</p>
    <h1 class="mt-3 text-3xl font-medium">Kullanım Koşulları</h1>

    <RcAlert tone="warning" class="mt-8" title="Taslak metin">
      Bu sayfa yer tutucudur. Yayına çıkmadan önce hukuk incelemesinden geçmiş nihai
      metinle değiştirilmesi gerekir — bu, go-live öncesi harici bir bağımlılık olarak
      <code>13_PROGRESS_STATE.md</code> içinde takip edilmektedir.
    </RcAlert>

    <div class="mt-8 space-y-6 leading-relaxed text-ink-secondary">
      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Hizmetin kapsamı</h2>
        <p>
          RefConcept, yapay zekâ destekli iç mekân tasarımı üretir ve tasarımdaki
          parçaları platformdaki satıcıların gerçek ürünleriyle eşleştirir. Tasarım
          çıktıları öneri niteliğindedir; nihai satın alma kararı kullanıcıya aittir.
        </p>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Pazar yeri ilişkisi</h2>
        <p>
          Ürünler bağımsız satıcılar tarafından satılır. RefConcept, satış sözleşmesinin
          tarafı değil; aracı hizmet sağlayıcıdır. Ürün, teslimat ve garanti
          yükümlülükleri ilgili satıcıya aittir.
        </p>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Kredi kullanımı</h2>
        <p>
          Yapay zekâ tasarım üretimi kredi tüketir. Tasarım başlamadan önce kredi
          rezerve edilir; üretim başarısız olursa rezerve edilen kredi iade edilir.
        </p>
      </section>

      <section>
        <h2 class="mb-2 text-lg font-medium text-ink">Hesap güvenliği</h2>
        <p>
          Hesap bilgilerinizin gizliliğinden siz sorumlusunuz. Yetkisiz bir erişimden
          şüphelendiğinizde parolanızı sıfırlamanız yeterlidir; sıfırlama tüm açık
          oturumlarınızı kapatır.
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
