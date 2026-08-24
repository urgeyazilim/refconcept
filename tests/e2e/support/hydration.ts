import type { Page } from '@playwright/test'

/**
 * Navigates and waits until Vue has actually taken over the server-rendered markup.
 *
 * Nuxt streams HTML long before hydration finishes. A click landing in that window
 * hits a plain HTML form with no event listeners attached: the browser performs a
 * native submit, the page reloads, and the test sees a pristine form with no
 * validation messages — a failure that looks like a product bug but is not one.
 *
 * Vue sets `__vue_app__` on the element it mounts to, so its presence is a precise
 * signal that hydration completed.
 */
export async function gotoHydrated(page: Page, path: string): Promise<void> {
  await page.goto(path)
  await waitForHydration(page)
}

export async function waitForHydration(page: Page): Promise<void> {
  await page.waitForFunction(
    () => {
      const root = document.querySelector('#__nuxt')

      return Boolean(root && '__vue_app__' in root)
    },
    undefined,
    { timeout: 60_000 },
  )
}
