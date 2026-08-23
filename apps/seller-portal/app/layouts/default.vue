<script setup lang="ts">
/**
 * Seller Portal shell — same design family as the storefront but more operational:
 * persistent left navigation, denser type, calmer surfaces (design spec §15).
 */
const nav = [
  { label: 'Panel', to: '/', icon: 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z' },
  { label: 'Ürünler', to: '/', icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9' },
  { label: 'Siparişler', to: '/', icon: 'M6 4h12l1.5 16.5H4.5L6 4Zm3 4a3 3 0 0 0 6 0' },
  { label: 'Kargo', to: '/', icon: 'M3 7h11v10H3V7Zm11 3h4l3 3v4h-7v-7ZM7 20a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z' },
  { label: 'İadeler', to: '/', icon: 'M9 5 4 10l5 5M4 10h9a7 7 0 0 1 7 7v2' },
  { label: 'Finans', to: '/', icon: 'M12 3v18M8 7h6a2.5 2.5 0 0 1 0 5h-4a2.5 2.5 0 0 0 0 5h6' },
]
</script>

<template>
  <div data-rc-theme="operational" class="flex min-h-screen bg-bg text-ink">
    <aside class="hidden w-64 shrink-0 border-r border-line bg-surface lg:block">
      <div class="flex h-16 items-center gap-2.5 border-b border-line px-6">
        <span class="grid size-8 place-items-center rounded-sm bg-charcoal text-inverse text-xs font-medium">
          RC
        </span>
        <div class="leading-tight">
          <p class="text-sm font-medium">RefConcept</p>
          <p class="text-[11px] text-muted">Satıcı Paneli</p>
        </div>
      </div>

      <nav class="flex flex-col gap-0.5 p-3">
        <NuxtLink
          v-for="item in nav"
          :key="item.label"
          :to="item.to"
          class="flex items-center gap-3 rounded-sm px-3 py-2.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
        >
          <svg class="rc-icon size-[18px]" viewBox="0 0 24 24" aria-hidden="true">
            <path :d="item.icon" />
          </svg>
          {{ item.label }}
        </NuxtLink>
      </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 items-center justify-between gap-4 border-b border-line bg-surface px-6">
        <p class="text-sm text-muted">Satıcı Paneli</p>
        <span class="rounded-pill border border-line px-3 py-1 text-xs text-ink-secondary">
          Phase 0 · bootstrap
        </span>
      </header>

      <main class="flex-1 p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
