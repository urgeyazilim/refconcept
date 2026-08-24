<script setup lang="ts">
useHead({
  title: 'Odanı gör, şekillendir, yaşa',
  meta: [
    {
      name: 'description',
      content:
        'RefConcept odanın fotoğrafını yapay zekâ ile analiz eder, sana özel bir tasarım '
        + 'üretir ve tasarımdaki her parçayı bütçene uyan gerçek ürünlerle eşleştirir.',
    },
  ],
})

/** The six pillars from 22_SCREEN_BLUEPRINTS §3, each saying what it actually does. */
const pillars = [
  {
    title: 'AI Tasarım',
    description: 'Odanın fotoğrafını yükle; yapay zekâ mekânı analiz etsin ve stiline uygun bir tasarım üretsin.',
    icon: 'M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
  },
  {
    title: 'Gerçek Ürünler',
    description: 'Tasarımdaki her parça, platformdaki satıcıların stokta olan gerçek ürünlerine eşlenir.',
    icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9L12 21',
  },
  {
    title: 'Bütçe Kontrolü',
    description: 'Bütçeni baştan söyle. Kategori dağılımını, kalanı ve aşım uyarılarını tek ekranda gör.',
    icon: 'M12 3a9 9 0 1 0 9 9h-9V3Z M14 3.5A9 9 0 0 1 20.5 10H14V3.5Z',
  },
  {
    title: 'Tek Sepet',
    description: 'Farklı satıcılardan seçtiğin ürünler tek sepette toplanır, tek ödemeyle satın alınır.',
    icon: 'M3 5h2l2.2 10.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L20.5 8H6.2 M10 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
  },
  {
    title: 'Profesyoneller',
    description: 'İç mimar, müteahhit ve montaj ekipleri aynı projenin içinden çalışır.',
    icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm10 8.5v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 4.6a3.5 3.5 0 0 1 0 6.8',
  },
  {
    title: 'Proje Yönetimi',
    description: 'Zaman çizelgesi, onaylar, teslimat ve montaj takibi tek yerde ilerler.',
    icon: 'M8 3v3m8-3v3M4 9h16M5 5.5h14a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Zm3.5 8h2m3.5 0h2m-7.5 4h2m3.5 0h2',
  },
]

const rooms = [
  { name: 'Oturma Odası', image: '/images/room-living.webp' },
  { name: 'Yatak Odası', image: '/images/room-bedroom.webp' },
  { name: 'Mutfak', image: '/images/room-kitchen.webp' },
  { name: 'Yemek Odası', image: '/images/room-dining.webp' },
]

const products = [
  { name: 'Modüler kanepe', brand: 'Atlas Mobilya', price: '48.900 ₺', image: '/images/product-sofa.webp' },
  { name: 'Meşe sehpa', brand: 'Nova Yaşam', price: '7.250 ₺', image: '/images/product-table.webp' },
  { name: 'Yün halı 200×300', brand: 'Atlas Mobilya', price: '12.400 ₺', image: '/images/product-rug.webp' },
]

const steps = [
  {
    n: '01',
    title: 'Odanı yükle',
    body: 'Telefonla çektiğin bir fotoğraf yeter. Kat planı yükleyebilir ya da ölçüleri elle girebilirsin.',
  },
  {
    n: '02',
    title: 'Stilini ve bütçeni seç',
    body: 'Modern, minimal, İskandinav, sıcak çağdaş… Bütçeni söyle, tasarım o aralıkta kurulsun.',
  },
  {
    n: '03',
    title: 'Tasarımını gör',
    body: 'Yapay zekâ odayı analiz eder, yerleşimi kurar ve sana özel bir konsept üretir.',
  },
  {
    n: '04',
    title: 'Gerçek ürünlerle tamamla',
    body: 'Her parça satın alınabilir bir ürüne eşleşir. Sepete ekle, tek ödemeyle satın al.',
  },
]

