/**
 * What a crawler may look at.
 *
 * Generated rather than a static file, because the disallow list has to name real paths
 * and a static file drifts from the router the moment somebody adds a page. Everything
 * behind a sign-in is excluded: an order page is not secret — it is protected — but a URL
 * that reaches a crawler is a URL that can reach a search result, and "it needed a login
 * anyway" is no comfort once the title is indexed.
 *
 * The checkout paths are excluded for a second reason: a crawler following a payment
 * return URL is a crawler walking into a state machine.
 */
export default defineEventHandler((event) => {
  const siteUrl = String(useRuntimeConfig().public.siteUrl ?? '').replace(/\/$/, '')

  const disallow = [
    '/account/',
    '/cart',
    '/checkout',
    '/projects/',
    '/favorites',
    '/auth/',
  ]

  const body = [
    'User-agent: *',
    ...disallow.map(path => `Disallow: ${path}`),
    '',
    `Sitemap: ${siteUrl}/sitemap.xml`,
    '',
  ].join('\n')

  setResponseHeader(event, 'Content-Type', 'text/plain; charset=utf-8')

  return body
})
