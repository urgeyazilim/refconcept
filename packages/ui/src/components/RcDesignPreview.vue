<script setup lang="ts">
/**
 * The hero's product preview.
 *
 * The approved references put a premium interior photograph here. Until licensed
 * photography exists, an empty grey box is the worst possible stand-in — it reads as
 * a broken image and drags the whole page down. This shows the actual product output
 * instead: the design result, its matched products and the budget ring.
 *
 * That is honest (nothing is faked as a photo), it is on-brand, and every piece of it
 * becomes a real component later: the ring is already RcBudgetRing, and the product
 * rows take the same shape the matching engine returns in Phase 9.
 *
 * When photography lands, this component gets an `image` prop and the composition
 * moves on top of it, exactly as in `design_refs/hero_room.jpg`.
 */

interface MatchedProduct {
  name: string
  brand: string
  price: string
}

const products: MatchedProduct[] = [
  { name: 'Modüler kanepe', brand: 'Atlas', price: '48.900 ₺' },
  { name: 'Meşe sehpa', brand: 'Nova', price: '7.250 ₺' },
  { name: 'Yün halı 200×300', brand: 'Atlas', price: '12.400 ₺' },
]
</script>

<template>
  <div class="relative">
    <!-- The "room" plate: a soft architectural wash rather than a fake photograph. -->
    <div
      class="relative aspect-4/3 w-full overflow-hidden rounded-xl border border-line bg-linear-160 from-neutral-200 via-neutral-100 to-accent-50"
    >
      <!-- Thin architectural lines: horizon, wall break and a suggestion of daylight. -->
      <svg class="absolute inset-0 size-full" viewBox="0 0 400 300" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 196 H400" stroke="var(--rc-neutral-300)" stroke-width="0.75" fill="none" />
        <path d="M262 0 V196" stroke="var(--rc-neutral-300)" stroke-width="0.75" fill="none" />
        <path d="M262 44 H400" stroke="var(--rc-neutral-300)" stroke-width="0.75" fill="none" />
        <rect x="286" y="66" width="92" height="108" fill="var(--rc-neutral-0)" opacity="0.55" />
      </svg>

      <span
        class="absolute top-5 left-5 inline-flex items-center gap-2 rounded-pill bg-surface/90 px-3 py-1.5 text-xs font-medium backdrop-blur"
      >
        <svg class="rc-icon size-3.5 text-accent-600" viewBox="0 0 24 24">
          <path d="M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" />
        </svg>
        Oturma odası · Warm Minimal
      </span>
    </div>

    <!-- Matched products, overlapping the plate the way the reference card does. -->
    <div class="rc-card absolute -bottom-10 -left-4 w-[300px] p-5 shadow-lg sm:-left-8">
      <div class="mb-3 flex items-center justify-between">
        <p class="text-xs tracking-wide text-muted uppercase">Eşleşen ürünler</p>
        <span class="text-xs text-muted">14</span>
      </div>

      <ul class="space-y-3">
        <li v-for="product in products" :key="product.name" class="flex items-center gap-3">
          <span class="size-9 shrink-0 rounded-sm bg-neutral-100" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm">{{ product.name }}</span>
            <span class="block text-xs text-muted">{{ product.brand }}</span>
          </span>
          <span class="shrink-0 text-sm tabular-nums">{{ product.price }}</span>
        </li>
      </ul>
    </div>

    <!-- Budget ring, mirrored from the approved dashboard reference. -->
    <div class="rc-card absolute -right-4 -bottom-6 hidden p-5 shadow-lg lg:block">
      <RcBudgetRing :percent="68" :size="120" :thickness="11" caption="bütçe" />
    </div>
  </div>
</template>
