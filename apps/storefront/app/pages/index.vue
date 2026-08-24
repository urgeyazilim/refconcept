<script setup lang="ts">
useHead({ title: 'Odanı gör, şekillendir, yaşa' })

/**
 * Six pillars from 22_SCREEN_BLUEPRINTS §3.
 *
 * Rendered as a light icon strip rather than six bordered cards: the approved
 * reference (`design_refs/ui_inspiration.jpg`) shows a quiet row of line icons under
 * the hero, and boxing each pillar adds six competing outlines to a page whose whole
 * design language is "minimal borders, generous space".
 */
const pillars = [
  {
    title: 'AI Tasarım',
    description: 'Fotoğraftan saniyeler içinde tasarım',
    icon: 'M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
  },
  {
    title: 'Gerçek Ürünler',
    description: 'Her parça satın alınabilir',
    icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9L12 21',
  },
  {
    title: 'Bütçe Kontrolü',
    description: 'Kategori dağılımı ve uyarılar',
    icon: 'M12 3a9 9 0 1 0 9 9h-9V3Z M14 3.5A9 9 0 0 1 20.5 10H14V3.5Z',
  },
  {
    title: 'Satın Alma',
    description: 'Çok satıcılı sepet, tek ödeme',
    icon: 'M3 5h2l2.2 10.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L20.5 8H6.2 M10 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
  },
  {
    title: 'Profesyoneller',
    description: 'İç mimar, montaj, müteahhit',
    icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm10 8.5v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 4.6a3.5 3.5 0 0 1 0 6.8',
  },
  {
    title: 'Proje Yönetimi',
    description: 'Zaman çizelgesi ve teslimat',
    icon: 'M8 3v3m8-3v3M4 9h16M5 5.5h14a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Zm3.5 8h2m3.5 0h2m-7.5 4h2m3.5 0h2',
  },
]

const steps = [
  { n: '01', title: 'Odanı yükle', body: 'Fotoğraf, kat planı veya ölçüler. Üçü de yeterli.' },
  { n: '02', title: 'Stilini ve bütçeni seç', body: 'Modern, minimal, İskandinav, sıcak çağdaş.' },
  { n: '03', title: 'Tasarımını al', body: 'Yapay zekâ odayı analiz eder ve önerisini üretir.' },
  { n: '04', title: 'Gerçek ürünlerle tamamla', body: 'Her parça eşleşir, sepete girer, kapına gelir.' },
]
</script>

<template>
  <div>
    <!--
      Hero: left value proposition + dark CTA, right product preview.
      Composed inside one warm panel, mirroring design_refs/ui_inspiration.jpg, rather
      than as a full-bleed split — the reference keeps the hero contained.
    -->
    <section class="rc-container pt-10 pb-24 lg:pt-14 lg:pb-32">
      <div
        class="relative rounded-2xl border border-line bg-surface px-6 pt-14 pb-24 sm:px-10 lg:px-14 lg:pt-20 lg:pb-24"
      >
        <div class="grid items-center gap-16 lg:grid-cols-[1fr_1fr] lg:gap-20">
          <div class="max-w-[560px]">
            <span
              class="inline-flex items-center gap-2 rounded-pill border border-line px-3.5 py-1.5 text-[11px] tracking-[0.12em] text-ink-secondary uppercase"
            >
              <span class="size-1.5 rounded-pill bg-gold" aria-hidden="true" />
              Yapay zekâ destekli iç mekân
            </span>

            <h1 class="rc-display mt-7 text-[40px] sm:text-[52px]">
              Odanı gör,<br>
              şekillendir, <span class="text-accent-600">yaşa</span>.
            </h1>

            <p class="mt-7 max-w-[46ch] text-[17px] leading-relaxed text-ink-secondary">
              Bir fotoğraf yükle; RefConcept odanı analiz etsin, sana özel bir tasarım
              üretsin ve her parçayı bütçene uyan gerçek ürünlerle eşleştirsin.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
              <RcButton to="/auth/register" size="lg">Projeni başlat</RcButton>
              <RcButton to="#nasil-calisir" variant="secondary" size="lg">Nasıl çalışır</RcButton>
            </div>

            <p class="mt-8 text-sm text-muted">
              Kredi ile çalışır · Kart bilgisi istemez · İlk tasarım ücretsiz
            </p>
          </div>

          <div class="mt-4 lg:mt-0 lg:pr-10">
            <RcDesignPreview />
          </div>
        </div>
      </div>
    </section>

    <!-- Capability strip: quiet row of line icons, no competing outlines. -->
    <section class="border-y border-line bg-bg-muted">
      <div class="rc-container py-16 lg:py-20">
        <div class="mx-auto mb-14 max-w-[52ch] text-center">
          <h2 class="text-[28px] sm:text-[34px]">Tasarımdan teslimata tek platform</h2>
          <p class="mt-3 text-ink-secondary">
            İlham aşamasından montaja kadar her adım aynı projenin içinde ilerler.
          </p>
        </div>

        <ul class="grid gap-x-6 gap-y-12 sm:grid-cols-3 lg:grid-cols-6">
          <li v-for="pillar in pillars" :key="pillar.title" class="text-center">
            <svg class="rc-icon mx-auto size-7 text-ink-secondary" viewBox="0 0 24 24" aria-hidden="true">
              <path :d="pillar.icon" />
            </svg>
            <p class="mt-4 text-sm font-medium">{{ pillar.title }}</p>
            <p class="mt-1.5 text-xs leading-relaxed text-muted">{{ pillar.description }}</p>
          </li>
        </ul>
      </div>
    </section>

    <!-- How it works -->
    <section id="nasil-calisir" class="rc-container py-20 lg:py-28">
      <div class="grid gap-14 lg:grid-cols-[minmax(0,380px)_1fr] lg:gap-24">
        <div>
          <h2 class="text-[28px] sm:text-[32px]">Nasıl çalışır</h2>
          <p class="mt-4 max-w-[38ch] leading-relaxed text-ink-secondary">
            Dört adım. Her adımda ne olduğunu, ne kadar krediye mal olduğunu ve sıradaki
            adımı görürsün.
          </p>
          <RcButton to="/auth/register" class="mt-8">Ücretsiz dene</RcButton>
        </div>

        <ol class="grid gap-x-10 gap-y-10 sm:grid-cols-2">
          <li v-for="step in steps" :key="step.n">
            <p class="text-xs tracking-[0.14em] text-accent-600">{{ step.n }}</p>
            <p class="mt-3 font-medium">{{ step.title }}</p>
            <p class="mt-2 text-sm leading-relaxed text-ink-secondary">{{ step.body }}</p>
          </li>
        </ol>
      </div>
    </section>

    <!-- Closing call to action -->
    <section class="rc-container pb-24 lg:pb-32">
      <div
        class="rounded-2xl bg-charcoal px-8 py-14 text-center sm:px-14"
      >
        <h2 class="rc-display text-[32px] text-white sm:text-[40px]">
          Odanı bugün şekillendir.
        </h2>
        <p class="mx-auto mt-5 max-w-[44ch] leading-relaxed text-neutral-300">
          Hesap oluştur, ilk odanı yükle ve tasarımını gör. Kredi kartı gerekmez.
        </p>
        <div class="mt-9 flex flex-wrap justify-center gap-3">
          <RcButton to="/auth/register" variant="inverse" size="lg">Hesap oluştur</RcButton>
          <RcButton to="/auth/login" variant="onDark" size="lg">Giriş yap</RcButton>
        </div>
      </div>
    </section>
  </div>
</template>
