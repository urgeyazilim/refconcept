import * as vue from 'vue'

/**
 * Nuxt's auto-imports, provided by hand.
 *
 * The components are written for Nuxt, where `computed`, `ref` and `resolveComponent` are
 * global. Importing them in each component to satisfy a test runner would be the test
 * changing the code it tests — so the runner supplies what Nuxt supplies instead, and the
 * components stay exactly as they ship.
 */
const provided = [
  'computed', 'ref', 'reactive', 'watch', 'watchEffect', 'nextTick',
  'onMounted', 'onBeforeUnmount', 'onUnmounted',
  'resolveComponent', 'defineAsyncComponent', 'h', 'toRef', 'toRefs', 'unref', 'isRef',
] as const

for (const name of provided) {
  // Assigned rather than imported per file: this is what Nuxt does, and a component that
  // works in the app must work here without being edited for the privilege.
  ;(globalThis as Record<string, unknown>)[name] = (vue as Record<string, unknown>)[name]
}
