<script setup lang="ts">
/**
 * Seller Portal shell — same design family as the storefront but more operational:
 * persistent left navigation, denser type, calmer surfaces (design spec §15).
 */
const { isAuthenticated, logout } = useAuth()

const nav = [
  { label: 'Panel', to: '/', icon: 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z' },
  { label: 'Siparişlerim', to: '/orders', icon: 'M6 2h9l3 3v17H6zM9 8h6M9 12h6M9 16h4' },
  { label: 'Kargo', to: '/shipping', icon: 'M3 8h13v9H3zM16 11h3l2 3v3h-5zM7 20a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm11 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z' },
  { label: 'İadeler', to: '/returns', icon: 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3' },
  { label: 'Hakedişlerim', to: '/earnings', icon: 'M12 3v18M8 7h6a2 2 0 0 1 0 4h-4a2 2 0 0 0 0 4h6' },
  { label: 'Ürünlerim', to: '/products', icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9' },
  { label: 'Fiyatlar', to: '/prices', icon: 'M7 7h.01M3 10V5a1 1 0 0 1 1-1h5l11 11-6 6L3 10Z' },
  { label: 'Stok', to: '/stock', icon: 'M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Zm0 0 9 4.5m0 0 9-4.5m-9 4.5V21' },
  { label: 'Toplu aktarma', to: '/imports', icon: 'M12 15V4m0 0L8 8m4-4 4 4M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2' },
  { label: 'Entegrasyonlar', to: '/integrations', icon: 'M15 7a4 4 0 1 1-3.9 5H7v3H4v-3.1A4 4 0 0 1 11.1 8H15Z' },
  { label: 'Ekibim', to: '/team', icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm10 8.5v-1.5a3.5 3.5 0 0 0-2.6-3.4M15 4.6a3.5 3.5 0 0 1 0 6.8' },
  { label: 'Başvurum', to: '/onboarding', icon: 'M8 4h8a1 1 0 0 1 1 1v15l-5-3-5 3V5a1 1 0 0 1 1-1Zm2 5h4m-4 4h4' },
]
</script>

<template>
  <div data-rc-theme="operational" class="flex min-h-screen bg-bg text-ink">
    <!--
      First in the DOM and visible only when focused. An operator working a queue by
      keyboard should not tab through the whole sidebar to reach the row they came for.
    -->
    <a
      href="#main"
      class="sr-only rounded-sm bg-charcoal px-4 py-2 text-inverse focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60]"
    >
      İçeriğe geç
    </a>

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
        <button
          v-if="isAuthenticated"
          type="button"
          class="rounded-sm px-3 py-1.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink"
          @click="logout"
        >
          Çıkış yap
        </button>
        <NuxtLink
          v-else
          to="/auth/login"
          class="rounded-sm px-3 py-1.5 text-sm text-ink-secondary hover:bg-bg-muted hover:text-ink"
        >
          Giriş yap
        </NuxtLink>
      </header>

      <main id="main" class="flex-1 p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
