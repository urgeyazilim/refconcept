<script setup lang="ts">
/**
 * Super Admin shell — operational density, same design family.
 *
 * Ordered the way a shift is actually worked rather than by importance: the dashboard
 * first because it says what is waiting, then the queues somebody clears, then the
 * records they consult, and the platform's own switches last. Sistem sits at the bottom
 * because an operator cannot open it at all — the matrix reserves it for a super admin,
 * and a link they can only be refused from is worse than no link.
 */
const { isAuthenticated, logout } = useAuth()

const nav = [
  { label: 'Gösterge paneli', to: '/analytics', icon: 'M4 19V5m0 14h16M8 19v-6m4 6V9m4 10v-4' },
  { label: 'Başvurular', to: '/', icon: 'M8 4h8a1 1 0 0 1 1 1v15l-5-3-5 3V5a1 1 0 0 1 1-1Zm2 5h4m-4 4h4' },
  { label: 'Satıcılar', to: '/sellers', icon: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z' },
  { label: 'Ürünler', to: '/products', icon: 'M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9' },
  { label: 'AI', to: '/ai', icon: 'M12 3v2m0 14v2m9-9h-2M5 12H3m14.5-6.5-1.4 1.4M7.9 16.1l-1.4 1.4m11.6 0-1.4-1.4M7.9 7.9 6.5 6.5M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z' },
  { label: 'Krediler', to: '/credits', icon: 'M3 8.5h18M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5m-18 0v8A1.5 1.5 0 0 0 4.5 18h15a1.5 1.5 0 0 0 1.5-1.5v-8M7 14h3' },
  { label: 'Ödemeler', to: '/payments', icon: 'M4 6h16v12H4zM4 10h16M8 15h4' },
  { label: 'Finans', to: '/finance', icon: 'M12 3v18M8 7h6a2 2 0 0 1 0 4h-4a2 2 0 0 0 0 4h6' },
  { label: 'Siparişler', to: '/orders', icon: 'M6 2h9l3 3v17H6zM9 9h6M9 13h6M9 17h4' },
  { label: 'Denetim', to: '/audit', icon: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm5 12 4 4' },
  { label: 'Sistem', to: '/system', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7.5 7.5 0 0 0-2-1.2l-.4-2.5h-4l-.4 2.5c-.7.3-1.4.7-2 1.2l-2.3-1-2 3.4 2 1.5a7.4 7.4 0 0 0 0 2.4l-2 1.5 2 3.4 2.3-1c.6.5 1.3.9 2 1.2l.4 2.5h4l.4-2.5c.7-.3 1.4-.7 2-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2Z' },
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
