<script setup lang="ts">
/**
 * The storefront shell.
 *
 * Two things here are not decoration. The **skip link** is the first focusable element on
 * every page, because a keyboard user should not have to tab through the whole header to
 * reach an article they arrived for. And the **mobile menu** exists at all: the desktop nav
 * is hidden below `lg`, and without an alternative a phone visitor could see the logo and a
 * sign-up button and no way to reach the catalogue — which is most of the traffic and all
 * of the shopping.
 *
 * The drawer is a real dialog: focus moves into it, Escape closes it, and the page behind
 * it does not scroll. A menu that leaves focus on the page underneath is a menu a screen
 * reader user cannot use and a sighted keyboard user gets lost behind.
 */
const { isAuthenticated, displayName, logout } = useAuth()

const nav = [
  { label: 'Ürünler', to: '/catalog' },
  { label: 'Projelerim', to: '/projects' },
  { label: 'Favorilerim', to: '/favorites' },
  { label: 'Krediler', to: '/account/credits' },
]

const menuOpen = ref(false)
const drawerOpen = ref(false)
const drawer = ref<HTMLElement | null>(null)

// Close both on navigation; a menu left hanging over the next page reads as a stuck
// overlay rather than as a menu.
const route = useRoute()
watch(() => route.fullPath, () => {
  menuOpen.value = false
  drawerOpen.value = false
})

/*
 * The page behind a full-screen drawer must not scroll. On a phone the symptom is
 * subtle and maddening: the menu looks stuck while the article behind it moves.
 */
watch(drawerOpen, async (open) => {
  if (import.meta.server) {
    return
  }

  document.body.style.overflow = open ? 'hidden' : ''

  if (open) {
    await nextTick()
    drawer.value?.querySelector<HTMLElement>('a, button')?.focus()
  }
})

