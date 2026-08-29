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
  await page.getByRole('button', { name: 'İleri' }).click()

  await expect(page.getByRole('heading', { name: 'Renkler nasıl olsun?' })).toBeVisible()
  await page.getByRole('button', { name: 'Sıcak Nötr', exact: false }).first().click()
  await page.getByRole('button', { name: 'İleri' }).click()

  /*
   * Then straight through the room questions on their defaults. Bounded rather than looped
   * on a condition: a wizard that cannot be finished should fail this helper with a clear
   * count, not spin until the test times out with nothing to say.
   */
  const start = page.getByRole('button', { name: 'Tasarımı başlat' })

  for (let step = 0; step < 15; step++) {
    if (await start.count() > 0) break

    await page.getByRole('button', { name: 'İleri' }).click()
  }

  await expect(start).toBeVisible()
  await start.click()
}
