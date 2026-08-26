/**
 * The tags a page needs to be found, and to look right when somebody shares it.
 *
 * One composable rather than a `useHead` block per page, because the parts that are easy
 * to forget are the parts nobody sees while building: a canonical URL, an Open Graph image,
 * a `noindex` on a page that must never be in a search result. A page that forgets the
 * first is competing with itself for its own ranking; a page that forgets the last can put
 * somebody's order number in Google.
 *
 * Descriptions are trimmed at a word boundary. A snippet cut mid-word is the difference
 * between a result that reads as written and one that reads as generated.
 */

interface SeoInput {
  title?: string
  description?: string
  /** Absolute or root-relative; the canonical is built from the site origin either way. */
  path?: string
  image?: string | null
  /** `article` for editorial pages, `product` for a listing. Defaults to `website`. */
  type?: 'website' | 'article' | 'product'
  /**
   * Keeps the page out of search results entirely.
   *
   * Anything behind a sign-in: an order, a basket, a project. These pages are not secret —
   * they are protected — but a URL that reaches a crawler is a URL that can reach a search
   * result, and "it needed a login anyway" is no comfort once the title is indexed.
   */
  noindex?: boolean
}

/** Cuts at a word boundary, because a snippet ending mid-word reads as machine output. */
export function trimForSnippet(text: string, limit = 160): string {
  const clean = text.replace(/\s+/g, ' ').trim()

  if (clean.length <= limit) {
    return clean
  }

  const cut = clean.slice(0, limit)
  const lastSpace = cut.lastIndexOf(' ')

  return (lastSpace > limit * 0.6 ? cut.slice(0, lastSpace) : cut).replace(/[,;:.\s]+$/, '') + '…'
}

export function useSeo(input: SeoInput | (() => SeoInput)): void {
  const config = useRuntimeConfig()
  const route = useRoute()

  const origin = String(config.public.siteUrl ?? '').replace(/\/$/, '')
  const appName = String(config.public.appName ?? 'RefConcept')

  useHead(() => {
    const seo = typeof input === 'function' ? input() : input

    const path = seo.path ?? route.path
    const canonical = origin === '' ? path : origin + path
    const description = seo.description === undefined ? undefined : trimForSnippet(seo.description)

    const described = description !== undefined && description !== ''

    const meta = [
      ...(described ? [{ name: 'description', content: description }] : []),
      ...(described ? [{ property: 'og:description', content: description }] : []),

      // The bare title in Open Graph, not the template: a share card reading
      // "Sepetim · RefConcept · RefConcept" is what happens when both are applied.
      ...(seo.title === undefined ? [] : [{ property: 'og:title', content: seo.title }]),

      { property: 'og:type', content: seo.type ?? 'website' },
      { property: 'og:site_name', content: appName },
      { property: 'og:url', content: canonical },
      { name: 'twitter:card', content: seo.image ? 'summary_large_image' : 'summary' },

      ...(seo.image
        ? [{ property: 'og:image', content: seo.image.startsWith('http') ? seo.image : origin + seo.image }]
        : []),

      ...(seo.noindex === true ? [{ name: 'robots', content: 'noindex, nofollow' }] : []),
    ]

    return {
      title: seo.title,
      meta,
      // Omitted entirely on a noindex page: a canonical is a request to index one URL
      // rather than another, and asking that of a page that must not be indexed at all is
      // a contradiction crawlers resolve unpredictably.
      link: seo.noindex === true ? [] : [{ rel: 'canonical', href: canonical }],
    }
  })
}
