import type { APIRequestContext } from '@playwright/test'
import { createVerifiedAccount, grantOperatorRole } from './accounts'
import type { VerifiedAccount } from './accounts'

/**
 * Builds an approved, trading seller through the API.
 *
 * Onboarding has its own end-to-end test that drives the whole wizard through the UI.
 * Every *other* seller journey — listing a product, being moderated, appearing in the
 * catalogue — needs an approved seller as a precondition, not as its subject. Driving
 * the wizard again for each of them would make a product test fail whenever an
 * onboarding field moved, and would add a minute to every run.
 *
 * The route taken here is the real one: the same endpoints, the same validation, the
 * same operator approval. Nothing is inserted into the database behind the API's back.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

export interface ApprovedSeller {
  account: VerifiedAccount
  operator: VerifiedAccount
  applicationId: string
  sellerCode: string
  displayName: string
}

/** A checksum-valid Turkish IBAN; the API refuses anything that fails mod-97. */
const VALID_IBAN = 'TR330006100519786457841326'

const REQUIRED_DOCUMENTS = ['tax_certificate', 'trade_registry_gazette', 'signature_circular']

export async function createApprovedSeller(
  request: APIRequestContext,
  options: { companyName: string, displayName: string, operator?: VerifiedAccount },
): Promise<ApprovedSeller> {
  const account = await createVerifiedAccount('seller')

  const operator = options.operator ?? await (async () => {
    const created = await createVerifiedAccount('operator')
    await grantOperatorRole(created.email)

    return created
  })()

  const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

  await request.post(`${API}/api/v1/seller/application`, {
    headers,
    data: {
      company_name: options.companyName,
      display_name: options.displayName,
      legal_form: 'anonim_sirket',
      contact_email: account.email,
      contact_phone: '+905551114455',
    },
  })

  await request.put(`${API}/api/v1/seller/application/sections/tax-profile`, {
    headers,
    data: { taxpayer_type: 'corporate', default_vat_rate_bps: 2000 },
  })

  await request.put(`${API}/api/v1/seller/application/sections/legal-entity`, {
    headers,
    data: { legal_name: options.companyName, tax_number: '9876543210' },
  })

  await request.put(`${API}/api/v1/seller/application/sections/contact`, {
    headers,
    data: { type: 'primary', full_name: 'Yetkili Kişi', email: account.email },
  })

  await request.put(`${API}/api/v1/seller/application/sections/address`, {
    headers,
    data: { type: 'registered', city: 'Ankara', address_line1: 'Atatürk Bulvarı 1' },
  })

  await request.put(`${API}/api/v1/seller/application/sections/bank-account`, {
    headers,
    data: { account_holder: options.companyName, iban: VALID_IBAN },
  })

  for (const type of REQUIRED_DOCUMENTS) {
    await request.post(`${API}/api/v1/seller/documents`, {
      headers: { Authorization: `Bearer ${account.token}` },
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

  const agreements = await (await request.get(`${API}/api/v1/seller/agreements`, { headers })).json()

  for (const agreement of agreements.data) {
    await request.post(`${API}/api/v1/seller/agreements/${agreement.id}/accept`, { headers })
  }

  const submitted = await request.post(`${API}/api/v1/seller/application/submit`, { headers })

  if (!submitted.ok()) {
    throw new Error(`Application submit failed: ${submitted.status()} ${await submitted.text()}`)
  }

  const operatorHeaders = { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' }

  const queue = await (await request.get(`${API}/api/v1/admin/seller-applications`, {
    headers: operatorHeaders,
  })).json()

  const target = queue.data.find(
    (application: { company_name: string }) => application.company_name === options.companyName,
  )

  if (!target) {
    throw new Error(`Application for ${options.companyName} never reached the review queue.`)
  }

  const approval = await request.post(
    `${API}/api/v1/admin/seller-applications/${target.id}/approve`,
    {
      headers: operatorHeaders,
      data: { reason: 'Belgeler eksiksiz ve doğrulandı.', commission_bps: 1250 },
    },
  )

  if (!approval.ok()) {
    throw new Error(`Approval failed: ${approval.status()} ${await approval.text()}`)
  }

  const approved = await approval.json()

  return {
    account,
    operator,
    applicationId: target.id,
    sellerCode: approved.data.seller_code,
    displayName: options.displayName,
  }
}

/** The leaf category slug the product tests list against, seeded by CatalogTaxonomySeeder. */
export const TEST_CATEGORY_SLUG = 'kanepe'

/** A small but genuinely decodable PNG, for image uploads that must pass getimagesize(). */
export function pngBuffer(): Buffer {
  return Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII=',
    'base64',
  )
}
