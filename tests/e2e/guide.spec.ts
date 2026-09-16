import { expect, test } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The guide speaks on every screen, in the words of docs/product/REHBER.md.
 *
 * No photograph is uploaded here on purpose: this is about the voice before anything has
 * happened — the empty house, the house with no room, the room with no picture — which is
 * where a form used to stand and where the guide now does.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'

test.describe.configure({ timeout: 180_000 })

test.describe('the guide', () => {
  test('says the next step on the house, the room list and the empty room', async ({ page }) => {
    const account = await createVerifiedAccount('guide-customer')

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)
    await gotoInteractive(page, `${STOREFRONT}/projects`)
    await waitForHydration(page)

    await expect(page.getByText('Hadi başlayalım.')).toBeVisible()
    await page.screenshot({ path: 'test-results/guide-1-projects.png', fullPage: true })

    await page.getByRole('button', { name: 'Evimi ekle' }).click()
    await fillStable(page, '#name', `Rehber Evi ${Date.now()}`)
    await page.getByRole('button', { name: 'Projeyi oluştur' }).click()

    await expect(page.getByText('Şimdi bir oda ekle.')).toBeVisible()
    await page.screenshot({ path: 'test-results/guide-2-project.png', fullPage: true })

    await page.getByRole('button', { name: 'Oda ekle' }).first().click()
    await fillStable(page, '#name', 'Salon')
    await page.locator('#room_type').selectOption('living_room')
    await page.locator('form').filter({ has: page.locator('#room_type') })
      .getByRole('button', { name: 'Ekle', exact: true })
      .click()

    await expect(page.getByText('Salon hazır — hadi tasarlayalım.')).toBeVisible()
    await page.screenshot({ path: 'test-results/guide-3-room-added.png', fullPage: true })

    await page.getByRole('link', { name: 'Odaya git' }).click()
    await waitForHydration(page)

    await expect(page.getByText('Hadi odanın fotoğrafını çekelim.')).toBeVisible()
    await expect(page.getByText('Kapıdan içeri', { exact: true })).toBeVisible()
    await page.screenshot({ path: 'test-results/guide-4-room.png', fullPage: true })
  })
})
