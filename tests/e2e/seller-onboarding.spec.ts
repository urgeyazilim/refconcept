import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD, grantOperatorRole } from './support/accounts'
import { checkStable, fillStable } from './support/forms'
import { gotoHydrated, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The seller onboarding journey from 15_CRITICAL_E2E_SCENARIOS.md, driven through the
 * seller portal against the live stack.
 *
 * The account is created and verified through the API rather than the storefront
 * sign-up form: this file is about onboarding, and routing it through a second app's
 * UI would make a seller test fail whenever the storefront changed.
 */

const PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, PORTAL, email)
}

test.describe('seller onboarding', () => {
  test('a new seller completes the application and submits it for review', async ({ page, request }) => {
    const account = await createVerifiedAccount('onboarding')

    await signIn(page, account.email)

    // --- dashboard invites the application ------------------------------------
    await expect(page.getByRole('heading', { name: /RefConcept'te satış yapın/ })).toBeVisible()
    await page.getByRole('link', { name: 'Başvuruyu başlat' }).click()
    await waitForHydration(page)

    // --- company details create the application -------------------------------
    await fillStable(page, '#company_name', 'Atlas Mobilya Anonim Şirketi')
    await fillStable(page, '#display_name', 'Atlas Mobilya')
    await fillStable(page, '#contact_email', account.email)
    await fillStable(page, '#contact_phone', '+905551112233')
    await page.getByRole('button', { name: 'Başvuruyu başlat' }).click()

    await expect(page.getByText('Başvurunuz oluşturuldu')).toBeVisible()

    // The checklist is derived from the data, so it must already reflect the one
    // section that was just filled in — and nothing else. "Firma bilgileri" appears
    // both as a checklist row and as a section heading, so the row is addressed
    // through its own list item.
    await expect(
      page.getByRole('listitem').filter({ hasText: 'Firma bilgileri' }).first(),
    ).toBeVisible()
    await expect(page.getByText('Hakediş ödemeleri için birincil banka hesabı zorunludur.')).toBeVisible()

    // --- tax profile first: it decides which identifiers and documents apply ---
    await page.locator('#taxpayer_type').selectOption('corporate')
    await page.getByRole('button', { name: 'Kaydet' }).nth(1).click()
    await expect(page.getByText('Kaydedildi.')).toBeVisible()

    // --- legal entity ---------------------------------------------------------
    await fillStable(page, '#legal_name', 'Atlas Mobilya Anonim Şirketi')
    await fillStable(page, '#tax_number', '1234567890')
    await fillStable(page, '#tax_office', 'Kadıköy')
    await page.getByRole('button', { name: 'Kaydet' }).first().click()
    await expect(page.getByText('Kaydedildi.')).toBeVisible()

    // --- primary contact ------------------------------------------------------
    await fillStable(page, '#full_name', 'Deniz Yılmaz')
    await fillStable(page, '#contact_person_email', account.email)
    await fillStable(page, '#contact_person_phone', '+905551112233')
    await page.getByRole('button', { name: 'Kaydet' }).nth(2).click()
    await expect(page.getByText('Kaydedildi.')).toBeVisible()

    // --- registered address ---------------------------------------------------
    await fillStable(page, '#city', 'İstanbul')
    await fillStable(page, '#district', 'Kadıköy')
    await fillStable(page, '#address_line1', 'Bağdat Caddesi 100')
    await page.getByRole('button', { name: 'Kaydet' }).nth(3).click()
    await expect(page.getByText('Kaydedildi.')).toBeVisible()

    // --- bank account: the IBAN is checksum-validated server side --------------
    await fillStable(page, '#account_holder', 'Atlas Mobilya Anonim Şirketi')
    await fillStable(page, '#bank_name', 'Demo Bank')
    await fillStable(page, '#iban', 'TR330006100519786457841327') // one digit wrong
    await page.getByRole('button', { name: 'Kaydet' }).nth(4).click()
    await expect(page.getByText('Geçerli bir IBAN girin.')).toBeVisible()

    await fillStable(page, '#iban', 'TR330006100519786457841326')
    await page.getByRole('button', { name: 'Kaydet' }).nth(4).click()
    await expect(page.getByText('Kaydedildi.')).toBeVisible()

    // Only the masked form comes back; the plaintext never leaves the server.
    await expect(page.getByText('**** **** **** 1326')).toBeVisible()
    await expect(page.locator('body')).not.toContainText('TR330006100519786457841326')

    // --- documents ------------------------------------------------------------
    // Addressed by id: the checklist row for "Belgeler" lists the same document names
    // in its "missing" detail, so a text filter matches two different places.
    for (const label of ['tax_certificate', 'trade_registry_gazette', 'signature_circular']) {
      await page.locator(`#document-${label}`).setInputFiles({
        name: `${label}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n% RefConcept E2E test document\n'),
      })

      await expect(page.getByText('Belge yüklendi.')).toBeVisible()
    }

    /*
     * --- agreements -----------------------------------------------------------
     *
     * Each click is confirmed by waiting for a badge to *appear*, not for a button to
     * disappear. The difference caught a real defect: a reload used to blank the whole
     * form, so "no accept buttons on screen" was also what a page mid-refresh looked
     * like — the loop could see zero, decide there was nothing left to accept, and pass
     * having accepted nothing at all. An appearing badge cannot be faked by an empty
     * page.
     */
    const acceptButtons = page.getByRole('button', { name: 'Okudum, onaylıyorum' })
    const acceptedBadges = page.getByText('Onaylandı', { exact: true })

    const outstandingCount = await acceptButtons.count()
    expect(outstandingCount).toBeGreaterThan(0)

    for (let accepted = 0; accepted < outstandingCount; accepted++) {
      await acceptButtons.first().click()
      await expect(acceptedBadges).toHaveCount(accepted + 1)
    }

    await expect(acceptButtons).toHaveCount(0)

    /*
     * The buttons being gone is not the same as the acceptances having landed — the list
     * is empty for a moment while the page reloads too. Checked against the API so that a
     * failed acceptance fails here, at its cause, rather than three steps later as a
     * submit button that will not enable and no explanation of why.
     */
    const agreements = await request.get(`${API}/api/v1/seller/agreements`, {
      headers: { Authorization: `Bearer ${account.token}`, Accept: 'application/json' },
    })

    const outstanding = (await agreements.json()).data
      .filter((agreement: { accepted: boolean }) => !agreement.accepted)
      .map((agreement: { title: string }) => agreement.title)

    expect(outstanding, `onaylanmayan sözleşmeler: ${outstanding.join(', ')}`).toHaveLength(0)

    // --- submit ---------------------------------------------------------------
    const submit = page.getByRole('button', { name: 'İncelemeye gönder' })

    await expect(submit).toBeEnabled()
    await submit.click()

    await expect(page.getByText('Başvurunuz incelemeye gönderildi.')).toBeVisible()

    // A submitted application is locked: the reviewer must see what was submitted.
    await gotoHydrated(page, `${PORTAL}/onboarding`)
    await expect(page.getByText(/düzenlenemez/)).toBeVisible()
  })

  test('the submit button stays disabled while steps are outstanding', async ({ page }) => {
    const account = await createVerifiedAccount('incomplete')

    await signIn(page, account.email)
    await page.getByRole('link', { name: 'Başvuruyu başlat' }).click()
    await waitForHydration(page)

    await fillStable(page, '#company_name', 'Eksik Şirket A.Ş.')
    await fillStable(page, '#display_name', 'Eksik Şirket')
    await fillStable(page, '#contact_email', account.email)
    await fillStable(page, '#contact_phone', '+905551112233')
    await page.getByRole('button', { name: 'Başvuruyu başlat' }).click()

    await expect(page.getByText('Başvurunuz oluşturuldu')).toBeVisible()

    // The guard is server-side; the disabled button only mirrors it so the user is
    // not invited to fail.
    await expect(page.getByRole('button', { name: 'İncelemeye gönder' })).toBeDisabled()
  })

  test('an individual seller is asked for a national id instead of a tax number', async ({ page }) => {
    const account = await createVerifiedAccount('individual')

    await signIn(page, account.email)
    await page.getByRole('link', { name: 'Başvuruyu başlat' }).click()
    await waitForHydration(page)

    await fillStable(page, '#company_name', 'Bireysel Satıcı')
    await fillStable(page, '#display_name', 'Bireysel Satıcı')
    await fillStable(page, '#contact_email', account.email)
    await fillStable(page, '#contact_phone', '+905551112233')
    await page.getByRole('button', { name: 'Başvuruyu başlat' }).click()
    await expect(page.getByText('Başvurunuz oluşturuldu')).toBeVisible()

    await expect(page.locator('#tax_number')).toBeVisible()

    await page.locator('#taxpayer_type').selectOption('individual')

    // Demanding a company tax number from an individual would block a legitimate
    // applicant from ever completing onboarding.
    await expect(page.locator('#national_id')).toBeVisible()
    await expect(page.locator('#tax_number')).toHaveCount(0)
  })
})

test.describe('seller review', () => {
  test('an operator approves an application and the seller account goes live', async ({ page, request }) => {
    const applicant = await createVerifiedAccount('review')
    const operator = await createVerifiedAccount('operator')
    await grantOperatorRole(operator.email)

    // Build a complete application through the API: this test is about the review
    // decision, not about re-driving the wizard a third time.
    const headers = { Authorization: `Bearer ${applicant.token}`, Accept: 'application/json' }
    const api = API

    await request.post(`${api}/api/v1/seller/application`, {
      headers,
      data: {
        company_name: 'Nova Yaşam A.Ş.',
        display_name: 'Nova Yaşam',
        legal_form: 'anonim_sirket',
        contact_email: applicant.email,
        contact_phone: '+905551114455',
      },
    })

    await request.put(`${api}/api/v1/seller/application/sections/tax-profile`, {
      headers,
      data: { taxpayer_type: 'corporate', default_vat_rate_bps: 2000 },
    })

    await request.put(`${api}/api/v1/seller/application/sections/legal-entity`, {
      headers,
      data: { legal_name: 'Nova Yaşam Anonim Şirketi', tax_number: '9876543210' },
    })

    await request.put(`${api}/api/v1/seller/application/sections/contact`, {
      headers,
      data: { type: 'primary', full_name: 'Nova Yetkili', email: applicant.email },
    })

    await request.put(`${api}/api/v1/seller/application/sections/address`, {
      headers,
      data: { type: 'registered', city: 'Ankara', address_line1: 'Atatürk Bulvarı 1' },
    })

    await request.put(`${api}/api/v1/seller/application/sections/bank-account`, {
      headers,
      data: {
        account_holder: 'Nova Yaşam A.Ş.',
        iban: 'TR330006100519786457841326',
      },
    })

    for (const type of ['tax_certificate', 'trade_registry_gazette', 'signature_circular']) {
      await request.post(`${api}/api/v1/seller/documents`, {
        headers: { Authorization: `Bearer ${applicant.token}` },
        multipart: {
          type,
          file: {
            name: `${type}.pdf`,
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4\n% RefConcept E2E\n'),
          },
        },
      })
    }

    const agreements = await (await request.get(`${api}/api/v1/seller/agreements`, { headers })).json()

    for (const agreement of agreements.data) {
      await request.post(`${api}/api/v1/seller/agreements/${agreement.id}/accept`, { headers })
    }

    const submitted = await request.post(`${api}/api/v1/seller/application/submit`, { headers })
    expect(submitted.ok()).toBeTruthy()

    // --- the operator decides -------------------------------------------------
    const operatorHeaders = { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' }

    const queue = await (await request.get(`${api}/api/v1/admin/seller-applications`, {
      headers: operatorHeaders,
    })).json()

    const target = queue.data.find((a: { company_name: string }) => a.company_name === 'Nova Yaşam A.Ş.')
    expect(target).toBeTruthy()

    const approval = await request.post(
      `${api}/api/v1/admin/seller-applications/${target.id}/approve`,
      {
        headers: operatorHeaders,
        data: { reason: 'Belgeler eksiksiz ve doğrulandı.', commission_bps: 1250 },
      },
    )

    expect(approval.ok()).toBeTruthy()

    const approved = await approval.json()
    expect(approved.data.seller_code).toMatch(/^RC-[A-Z0-9]{6}$/)

    // --- the applicant sees their live seller account -------------------------
    await signIn(page, applicant.email)

    /*
     * Approval swaps the front page rather than adding a banner to it. Somebody whose
     * account has just opened does not need to be told again that it opened; they need
     * to know what is waiting for them, which from this moment is the only question the
     * page exists to answer.
     */
    await expect(page.getByRole('heading', { name: 'Sizi bekleyenler' })).toBeVisible()
    await expect(page.getByTestId('seller-queue-unconfirmed')).toBeVisible()
  })
})
