<script setup lang="ts">
/**
 * Super Admin shell — operational density, same design family.
 * Full navigation is built out in Phase 18; these are the section anchors.
 */
const nav = [
  { label: 'Genel Bakış', to: '/', icon: 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z' },
  { label: 'Satıcılar', to: '/', icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z' },
  { label: 'Ürün Moderasyon', to: '/', icon: 'M9 12.5l2.2 2.2L15.5 10M12 3l7.5 3.5v5c0 4.5-3 8-7.5 9.5-4.5-1.5-7.5-5-7.5-9.5v-5L12 3Z' },
  { label: 'Siparişler', to: '/', icon: 'M6 4h12l1.5 16.5H4.5L6 4Zm3 4a3 3 0 0 0 6 0' },
  { label: 'Ödemeler', to: '/', icon: 'M3 7.5h18v9H3v-9Zm0 3.5h18M6.5 14h3' },
  { label: 'Krediler', to: '/', icon: 'M12 3v18M8 7h6a2.5 2.5 0 0 1 0 5h-4a2.5 2.5 0 0 0 0 5h6' },
  { label: 'AI Kontrol', to: '/', icon: 'M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z' },
  { label: 'Muhasebe / Ledger', to: '/', icon: 'M5 4h11l3 3v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm3 6h8m-8 4h8m-8 4h5' },
  { label: 'Hakediş', to: '/', icon: 'M4 18V9m5 9V5m5 13v-6m5 6V7' },
  { label: 'Denetim Kaydı', to: '/', icon: 'M12 3 4 6.5v5c0 4.5 3.2 8.2 8 9.5 4.8-1.3 8-5 8-9.5v-5L12 3Zm0 5v4m0 3h.01' },
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
          <p class="text-[11px] text-muted">Süper Admin</p>
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
        <p class="text-sm text-muted">Süper Admin</p>
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
