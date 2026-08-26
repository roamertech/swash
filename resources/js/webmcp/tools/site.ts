import type { ToolDef } from '../types'
import { api } from '../api'
import { patchState, getState } from '../state'
import { confirmWithUser, showConfirmDialog } from '../confirm'
import { enterMode } from '../modes'

const refresh = () => {
  document.dispatchEvent(new CustomEvent('swash:refresh'))
}

const clamp = (text: string, max = 1400): string => {
  const value = String(text ?? '')
  if (value.length <= max) return value
  return `${value.slice(0, Math.max(0, max - 20))}… [truncated]`
}

const serverMessage = (e: unknown): string => {
  const err = e as any
  const candidate = err?.data?.message ?? err?.message ?? err?.error ?? (typeof err === 'string' ? err : '')

  if (typeof candidate === 'string' && candidate.trim().length) {
    return candidate
  }

  try {
    const json = JSON.stringify(candidate)
    return typeof json === 'string' && json.length ? json : 'unknown error'
  } catch {
    return 'unknown error'
  }
}

const errorMessage = (e: unknown, prefix: string): string => `${prefix}: ${serverMessage(e)}`

const navLabels = (nav: unknown): string[] => {
  const items = Array.isArray(nav) ? nav : Array.isArray((nav as any)?.items) ? (nav as any).items : []

  return items
    .map((item: any) => String(item?.label ?? item?.title ?? item?.slug ?? ''))
    .filter(Boolean)
}

const navPairs = (nav: unknown, fallback: Array<{ label: string; slug: string }>): string => {
  const items = Array.isArray(nav) ? nav : Array.isArray((nav as any)?.items) ? (nav as any).items : fallback

  return items
    .map((item: any) => {
      const label = String(item?.label ?? 'Untitled')
      const rawSlug = String(item?.slug ?? '')
      const slug = rawSlug.startsWith('/') ? rawSlug : `/${rawSlug}`
      return `${label} → ${slug}`
    })
    .join(', ')
}

