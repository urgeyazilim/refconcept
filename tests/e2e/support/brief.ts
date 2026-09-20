import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'

/**
 * Walks the guided design brief from the first tile to "Tasarımı başlat".
 *
 * The design page used to ask one free-text question and every journey filled in the same
 * textarea. It now asks eight tapped ones, and every journey that wants a design has to go
 * through them — so the walk lives here rather than being copied into each spec, where the
 * copies would drift the first time a question was reworded.
 *
 * Deliberately presses through with the defaults rather than choosing anything clever. That
 * is how most people will use it, and it is the path most likely to break silently: a
 * required question with no default and no way out would hang here, which is exactly the
 * failure worth catching.
 */
export async function completeBrief(page: Page, options: { style?: string } = {}): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Hangi tarzı seviyorsunuz?' })).toBeVisible()

  await page.getByRole('button', { name: options.style ?? 'Modern', exact: false }).first().click()
  await next(page)

  await expect(page.getByRole('heading', { name: 'Renkler nasıl olsun?' })).toBeVisible()
  await page.getByRole('button', { name: 'Sıcak Nötr', exact: false }).first().click()
  await next(page)

  /*
   * Then straight through the room questions on their defaults. Bounded rather than looped
   * on a condition: a wizard that cannot be finished should fail this helper with a clear
   * count, not spin until the test times out with nothing to say.
   */
  const start = page.getByRole('button', { name: 'Tasarımı başlat' })

  for (let step = 0; step < 15; step++) {
    if (await start.count() > 0) break

    await next(page)
  }

  await expect(start).toBeVisible()
  await start.click()
}

/**
 * One press of "İleri", through a step that is redrawing itself.
 *
 * Choosing a tile re-renders the question, so the button can be detached between the moment
 * Playwright resolves it and the moment the click lands — and Playwright's own retry
 * resolves the replacement and races it again. It fails as "element was detached from the
 * DOM, retrying" and then a timeout, which reads like a broken wizard and is not one.
 *
 * So: press, and if the press did not land, look at whether the wizard moved on anyway
 * before pressing again. Pressing twice on one question would silently skip the next one,
 * which is worse than the flake — the heading check is what makes that safe.
 */
async function next(page: Page): Promise<void> {
  const heading = () => page.locator('h2, h3').first().textContent().catch(() => null)

  const before = await heading()

  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      await page.getByRole('button', { name: 'İleri' }).first().click({ timeout: 5_000 })

      return
    }
    catch {
      // Landed after all, or the step changed under it: either way there is nothing to press.
      if (await heading() !== before) {
        return
      }

      await page.waitForTimeout(500)
    }
  }

  throw new Error(`Brief sihirbazında "İleri" basılamadı; ekranda "${before}" duruyor.`)
}