const faqs = [
  {
    q: 'Tasarım ne kadar sürüyor?',
    a: 'Oda fotoğrafını yükledikten sonra analiz ve tasarım üretimi birkaç dakika sürer. '
      + 'İşlem arka planda çalışır; hazır olduğunda size bildiririz, ekranı açık tutmanız gerekmez.',
  },
  {
    q: 'Kredi nasıl çalışıyor?',
    a: 'Her tasarım üretimi kredi harcar. Tasarım başlamadan önce kredi rezerve edilir; '
      + 'üretim başarısız olursa rezerve edilen kredi otomatik iade edilir. Kullanılmayan krediniz durur.',
  },
  {
    q: 'Ürünleri satın almak zorunda mıyım?',
    a: 'Hayır. Tasarım ve eşleşen ürün listesi sizindir; dilediğiniz parçayı alır, dilediğinizi '
      + 'çıkarırsınız. Her parça için alternatifleri karşılaştırabilirsiniz.',
  },
  {
    q: 'Ürünleri kim satıyor?',
    a: 'Ürünler platformda onaylı bağımsız satıcılara aittir. RefConcept aracı hizmet sağlayıcıdır; '
      + 'teslimat ve garanti yükümlülüğü ilgili satıcıdadır. Farklı satıcılardan aldığınız ürünler '
      + 'tek sepette birleşir.',
  },
  {
    q: 'Odamın fotoğrafı nerede saklanıyor?',
    a: 'Yüklediğiniz görseller özel depolamada tutulur ve yalnızca imzalı bağlantılarla erişilir. '
      + 'KVKK kapsamında verilerinizi silme hakkınız saklıdır.',
  },
]

const openFaq = ref<number | null>(0)
</script>