onBeforeUnmount(() => {
  if (import.meta.client) {
    document.body.style.overflow = ''
  }
})

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    drawerOpen.value = false
    menuOpen.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-bg text-ink" @keydown="onKeydown">
    <!--
      First in the DOM and visible only when focused. A keyboard user arriving on an
      article should not have to tab through a header to read it.
    -->
    <a
      href="#main"
      class="sr-only rounded-sm bg-charcoal px-4 py-2 text-inverse focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60]"
    >
      İçeriğe geç
    </a>

    <header class="sticky top-0 z-50 border-b border-line/70 bg-bg/85 backdrop-blur">
      <div class="rc-container flex h-18 items-center justify-between gap-4 py-4 lg:gap-8">
        <div class="flex items-center gap-2">
          <!-- The phone's way in. Absent, this header offers a logo and a sign-up button. -->
          <button
            type="button"
            class="-ml-2 grid size-10 place-items-center rounded-sm text-ink-secondary hover:bg-bg-muted hover:text-ink lg:hidden"
            :aria-expanded="drawerOpen"
            aria-controls="mobile-menu"
            aria-label="Menüyü aç"
            data-testid="menu-open"
            @click="drawerOpen = true"
          >
            <svg class="rc-icon size-6" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>

          <NuxtLink to="/" class="flex items-center gap-2.5">
            <span class="grid size-9 place-items-center rounded-md bg-charcoal text-inverse">
              <svg class="rc-icon size-5" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" />
              </svg>
            </span>
            <span class="text-lg font-medium tracking-tight">RefConcept</span>
          </NuxtLink>
        </div>

        <nav class="hidden items-center gap-7 lg:flex" aria-label="Ana menü">
          <NuxtLink
            v-for="item in nav"
            :key="item.label"
            :to="item.to"
            class="text-sm text-ink-secondary transition-colors hover:text-ink"
          >
            {{ item.label }}
          </NuxtLink>
        </nav>

        <div class="flex items-center gap-3">
          <!--
            The basket is in the header rather than only in the account menu: it is the one
            thing a shopper checks constantly, and burying it behind a dropdown is how a
            half-filled cart gets forgotten. Shown on every width, because that is truer on
            a phone than on a desktop.
          -->
          <NuxtLink
            v-if="isAuthenticated"
            to="/cart"
            class="rounded-sm px-2 py-1 text-sm text-ink-secondary hover:text-ink"
            data-testid="header-cart"
          >
            Sepetim
          </NuxtLink>

          <div v-if="isAuthenticated" class="relative hidden sm:block">
            <button
              type="button"
              class="rounded-sm px-2 py-1 text-sm text-ink-secondary hover:text-ink"
              :aria-expanded="menuOpen"
              aria-haspopup="menu"
              data-testid="account-menu"
              @click="menuOpen = !menuOpen"
            >
              {{ displayName ?? 'Hesabım' }}
            </button>

            <div
              v-if="menuOpen"
              role="menu"
              class="absolute right-0 mt-2 w-52 rounded-md border border-line bg-surface py-1 shadow-lg"
            >
              <NuxtLink to="/account" role="menuitem" class="block px-4 py-2 text-sm hover:bg-bg-muted">
                Hesabım
              </NuxtLink>
              <NuxtLink to="/account/orders" role="menuitem" class="block px-4 py-2 text-sm hover:bg-bg-muted">
                Siparişlerim
              </NuxtLink>
              <NuxtLink to="/account/returns" role="menuitem" class="block px-4 py-2 text-sm hover:bg-bg-muted">
                İadelerim
              </NuxtLink>
              <NuxtLink to="/account/addresses" role="menuitem" class="block px-4 py-2 text-sm hover:bg-bg-muted">
                Adreslerim
              </NuxtLink>
              <NuxtLink to="/account/credits" role="menuitem" class="block px-4 py-2 text-sm hover:bg-bg-muted">
                Kredilerim
              </NuxtLink>
              <button
                type="button"
                role="menuitem"
                class="block w-full px-4 py-2 text-left text-sm hover:bg-bg-muted"
                @click="logout"
              >
                Çıkış yap
              </button>
            </div>
          </div>

          <div v-if="!isAuthenticated" class="flex items-center gap-3">
            <NuxtLink
              to="/auth/login"
              class="hidden text-sm text-ink-secondary hover:text-ink sm:block"
            >
              Giriş yap
            </NuxtLink>
            <RcButton to="/auth/register" size="md">Başla</RcButton>
          </div>
        </div>
      </div>
    </header>

    <!--
      The drawer. A real dialog: focus goes in, Escape closes, the page behind is still.

      Rendered in place rather than teleported to the body. It is fixed-positioned and the
      layout root imposes no transform or overflow, so a teleport bought nothing — and cost
      a crash on unmount that took the whole client-side app down with it.
    -->
    <div v-if="drawerOpen" class="fixed inset-0 z-[70] lg:hidden">
        <div class="absolute inset-0 bg-charcoal/40" @click="drawerOpen = false" />

        <div
          id="mobile-menu"
          ref="drawer"
          role="dialog"
          aria-modal="true"
          aria-label="Menü"
          class="absolute inset-y-0 left-0 flex w-[82%] max-w-xs flex-col bg-surface shadow-xl"
          @keydown="onKeydown"
        >
          <div class="flex h-18 items-center justify-between border-b border-line px-5">
            <span class="text-lg font-medium tracking-tight">RefConcept</span>
            <button
              type="button"
              class="grid size-10 place-items-center rounded-sm text-ink-secondary hover:bg-bg-muted hover:text-ink"
              aria-label="Menüyü kapat"
              data-testid="menu-close"
              @click="drawerOpen = false"
            >
              <svg class="rc-icon size-6" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 6 12 12M18 6 6 18" />
              </svg>
            </button>
          </div>

          <nav class="flex flex-col p-3" aria-label="Mobil menü">
            <NuxtLink
              v-for="item in nav"
              :key="item.label"
              :to="item.to"
              class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink"
            >
              {{ item.label }}
            </NuxtLink>

            <div class="my-2 border-t border-line" />

            <template v-if="isAuthenticated">
              <NuxtLink to="/cart" class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink">
                Sepetim
              </NuxtLink>
              <NuxtLink to="/account/orders" class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink">
                Siparişlerim
              </NuxtLink>
              <NuxtLink to="/account" class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink">
                Hesabım
              </NuxtLink>
              <button
                type="button"
                class="rounded-sm px-3 py-3 text-left text-base text-ink-secondary hover:bg-bg-muted hover:text-ink"
                @click="logout"
              >
                Çıkış yap
              </button>
            </template>

            <template v-else>
              <NuxtLink to="/auth/login" class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink">
                Giriş yap
              </NuxtLink>
              <NuxtLink to="/auth/register" class="rounded-sm px-3 py-3 text-base text-ink-secondary hover:bg-bg-muted hover:text-ink">
                Hesap oluştur
              </NuxtLink>
            </template>
          </nav>
        </div>
    </div>

    <main id="main" class="flex-1">
      <slot />
    </main>

    <footer class="border-t border-line bg-bg-muted">
      <div class="rc-container py-10">
        <div class="flex flex-col gap-6 sm:flex-row sm:justify-between">
          <div>
            <p class="text-sm font-medium">RefConcept</p>
            <p class="mt-1 max-w-[42ch] text-xs leading-relaxed text-muted">
              Yapay zekâ destekli iç mekân tasarımı ve pazar yeri.
            </p>
          </div>

          <!--
            The legal pages were reachable only by typing the URL. A terms page nobody can
            find is a terms page nobody agreed to.
          -->
          <nav class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted" aria-label="Alt menü">
            <NuxtLink to="/catalog" class="hover:text-ink">Ürünler</NuxtLink>
            <NuxtLink to="/projects" class="hover:text-ink">Projelerim</NuxtLink>
            <NuxtLink to="/legal/terms" class="hover:text-ink">Kullanım koşulları</NuxtLink>
            <NuxtLink to="/legal/privacy" class="hover:text-ink">Gizlilik</NuxtLink>
          </nav>
        </div>

        <p class="mt-8 text-xs text-muted">© {{ new Date().getFullYear() }} RefConcept</p>
      </div>
    </footer>
  </div>
</template>
