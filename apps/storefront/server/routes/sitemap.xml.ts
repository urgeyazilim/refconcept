/**
 * The pages worth indexing, listed for a crawler.
 *
 * Built from the catalogue at request time rather than from a hand-kept list, because a
 * hand-kept list is wrong the day after somebody adds a product. Only what a signed-out
 * visitor can actually open appears here — the same set `robots.txt` allows, and for the
 * same reason.
 *
 * If the API cannot be reached the static pages are still served. A sitemap missing its
 * product URLs is a smaller problem than a 500 where a crawler expected XML, which is how
 * a site stops being crawled at all.
 */
interface CatalogueRow {
  slug: string
  updated_at?: string | null
}

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const siteUrl = String(config.public.siteUrl ?? '').replace(/\/$/, '')
  const apiBase = String(config.public.apiBase ?? '').replace(/\/$/, '')

  const staticPaths = [
    { path: '/', priority: '1.0', changefreq: 'weekly' },
    { path: '/catalog', priority: '0.9', changefreq: 'daily' },
    { path: '/legal/terms', priority: '0.3', changefreq: 'yearly' },
    { path: '/legal/privacy', priority: '0.3', changefreq: 'yearly' },
  ]

  const products = await catalogue(apiBase)

  const entries = [
    ...staticPaths.map(page => ({
      loc: siteUrl + page.path,
      priority: page.priority,
      changefreq: page.changefreq,
      lastmod: null as string | null,
    })),
    ...products.map(product => ({
      loc: `${siteUrl}/catalog/${product.slug}`,
      priority: '0.7',
      changefreq: 'weekly',
      lastmod: product.updated_at ?? null,
    })),
  ]

  const xml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ...entries.map(entry => [
      '  <url>',
      `    <loc>${escapeXml(entry.loc)}</loc>`,
      entry.lastmod ? `    <lastmod>${escapeXml(entry.lastmod.slice(0, 10))}</lastmod>` : '',
      `    <changefreq>${entry.changefreq}</changefreq>`,
      `    <priority>${entry.priority}</priority>`,
      '  </url>',
    ].filter(Boolean).join('\n')),
    '</urlset>',
    '',
  ].join('\n')

  setResponseHeader(event, 'Content-Type', 'application/xml; charset=utf-8')

  return xml
})

/**
 * The whole live catalogue, a page at a time.
 *
 * Paged rather than asked for in one request, because the API caps a page at sixty — and
 * asking for more is a 422, which is how a sitemap silently loses every product it was
 * written to list. Bounded at twenty pages so a catalogue that grows past what a single
 * sitemap should carry stops rather than hanging the request; past that the answer is a
 * sitemap index, which is a different feature.
 */
async function catalogue(apiBase: string): Promise<CatalogueRow[]> {
  const perPage = 60
  const maxPages = 20
  const rows: CatalogueRow[] = []

  for (let page = 1; page <= maxPages; page++) {
    let batch: CatalogueRow[]

    try {
      const response = await $fetch<{ data: CatalogueRow[] }>(`${apiBase}/api/v1/catalog/products`, {
        query: { per_page: perPage, page },
        headers: { Accept: 'application/json' },
      })

      batch = response.data ?? []
    } catch {
      // Deliberately swallowed. A sitemap missing its product URLs is a smaller problem
      // than a 500 where a crawler expected XML, which is how a site stops being crawled.
      break
    }

    rows.push(...batch)

    if (batch.length < perPage) {
      break
    }
  }

  return rows
}

/** A product name with an ampersand in it must not break the document. */
function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}
