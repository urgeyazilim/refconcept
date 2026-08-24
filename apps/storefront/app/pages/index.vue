<script setup lang="ts">
useHead({ title: 'Odanı gör, şekillendir, yaşa' })

/** Six pillars from 22_SCREEN_BLUEPRINTS §3 "Capability strip". */
const pillars = [
  {
    title: 'AI Tasarım',
    description: 'Odanın fotoğrafını yükle, saniyeler içinde sana ait bir tasarım önerisi al.',
    icon: 'M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
  },
  {
    title: 'Gerçek Ürünler',
    description: 'Tasarımdaki her parça, satın alınabilir gerçek bir ürüne eşlenir.',
    icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9L12 21',
  },
  {
    title: 'Bütçe Kontrolü',
    description: 'Kategori bazlı dağılım, kalan bütçe ve aşım uyarıları tek ekranda.',
    icon: 'M12 3a9 9 0 1 0 9 9h-9V3Z M14 3.5A9 9 0 0 1 20.5 10H14V3.5Z',
  },
  {
    title: 'Satın Alma',
    description: 'Çok satıcılı sepet, tek ödeme; iyzico, QNB veya banka havalesi.',
    icon: 'M3 5h2l2.2 10.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L20.5 8H6.2 M10 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
  },
  {
    title: 'Profesyoneller',
    description: 'İç mimar, müteahhit ve montaj ekipleri projene bağlı çalışır.',
    icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm10 8.5v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 4.6a3.5 3.5 0 0 1 0 6.8',
  },
  {
    title: 'Proje Yönetimi',
    description: 'Zaman çizelgesi, onaylar, teslimat ve montaj takibi bir arada.',
    icon: 'M8 3v3m8-3v3M4 9h16M5 5.5h14a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Zm3.5 8h2m3.5 0h2m-7.5 4h2m3.5 0h2',
  },
]
</script>

<template>
  <div>
    <!-- Hero: left value proposition + dark CTA, right premium interior visual (spec §8.1) -->
    <section class="rc-container grid items-center gap-14 py-16 lg:grid-cols-[1.05fr_1fr] lg:py-24">
      <div class="max-w-[620px]">
        <span
          class="mb-6 inline-flex items-center gap-2 rounded-pill border border-line bg-surface px-3.5 py-1.5 text-xs tracking-wide text-ink-secondary uppercase"
        >
          <span class="size-1.5 rounded-pill bg-gold" aria-hidden="true" />
          Yapay zekâ destekli iç mekân
        </span>

        <h1 class="text-4xl font-medium text-balance sm:text-5xl lg:text-6xl">
          Odanı gör, şekillendir,
          <span class="text-accent-600">yaşa</span>.
        </h1>

        <p class="mt-6 max-w-[52ch] text-lg leading-relaxed text-ink-secondary">
          Bir fotoğraf yükle; RefConcept odanı analiz etsin, sana özel bir tasarım üretsin ve
          her parçayı bütçene uyan gerçek ürünlerle eşleştirsin.
        </p>

        <div class="mt-9 flex flex-wrap items-center gap-3">
          <NuxtLink
            to="/auth/register"
            class="rounded-pill bg-charcoal px-7 py-3.5 text-sm font-medium text-inverse transition-colors hover:bg-neutral-800"
          >
            Projeni başlat
          </NuxtLink>
          <NuxtLink
            to="/"
            class="rounded-pill border border-line-strong px-7 py-3.5 text-sm font-medium text-ink transition-colors hover:bg-bg-muted"
          >
            Ürünleri keşfet
          </NuxtLink>
        </div>

        <div class="mt-10">
          <RcSystemStatus />
        </div>
      </div>

      <!-- Interior visual placeholder: replaced with approved photography in Phase 20. -->
      <div class="relative">
        <div
          class="aspect-4/3 w-full rounded-xl border border-line bg-linear-to-br from-neutral-100 via-neutral-50 to-accent-50 shadow-lg"
        />
        <!-- Floating AI progress card (spec §8.1) -->
        <div class="rc-card absolute -bottom-8 left-6 w-[280px] p-5 shadow-lg sm:left-10">
          <p class="text-xs tracking-wide text-muted uppercase">AI tasarım</p>
          <p class="mt-1.5 text-sm font-medium">Oturma odası · Warm Minimal</p>
          <div class="mt-4 h-1.5 w-full overflow-hidden rounded-pill bg-neutral-150">
            <div class="h-full w-2/3 rounded-pill bg-gold" />
          </div>
          <p class="mt-3 text-xs text-muted">14 ürün eşleşti · bütçenin %68'i</p>
        </div>
      </div>
    </section>

    <!-- Capability strip -->
    <section class="rc-section rc-section--muted mt-12">
      <div class="rc-container">
        <div class="mb-12 max-w-[56ch]">
          <h2 class="text-3xl font-medium">Tasarımdan teslimata tek platform</h2>
          <p class="mt-4 text-ink-secondary">
            İlham aşamasından montaja kadar her adım aynı projenin içinde ilerler.
          </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <RcPillarCard
            v-for="pillar in pillars"
            :key="pillar.title"
            :title="pillar.title"
            :description="pillar.description"
            :icon="pillar.icon"
          />
        </div>
      </div>
    </section>
  </div>
</template>
