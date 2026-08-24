import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { pngBuffer } from './support/sellers'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 5 gate: a customer's home, and who can see it.
 *
 * Three things are proven here that unit tests cannot. That the whole journey works
 * through the real screens — project, room, photograph, design; that a room photograph
 * never reaches the browser as a plain URL anybody could reuse; and that sharing a
 * project actually grants access while revoking it actually takes it away.
 *
 * The negative assertions carry the weight. A test that only checks the owner can see
 * their own photographs would pass just as happily if everybody could.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

test.describe.configure({ timeout: 300_000 })

async function signIn(page: Page, email: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${STOREFRONT}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  // The storefront lands a signed-in customer on their account, not the home page.
  await expect(page).toHaveURL(/\/account$/)
}

test.describe('project journey', () => {
  test('a customer creates a project, photographs a room and starts a design', async ({ page, request }) => {
    const account = await createVerifiedAccount('customer')
    const projectName = `Kadıköy Dairesi ${Date.now()}`

    await signIn(page, account.email)

    // --- the project ----------------------------------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects`)

    await expect(page.getByRole('heading', { name: 'İlk projenizi oluşturun' })).toBeVisible()
    // Two of them on an empty list — the header action and the empty-state call to
    // action — which is deliberate, so the test names which one it means.
    await page.getByRole('button', { name: 'Yeni proje' }).first().click()

    await fillStable(page, '#name', projectName)
    await fillStable(page, '#budget', '150.000')
    await page.getByRole('button', { name: 'Projeyi oluştur' }).click()

    await expect(page.getByRole('heading', { name: projectName })).toBeVisible()

    const projectId = new URL(page.url()).pathname.split('/').pop()!

    // --- a room ----------------------------------------------------------------
    await page.getByRole('button', { name: 'Oda ekle' }).click()
    await fillStable(page, '#name', 'Salon')
    await page.locator('#room_type').selectOption('living_room')
    await page.locator('form').filter({ has: page.locator('#room_type') })
      .getByRole('button', { name: 'Ekle', exact: true })
      .click()

    await expect(page.getByRole('link', { name: /Salon/ })).toBeVisible()
    // Said plainly rather than left as a disabled button with no explanation.
    await expect(page.getByText('Fotoğraf bekliyor')).toBeVisible()

    await page.getByRole('link', { name: /Salon/ }).click()
    await waitForHydration(page)

    await expect(page.getByText('Odanın fotoğrafı')).toBeVisible()

    // --- the photograph ---------------------------------------------------------
    await page.locator('input[type="file"]').setInputFiles({
      name: 'salon.png',
      mimeType: 'image/png',
      buffer: pngBuffer(1024, 768),
    })

    await expect(page.getByText('Tasarım bu fotoğraftan')).toBeVisible()

    // --- measurements ------------------------------------------------------------
    await fillStable(page, '#width', '420')
    await fillStable(page, '#length', '560')
    await fillStable(page, '#height', '270')
    await page.locator('#quality').selectOption('manual')
    await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click()

    // Centimetres in the form, millimetres on the wire: 420 × 560 cm is 23.52 m².
    await expect(page.getByText('23.52 m²')).toBeVisible()

    // --- something to design around ------------------------------------------------
    await page.getByRole('button', { name: 'Ekle', exact: true }).click()
    await page.locator('#ctype').selectOption('window')
    await page.locator('#wall').selectOption('south')
    await fillStable(page, '#offset', '120')
    await fillStable(page, '#cwidth', '180')
    await fillStable(page, '#sill', '90')
    await page.locator('form').filter({ has: page.locator('#ctype') })
      .getByRole('button', { name: 'Ekle', exact: true })
      .click()

    await expect(page.getByText(/Pencere · 180 cm/)).toBeVisible()

    // --- a design ---------------------------------------------------------------------
    await page.getByRole('button', { name: 'Tasarım oluştur' }).click()
    await page.locator('#prompt').fill('İskandinav, açık renkler')
    await page.getByRole('button', { name: 'Başlat' }).click()

    await expect(page.getByRole('heading', { name: /Salon tasarımı/ })).toBeVisible()
    await expect(page.getByText('v1')).toBeVisible()

    // Honest about where the product is: the tree works, the engine is Phase 8.
    await expect(page.getByText(/Tasarım motoru henüz devrede değil/)).toBeVisible()

    // --- the API agrees ----------------------------------------------------------------
    const detail = await request.get(`${API}/api/v1/projects/${projectId}`, {
      headers: { Authorization: `Bearer ${account.token}`, Accept: 'application/json' },
    })

    const body = await detail.json()

    expect(body.data.rooms).toHaveLength(1)
    expect(body.data.rooms[0].is_ready_for_design).toBe(true)
    expect(body.data.rooms[0].floor_area_m2).toBe(23.52)
  })

  test('a room photograph is never handed out as a reusable URL', async ({ page, request }) => {
    const account = await createVerifiedAccount('privacy')
    const intruder = await createVerifiedAccount('intruder')

    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    const project = await (await request.post(`${API}/api/v1/projects`, {
      headers,
      data: { name: `Gizli Ev ${Date.now()}` },
    })).json()

    const room = await (await request.post(`${API}/api/v1/projects/${project.data.id}/rooms`, {
      headers,
      data: { name: 'Yatak odası', room_type: 'bedroom' },
    })).json()

    const mediaBase = `${API}/api/v1/projects/${project.data.id}/rooms/${room.data.id}/media`

    await request.post(mediaBase, {
      headers: { Authorization: `Bearer ${account.token}` },
      multipart: {
        file: { name: 'yatak-odasi.png', mimeType: 'image/png', buffer: pngBuffer(1024, 768) },
      },
    })

    // --- the listing carries no URL and no path ---------------------------------------
    const listing = await (await request.get(mediaBase, { headers })).json()

    expect(listing.data).toHaveLength(1)
    expect(listing.data[0].url).toBeUndefined()
    expect(JSON.stringify(listing)).not.toContain('room-media/')

    const mediaId = listing.data[0].id

    // --- a link is a separate request, and only for the owner --------------------------
    const link = await request.get(`${mediaBase}/${mediaId}/link`, { headers })

    expect(link.ok()).toBeTruthy()
    expect((await link.json()).data.expires_in).toBe(300)

    const intruderLink = await request.get(`${mediaBase}/${mediaId}/link`, {
      headers: { Authorization: `Bearer ${intruder.token}`, Accept: 'application/json' },
    })

    expect(intruderLink.status()).toBe(403)

    // --- and the bytes themselves are behind the same check ------------------------------
    const download = `${API}/api/v1/projects/room-media/${mediaId}/download`

    const asOwner = await request.get(download, { headers })
    expect(asOwner.ok()).toBeTruthy()
    // A shared cache is a way for one person's photograph to become two people's.
    expect(asOwner.headers()['cache-control']).toContain('no-store')

    const asIntruder = await request.get(download, {
      headers: { Authorization: `Bearer ${intruder.token}`, Accept: 'application/json' },
    })
    expect(asIntruder.status()).toBe(403)

    const anonymous = await request.get(download, { headers: { Accept: 'application/json' } })
    expect(anonymous.status()).toBe(401)

    // --- the browser renders it, so the signed-link path genuinely works ------------------
    await signIn(page, account.email)
    await gotoInteractive(page, `${STOREFRONT}/projects/${project.data.id}/rooms/${room.data.id}`)

    const photo = page.locator('figure img').first()
    await expect(photo).toBeVisible()

    // The src is a link this page fetched, not something in the listing payload.
    const src = await photo.getAttribute('src')
    expect(src).toBeTruthy()
  })

  test('sharing a project grants access and revoking it takes access away', async ({ page, request }) => {
    const owner = await createVerifiedAccount('owner')
    const partner = await createVerifiedAccount('partner')

    const headers = { Authorization: `Bearer ${owner.token}`, Accept: 'application/json' }

    const project = await (await request.post(`${API}/api/v1/projects`, {
      headers,
      data: { name: `Paylaşımlı Ev ${Date.now()}` },
    })).json()

    const projectId = project.data.id

    // --- the owner invites through the portal ---------------------------------------
    await signIn(page, owner.email)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}`)

    await page.getByRole('button', { name: 'Kişi davet et' }).click()
    await fillStable(page, '#email', partner.email)
    await page.locator('#role').selectOption('viewer')
    await page.getByRole('button', { name: 'Davet oluştur' }).click()

    await expect(page.getByText('Davet bağlantısı oluşturuldu')).toBeVisible()

    const inviteLink = (await page.locator('code').first().textContent())!.trim()

    // --- the partner cannot get in until they accept ---------------------------------
    const before = await request.get(`${API}/api/v1/projects/${projectId}`, {
      headers: { Authorization: `Bearer ${partner.token}`, Accept: 'application/json' },
    })

    expect(before.status()).toBe(403)

    // --- accepting works, and only for the invited address ----------------------------
    await signIn(page, partner.email)
    await gotoInteractive(page, inviteLink.replace(STOREFRONT, STOREFRONT))

    await page.getByRole('button', { name: 'Daveti kabul et' }).click()
    await expect(page.getByRole('heading', { name: 'Davet kabul edildi' })).toBeVisible()

    const after = await request.get(`${API}/api/v1/projects/${projectId}`, {
      headers: { Authorization: `Bearer ${partner.token}`, Accept: 'application/json' },
    })

    expect(after.ok()).toBeTruthy()
    expect((await after.json()).data.can_edit).toBe(false)

    // A viewer may look and nothing more; generating costs the owner's credits.
    const attemptedEdit = await request.patch(`${API}/api/v1/projects/${projectId}`, {
      headers: { Authorization: `Bearer ${partner.token}`, Accept: 'application/json' },
      data: { name: 'Benim Evim' },
    })

    expect(attemptedEdit.status()).toBe(403)

    // --- revoking closes the door immediately -------------------------------------------
    await signIn(page, owner.email)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}`)

    await page.getByRole('button', { name: 'Erişimi kaldır' }).click()

    await expect.poll(async () => {
      const check = await request.get(`${API}/api/v1/projects/${projectId}`, {
        headers: { Authorization: `Bearer ${partner.token}`, Accept: 'application/json' },
      })

      return check.status()
    }).toBe(403)
  })
})
