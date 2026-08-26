import { expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { createApprovedSeller, pngBuffer, TEST_CATEGORY_SLUG } from './sellers'

const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

export interface ListedProduct {
  productId: string
  skuId: string
  slug: string
  sellerToken: string
  /** The seller owner's own address, for tests that sign into the portal as them. */
  sellerEmail: string
  operatorToken: string
  /** The operator's own address, for tests that sign into the admin panel as them. */
  operatorEmail: string
}

/**
 * An approved seller with one listed, in-stock product.
 *
 * Built through the real endpoints — listing, media, moderation, stock — rather than by
 * inserting rows. A product put into the database behind the API's back would not prove
 * that a basket or a checkout refuses the same things the catalogue refuses, which is the
 * only reason these fixtures exist.
 *
 * Lives here rather than in one spec file because both the shopping journeys and the
 * checkout journeys need the same starting point, and two copies of a fixture is two
 * places for it to drift from what the API actually demands.
 */
export async function listProduct(
  request: APIRequestContext,
  name: string,
  priceMinor: number,
  stock: number,
): Promise<ListedProduct> {
  const seller = await createApprovedSeller(request, {
    companyName: `${name} Mobilya A.Ş.`,
    displayName: `${name} Mobilya`,
  })

  const headers = { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' }

  /*
   * A listing needs its category by id and its required attributes filled — the same
   * demands the seller portal makes of a person, which is the point of building the
   * fixture through the API rather than inserting rows.
   */
  const categories = await (await request.get(`${API}/api/v1/catalog/categories`)).json()
  const category = categories.data.find((item: { slug: string }) => item.slug === TEST_CATEGORY_SLUG)

  const attributes = await (await request.get(
    `${API}/api/v1/catalog/categories/${TEST_CATEGORY_SLUG}/attributes`,
  )).json()

  const required = attributes.data
    .filter((attribute: { is_required: boolean }) => attribute.is_required)
    .map((attribute: { code: string, values: Array<{ value: string }> }) => ({
      code: attribute.code,
      value: attribute.values[0]!.value,
    }))

  const created = await request.post(`${API}/api/v1/seller/products`, {
    headers,
    data: {
      name,
      description: 'Sepet, arama ve ödeme testleri için üretilmiş bir ürün.',
      primary_category_id: category.id,
      attributes: required,
    },
  })

  expect(created.ok(), await created.text()).toBeTruthy()

  const productId = (await created.json()).data.id

  const sku = await request.post(`${API}/api/v1/seller/products/${productId}/skus`, {
    headers,
    data: {
      sku: `E2E-${Date.now()}-${Math.floor(Math.random() * 1e4)}`,
      list_price_minor: priceMinor,
      stock_quantity: stock,
      dimensions: { width_mm: 1_800, depth_mm: 900 },
    },
  })

  expect(sku.ok(), await sku.text()).toBeTruthy()

  const skuId = (await sku.json()).data.skus.at(-1).id

  await request.post(`${API}/api/v1/seller/products/${productId}/media`, {
    headers: { Authorization: `Bearer ${seller.account.token}` },
    multipart: {
      file: { name: 'urun.png', mimeType: 'image/png', buffer: pngBuffer(1200, 900) },
    },
  })

  await request.post(`${API}/api/v1/seller/products/${productId}/submit`, { headers })

  const operatorHeaders = { Authorization: `Bearer ${seller.operator.token}`, Accept: 'application/json' }

  await request.post(`${API}/api/v1/admin/products/${productId}/review`, { headers: operatorHeaders })
  await request.post(`${API}/api/v1/admin/products/${productId}/approve`, {
    headers: operatorHeaders,
    data: { reason: 'E2E testi için onaylandı.' },
  })

  // The ledger, not the seller's own figure, is what decides availability at checkout.
  await request.post(`${API}/api/v1/seller/stock/adjust`, {
    headers,
    data: { sku_id: skuId, delta: stock, type: 'receipt', reason: 'E2E açılış stoğu' },
  })

  const detail = await request.get(`${API}/api/v1/seller/products/${productId}`, { headers })
  const slug = (await detail.json()).data.slug

  return {
    productId,
    skuId,
    slug,
    sellerToken: seller.account.token,
    sellerEmail: seller.account.email,
    operatorToken: seller.operator.token,
    operatorEmail: seller.operator.email,
  }
}