<template>
  <div>
    <!--
      Hero: interior photograph with the value proposition overlaid on the left,
      mirroring design_refs/hero_room.jpg. The photograph is composed with an empty
      left third so the copy sits on plain wall rather than on busy detail.
    -->
    <section class="rc-container pt-6 lg:pt-8">
      <div class="relative overflow-hidden rounded-2xl">
        <img
          src="/images/hero-living-room.webp"
          alt="Doğal ışık alan, sıcak nötr tonlarda modern bir oturma odası"
          class="h-[520px] w-full object-cover sm:h-[600px] lg:h-[680px]"
          fetchpriority="high"
          decoding="async"
        >

        <!-- Legibility wash: keeps the copy readable without dulling the photograph. -->
        <div
          class="absolute inset-0 bg-linear-to-r from-neutral-950/72 via-neutral-950/35 to-transparent"
          aria-hidden="true"
        />

        <div class="absolute inset-0 flex items-center">
          <div class="w-full px-7 sm:px-12 lg:px-16">
            <div class="max-w-[560px]">
              <span
                class="inline-flex items-center gap-2 rounded-pill bg-white/15 px-3.5 py-1.5 text-[11px] tracking-[0.14em] text-white uppercase backdrop-blur"
              >
                <span class="size-1.5 rounded-pill bg-sand" aria-hidden="true" />
                Yapay zekâ destekli iç mekân
              </span>

              <h1 class="rc-display mt-6 text-[38px] text-white sm:text-[52px] lg:text-[60px]">
                Odanı gör,<br>
                şekillendir, <span class="text-sand">yaşa</span>.
              </h1>

              <p class="mt-6 max-w-[44ch] text-[17px] leading-relaxed text-neutral-200">
                Odanın fotoğrafını yükle. Yapay zekâ mekânı analiz etsin, sana özel bir
                tasarım üretsin ve her parçayı bütçene uyan gerçek ürünlerle eşleştirsin.
              </p>

              <div class="mt-9 flex flex-wrap items-center gap-3">
                <RcButton to="/auth/register" variant="inverse" size="lg">Projeni başlat</RcButton>
                <RcButton to="#nasil-calisir" variant="onDark" size="lg">Nasıl çalışır</RcButton>
              </div>

              <p class="mt-7 text-sm text-neutral-300">
                Kredi ile çalışır · Kart bilgisi istemez · İlk tasarım ücretsiz
              </p>
            </div>
          </div>
        </div>

        <!-- Floating design card, as in the approved reference. -->
        <div class="rc-card absolute right-6 bottom-6 hidden w-[300px] p-5 shadow-lg lg:block">
          <div class="flex items-center gap-3">
            <RcFeatureIcon
              size="sm"
              icon="M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"
            />
            <div class="min-w-0">
              <p class="text-[11px] tracking-wide text-muted uppercase">AI tasarım</p>
              <p class="truncate text-sm font-medium">Oturma odası · Warm Minimal</p>
            </div>
          </div>

          <div class="mt-4 h-1.5 w-full overflow-hidden rounded-pill bg-neutral-150">
            <div class="h-full w-2/3 rounded-pill bg-gold" />
          </div>
          <p class="mt-3 text-xs text-muted">14 ürün eşleşti · bütçenin %68'i</p>
        </div>
      </div>
    </section>

    <!-- What RefConcept actually is -->
    <section class="rc-container py-20 lg:py-28">
      <div class="mx-auto max-w-[62ch] text-center">
        <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">RefConcept nedir?</p>
        <h2 class="mt-5 text-[28px] sm:text-[36px]">
          İlham panosu değil, satın alınabilir bir tasarım.
        </h2>
        <p class="mt-6 text-[17px] leading-relaxed text-ink-secondary">
          Çoğu tasarım aracı size güzel bir görsel verir ve orada bırakır. RefConcept
          tasarımı üretir, sonra o tasarımdaki her parçayı platformdaki satıcıların stokta
          olan ürünleriyle eşleştirir — bütçenizi, ölçülerinizi ve stilinizi gözeterek.
          Ekranda gördüğünüz oda, sipariş verebileceğiniz bir odaya dönüşür.
        </p>
      </div>

      <ul class="mt-16 grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="pillar in pillars" :key="pillar.title">
          <RcFeatureIcon :icon="pillar.icon" />
          <p class="mt-5 text-lg font-medium">{{ pillar.title }}</p>
          <p class="mt-2.5 leading-relaxed text-ink-secondary">{{ pillar.description }}</p>
        </li>
      </ul>
    </section>

    <!-- How it works, shown rather than only told -->
    <section id="nasil-calisir" class="border-y border-line bg-bg-muted">
      <div class="rc-container py-20 lg:py-28">
        <div class="mx-auto mb-16 max-w-[52ch] text-center">
          <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">Nasıl çalışır</p>
          <h2 class="mt-5 text-[28px] sm:text-[36px]">Dört adımda bitiyor</h2>
        </div>

        <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
          <div class="grid gap-6 sm:grid-cols-2">
            <figure>
              <img
                src="/images/before-room.webp"
                alt="Mobilyasız, sade bir oturma odası — tasarım öncesi"
                class="aspect-4/3 w-full rounded-lg border border-line object-cover"
                loading="lazy"
                decoding="async"
              >
              <figcaption class="mt-3 text-xs tracking-wide text-muted uppercase">
                Öncesi · senin odan
              </figcaption>
            </figure>

            <figure>
              <img
                src="/images/room-living.webp"
                alt="Aynı odanın yapay zekâ ile tasarlanmış hâli"
                class="aspect-4/3 w-full rounded-lg border border-line object-cover"
                loading="lazy"
                decoding="async"
              >
              <figcaption class="mt-3 text-xs tracking-wide text-accent-700 uppercase">
                Sonrası · RefConcept
              </figcaption>
            </figure>
          </div>

          <ol class="space-y-9">
            <li v-for="step in steps" :key="step.n" class="flex gap-5">
              <span
                class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-pill border border-line-strong text-xs tracking-wide"
              >
                {{ step.n }}
              </span>
              <div>
                <p class="font-medium">{{ step.title }}</p>
                <p class="mt-1.5 leading-relaxed text-ink-secondary">{{ step.body }}</p>
              </div>
            </li>
          </ol>
        </div>
      </div>
    </section>

    <!-- Room types -->
    <section class="rc-container py-20 lg:py-28">
      <div class="mb-12 flex flex-wrap items-end justify-between gap-6">
        <div class="max-w-[46ch]">
          <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">Odalar</p>
          <h2 class="mt-5 text-[28px] sm:text-[36px]">Hangi odayı tasarlayalım?</h2>
          <p class="mt-4 leading-relaxed text-ink-secondary">
            Tek bir odayla başlayın, sonra tüm eve genişletin. Her oda kendi bütçesi ve
            zaman çizelgesiyle projenizin içinde durur.
          </p>
        </div>
        <RcButton to="/auth/register" variant="secondary">Odanı yükle</RcButton>
      </div>

      <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <li v-for="room in rooms" :key="room.name" class="group">
          <div class="overflow-hidden rounded-lg border border-line">
            <img
              :src="room.image"
              :alt="`${room.name} tasarım örneği`"
              class="aspect-4/3 w-full object-cover transition-transform duration-[--rc-duration-slow] group-hover:scale-[1.03]"
              loading="lazy"
              decoding="async"
            >
          </div>
          <p class="mt-3.5 font-medium">{{ room.name }}</p>
        </li>
      </ul>
    </section>

    <!-- Real products + budget -->
    <section class="border-y border-line bg-bg-muted">
      <div class="rc-container py-20 lg:py-28">
        <div class="grid gap-14 lg:grid-cols-[1fr_minmax(0,400px)] lg:gap-20">
          <div>
            <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">Gerçek ürünler</p>
            <h2 class="mt-5 max-w-[20ch] text-[28px] sm:text-[36px]">
              Tasarımdaki her parça satın alınabilir
            </h2>
            <p class="mt-5 max-w-[52ch] leading-relaxed text-ink-secondary">
              Yapay zekâ tasarımdaki nesneleri tanır, ölçü ve malzeme özelliklerini çıkarır
              ve satıcı kataloğunda eşleşen ürünleri bulur. Beğenmediğiniz parçanın
              alternatiflerini görür, bütçenize göre değiştirirsiniz.
            </p>

            <ul class="mt-10 space-y-3">
              <li
                v-for="product in products"
                :key="product.name"
                class="rc-card flex items-center gap-4 p-3.5"
              >
                <img
                  :src="product.image"
                  :alt="product.name"
                  class="size-16 shrink-0 rounded-sm bg-neutral-50 object-cover"
                  loading="lazy"
                  decoding="async"
                >
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium">{{ product.name }}</span>
                  <span class="block text-sm text-muted">{{ product.brand }}</span>
                </span>
                <span class="shrink-0 tabular-nums">{{ product.price }}</span>
              </li>
            </ul>
          </div>

          <div class="rc-card flex flex-col items-center p-8 text-center lg:p-10">
            <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">Bütçe</p>
            <h3 class="mt-4 text-xl">Bütçeni aşmadan</h3>

            <div class="my-8">
              <RcBudgetRing :percent="68" :size="180" :thickness="15" caption="kullanıldı" />
            </div>

            <dl class="w-full space-y-3 text-sm">
              <div class="flex items-center justify-between border-b border-line pb-3">
                <dt class="text-muted">Toplam bütçe</dt>
                <dd class="tabular-nums">120.000 ₺</dd>
              </div>
              <div class="flex items-center justify-between border-b border-line pb-3">
                <dt class="text-muted">Seçilen ürünler</dt>
                <dd class="tabular-nums">81.600 ₺</dd>
              </div>
              <div class="flex items-center justify-between">
                <dt class="text-muted">Kalan</dt>
                <dd class="tabular-nums text-success-strong">38.400 ₺</dd>
              </div>
            </dl>

            <p class="mt-8 text-sm leading-relaxed text-ink-secondary">
              Kategori bazlı dağılımı görür, aşım olduğunda uyarı alırsınız.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="rc-container py-20 lg:py-28">
      <div class="grid gap-12 lg:grid-cols-[minmax(0,340px)_1fr] lg:gap-20">
        <div>
          <p class="text-xs tracking-[0.16em] text-accent-700 uppercase">Sık sorulanlar</p>
          <h2 class="mt-5 text-[28px] sm:text-[36px]">Merak edilenler</h2>
          <p class="mt-4 leading-relaxed text-ink-secondary">
            Aradığınızı bulamazsanız hesabınızdan bize yazabilirsiniz.
          </p>
        </div>

        <dl class="divide-y divide-line border-y border-line">
          <div v-for="(faq, index) in faqs" :key="faq.q">
            <dt>
              <button
                type="button"
                class="flex w-full items-center justify-between gap-6 py-5 text-left"
                :aria-expanded="openFaq === index"
                @click="openFaq = openFaq === index ? null : index"
              >
                <span class="font-medium">{{ faq.q }}</span>
                <svg
                  class="rc-icon size-5 shrink-0 text-muted transition-transform duration-[--rc-duration-fast]"
                  :class="openFaq === index ? 'rotate-45' : ''"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </button>
            </dt>
            <dd v-if="openFaq === index" class="max-w-[62ch] pb-6 leading-relaxed text-ink-secondary">
              {{ faq.a }}
            </dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- Closing call to action -->
    <section class="rc-container pb-24 lg:pb-32">
      <div class="relative overflow-hidden rounded-2xl">
        <img
          src="/images/room-dining.webp"
          alt=""
          class="h-[380px] w-full object-cover sm:h-[420px]"
          loading="lazy"
          decoding="async"
        >
        <div class="absolute inset-0 bg-neutral-950/60" aria-hidden="true" />

        <div class="absolute inset-0 flex flex-col items-center justify-center px-8 text-center">
          <h2 class="rc-display max-w-[18ch] text-[30px] text-white sm:text-[40px]">
            Odanı bugün şekillendir.
          </h2>
          <p class="mt-5 max-w-[46ch] leading-relaxed text-neutral-200">
            Hesap oluştur, ilk odanı yükle ve tasarımını gör. Kredi kartı gerekmez.
          </p>
          <div class="mt-9 flex flex-wrap justify-center gap-3">
            <RcButton to="/auth/register" variant="inverse" size="lg">Hesap oluştur</RcButton>
            <RcButton to="/auth/login" variant="onDark" size="lg">Giriş yap</RcButton>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