export const siteTools: ToolDef[] = [
  {
    name: 'get_site_overview',
    description:
      'Get the current site name, page count, theme name, theme type pair, mood, and navigation labels. Use this for quick site-level context without opening a page.',
    inputSchema: {
      type: 'object',
      properties: {},
      required: []
    },
    annotations: {
      readOnlyHint: true
    },
    async execute(_input: any, _client: any) {
      try {
        const data = await api('/site')
        const site = data?.site ?? {}
        const theme = data?.theme ?? {}
        const tokens = theme?.tokens ?? {}

        const typePair = tokens?.type_pair ?? tokens?.typePair ?? tokens?.typography ?? 'unknown'
        const mood = tokens?.mood ?? tokens?.vibe ?? 'unknown'
        const labels = navLabels(site?.nav)
        const navigation = labels.length ? labels.join(', ') : 'none'

        return clamp(
          `"${site?.name ?? 'Unknown site'}" — ${data?.page_count ?? 0} pages, theme "${theme?.name ?? 'Unknown theme'}" (${typePair}, ${mood}). Navigation: ${navigation}.`
        )
      } catch (e) {
        return clamp(errorMessage(e, 'Could not load site overview'))
      }
    }
  },
  {
    name: 'list_pages',
    description:
      'List all pages with id, kind, status, title, and slug. Use this to discover page ids before opening, reordering, renaming, or deleting pages.',
    inputSchema: {
      type: 'object',
      properties: {},
      required: []
    },
    annotations: {
      readOnlyHint: true
    },
    async execute(_input: any, _client: any) {
      try {
        const data = await api('/pages')
        const pages = Array.isArray(data?.pages) ? data.pages : []

        if (!pages.length) {
          return 'There are no pages yet.'
        }

        const lines = pages.map(
          (page: any) => `#${page.id} [${page.kind}/${page.status}] ${page.title} (/${page.slug})`
        )

        return clamp(lines.join('\n'))
      } catch (e) {
        return clamp(errorMessage(e, 'Could not list pages'))
      }
    }
  },
  {
    name: 'open_page',
    description:
      'Open an existing page by page_id or slug, set it as the active page, and switch to write mode. Use this before editing blocks.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number' },
        slug: { type: 'string' }
      },
      anyOf: [{ required: ['page_id'] }, { required: ['slug'] }]
    },
    async execute(input: any, _client: any) {
      try {
        const hasPageId = input?.page_id !== undefined && input?.page_id !== null && input?.page_id !== ''
        const pageId = hasPageId ? Number(input.page_id) : undefined
        const slug = typeof input?.slug === 'string' && input.slug.trim() ? input.slug.trim() : undefined

        if (hasPageId && !Number.isFinite(pageId)) {
          return 'page_id must be a number.'
        }

        if (pageId === undefined && !slug) {
          return 'Provide either page_id or slug to open a page.'
        }

        let page: any

        if (pageId !== undefined) {
          page = await api(`/pages/${pageId}`)
        } else {
          const data = await api('/pages')
          const pages = Array.isArray(data?.pages) ? data.pages : []
          const found = pages.find((p: any) => p.slug === slug)

          if (!found) {
            const slugs = pages.map((p: any) => p.slug).filter(Boolean).join(', ')
            return clamp(`No page matches slug "${slug}". Available slugs: ${slugs || 'none'}.`)
          }

          page = await api(`/pages/${found.id}`)
        }

        patchState({
          openPage: {
            id: page.id,
            title: page.title,
            kind: page.kind
          },
          status: page.status,
          cursorBlockId: null
        })

        await enterMode('write')
        refresh()

        return clamp(`Opened "${page.title}" and switched to write mode. It has ${page.blocks?.length ?? 0} blocks.`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not open page'))
      }
    }
  },
  {
    name: 'create_page',
    description:
      'Create a new page or post, open it, and switch to write mode. Use this when the site needs a new page or article.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string' },
        kind: { type: 'string', enum: ['page', 'post'] }
      },
      required: ['title', 'kind']
    },
    async execute(input: any, _client: any) {
      try {
        const title = String(input?.title ?? '').trim()
        const kind = input?.kind === 'post' ? 'post' : 'page'

        if (!title) {
          return 'A title is required to create a page.'
        }

        const page = await api('/pages', {
          method: 'POST',
          body: JSON.stringify({ title, kind })
        })

        patchState({
          openPage: {
            id: page.id,
            title: page.title,
            kind: page.kind
          },
          status: page.status,
          cursorBlockId: null
        })

        await enterMode('write')
        refresh()

        return clamp(`Created "${page.title}" (/${page.slug}) as a draft ${page.kind} and opened it.`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not create page'))
      }
    }
  },
  {
    name: 'set_slug',
    description:
      'Change the URL slug for a page. Use this to rename a page path. If the slug is taken, the API may suggest an available alternative.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number' },
        slug: { type: 'string' }
      },
      required: ['page_id', 'slug']
    },
    async execute(input: any, _client: any) {
      try {
        const pageId = Number(input?.page_id)
        const rawSlug = String(input?.slug ?? '').trim()
        const slug = rawSlug.replace(/^\/+/, '')

        if (!Number.isFinite(pageId)) {
          return 'page_id must be a number.'
        }

        if (!slug) {
          return 'A slug is required.'
        }

        await api(`/pages/${pageId}`, {
          method: 'PATCH',
          body: JSON.stringify({ slug })
        })

        refresh()

        return clamp(`Updated slug for page #${pageId} to /${slug}.`)
      } catch (e) {
        const err = e as any
        const text = serverMessage(e)

        if (err?.status === 422 || err?.response?.status === 422 || err?.data?.message) {
          return clamp(text)
        }

        return clamp(`Could not set slug: ${text}`)
      }
    }
  },
  {
    name: 'reorder_pages',
    description:
      'Set the order of pages by providing page ids in the desired sequence. Use this to change navigation or listing order.',
    inputSchema: {
      type: 'object',
      properties: {
        order: {
          type: 'array',
          items: { type: 'number' }
        }
      },
      required: ['order']
    },
    async execute(input: any, _client: any) {
      try {
        const order = Array.isArray(input?.order)
          ? input.order.map((id: any) => Number(id)).filter((id: number) => Number.isFinite(id))
          : []

        if (!order.length) {
          return 'Provide an order array of page ids.'
        }

        await api('/pages/reorder', {
          method: 'POST',
          body: JSON.stringify({ order })
        })

        refresh()

        let pages: any[] = []

        try {
          const data = await api('/pages')
          pages = Array.isArray(data?.pages) ? data.pages : []
        } catch {
          return 'The new order was saved, but I could not fetch page titles.'
        }

        const byId = new Map(pages.map((page: any) => [Number(page.id), page] as [number, any]))
        const titles = order.map((id: number) => byId.get(id)?.title ?? `Page ${id}`)
        const text = titles.map((title: string, index: number) => `${index + 1}. ${title}`).join('  ')

        return clamp(text || 'The new order was saved.')
      } catch (e) {
        return clamp(errorMessage(e, 'Could not reorder pages'))
      }
    }
  },
  {
    name: 'set_navigation',
    description:
      'Replace the site navigation items. Use this to add, remove, or reorder menu links. Each item needs a label and page slug.',
    inputSchema: {
      type: 'object',
      properties: {
        items: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              label: { type: 'string' },
              slug: { type: 'string' }
            },
            required: ['label', 'slug']
          }
        }
      },
      required: ['items']
    },
    async execute(input: any, _client: any) {
      try {
        if (!Array.isArray(input?.items)) {
          return 'Provide an items array for navigation.'
        }

        const items = input.items
          .map((item: any) => ({
            label: String(item?.label ?? '').trim(),
            slug: String(item?.slug ?? '').trim().replace(/^\/+/, '')
          }))
          .filter((item: any) => item.label && item.slug)

        const data = await api('/site/nav', {
          method: 'PATCH',
          body: JSON.stringify({ nav: { items } })
        })

        refresh()

        const text = navPairs(data?.nav, items)
        return clamp(text || 'Navigation is now empty.')
      } catch (e) {
        return clamp(errorMessage(e, 'Could not update navigation'))
      }
    }
  },
  {
    name: 'delete_page',
    description:
      'Delete a page and all of its blocks. Use this only when the user explicitly wants a page removed. This is destructive and asks for confirmation.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number' }
      },
      required: ['page_id']
    },
    async execute(input: any, client: any) {
      try {
        const pageId = Number(input?.page_id)

        if (!Number.isFinite(pageId)) {
          return 'page_id must be a number.'
        }

        const page = await api(`/pages/${pageId}`)
        const blockCount = Array.isArray(page?.blocks) ? page.blocks.length : 0

        const confirmed = await confirmWithUser(client, () =>
          showConfirmDialog({
            title: 'Delete this page?',
            body: `"${page.title}" will be removed, along with its ${blockCount} blocks. This cannot be undone.`,
            confirmLabel: 'Delete'
          })
        )

        if (!confirmed) {
          return 'The editor declined; nothing changed.'
        }

        await api(`/pages/${pageId}`, {
          method: 'DELETE'
        })

        const openPage = getState().openPage

        if (Number(openPage?.id) === pageId) {
          patchState({ openPage: null })
          await enterMode('site')
        }

        refresh()

        return clamp(`Deleted "${page.title}" and its ${blockCount} blocks.`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not delete page'))
      }
    }
  }
]
