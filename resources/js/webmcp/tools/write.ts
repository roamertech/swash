import type { ToolDef } from '../types'
import { api } from '../api'
import { patchState, getState } from '../state'
import { confirmWithUser, showConfirmDialog } from '../confirm'

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

export const writeTools: ToolDef[] = [
  {
    name: 'get_outline',
    description:
      'Get a compact outline of the open page and its blocks. Use this as the primary map before reading, replacing, inserting, moving, or deleting blocks.',
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
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const outline = await api(`/pages/${page.id}/outline`)
        const blocks = Array.isArray(outline?.blocks) ? outline.blocks : []
        const title = outline?.title ?? page.title

        if (!blocks.length) {
          return clamp(`${title} has no blocks yet.`)
        }

        const lines = blocks.map((block: any) => `#${block.id} ${block.type}: ${block.excerpt ?? ''}`)
        return clamp([`${title} outline:`, ...lines].join('\n'))
      } catch (e) {
        return clamp(errorMessage(e, 'Could not load outline'))
      }
    }
  },
  {
    name: 'read_block',
    description:
      'Read one block from the open page by block id. Use this to inspect exact block content before editing or deleting it.',
    inputSchema: {
      type: 'object',
      properties: {
        block_id: { type: 'number' }
      },
      required: ['block_id']
    },
    annotations: {
      readOnlyHint: true
    },
    async execute(input: any, _client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const blockId = Number(input?.block_id)

        if (!Number.isFinite(blockId)) {
          return 'block_id must be a number.'
        }

        const block = await api(`/blocks/${blockId}`)
        const original = String(block?.content ?? '')

        let content = original
        if (content.length > 1200) {
          content = `${content.slice(0, 1200)}… [truncated, ${original.length} characters total]`
        }

        return clamp(`#${block?.id ?? blockId} ${block?.type ?? 'block'} (position ${block?.position ?? 0}):\n${content}`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not read block'))
      }
    }
  },
  {
    name: 'replace_block',
    description:
      'Replace the content of an existing block. Use this for targeted edits. Provide the full replacement content for that block.',
    inputSchema: {
      type: 'object',
      properties: {
        block_id: { type: 'number' },
        content: { type: 'string' }
      },
      required: ['block_id', 'content']
    },
    async execute(input: any, _client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const blockId = Number(input?.block_id)
        const content = String(input?.content ?? '')

        if (!Number.isFinite(blockId)) {
          return 'block_id must be a number.'
        }

        await api(`/blocks/${blockId}`, {
          method: 'PATCH',
          body: JSON.stringify({ content })
        })

        refresh()
        patchState({ cursorBlockId: blockId })

        const preview = content.slice(0, 60)
        return clamp(`Updated block #${blockId}.${preview ? ` ${preview}` : ''}`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not update the block'))
      }
    }
  },
  {
    name: 'insert_block',
    description:
      'Insert a new block into the open page. Omit after_block_id to append to the end. For long articles, call this repeatedly with one block at a time instead of sending one huge string. Use insert_image for existing images.',
    inputSchema: {
      type: 'object',
      properties: {
        type: {
          type: 'string',
          enum: ['heading', 'paragraph', 'image', 'quote', 'list', 'code', 'divider']
        },
        content: { type: 'string' },
        after_block_id: { type: 'number' },
        asset_id: { type: 'number' }
      },
      required: ['type']
    },
    async execute(input: any, _client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const type = String(input?.type ?? '')
        const allowed = ['heading', 'paragraph', 'image', 'quote', 'list', 'code', 'divider']

        if (!allowed.includes(type)) {
          return 'type must be one of heading, paragraph, image, quote, list, code, or divider.'
        }

        const body: any = {
          type,
          content: String(input?.content ?? '')
        }

        const afterBlockId = input?.after_block_id !== undefined ? Number(input.after_block_id) : undefined
        if (afterBlockId !== undefined && Number.isFinite(afterBlockId)) {
          body.after_block_id = afterBlockId
        }

        const assetId = input?.asset_id !== undefined ? Number(input.asset_id) : undefined
        if (assetId !== undefined && Number.isFinite(assetId)) {
          body.asset_id = assetId
        }

        const block = await api(`/pages/${page.id}/blocks`, {
          method: 'POST',
          body: JSON.stringify(body)
        })

        refresh()
        patchState({ cursorBlockId: block?.id ?? null })

        return clamp(`Inserted ${block?.type ?? type} block #${block?.id} on "${page.title}".`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not insert block'))
      }
    }
  },
  {
    name: 'move_block',
    description:
      'Move a block to a new position within the open page. Use this to reorder blocks without changing their content.',
    inputSchema: {
      type: 'object',
      properties: {
        block_id: { type: 'number' },
        position: { type: 'number' }
      },
      required: ['block_id', 'position']
    },
    async execute(input: any, _client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const blockId = Number(input?.block_id)
        const position = Number(input?.position)

        if (!Number.isFinite(blockId)) {
          return 'block_id must be a number.'
        }

        if (!Number.isFinite(position)) {
          return 'position must be a number.'
        }

        await api(`/blocks/${blockId}/move`, {
          method: 'POST',
          body: JSON.stringify({ position })
        })

        refresh()

        return clamp(`Moved block #${blockId} to position ${position}.`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not move block'))
      }
    }
  },
  {
    name: 'delete_block',
    description:
      'Delete one block from the open page. Use this only when the user explicitly wants the block removed. This is destructive and asks for confirmation.',
    inputSchema: {
      type: 'object',
      properties: {
        block_id: { type: 'number' }
      },
      required: ['block_id']
    },
    async execute(input: any, client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const blockId = Number(input?.block_id)

        if (!Number.isFinite(blockId)) {
          return 'block_id must be a number.'
        }

        const block = await api(`/blocks/${blockId}`)
        const detail = String(block?.content ?? '').slice(0, 300)

        const confirmed = await confirmWithUser(client, () =>
          showConfirmDialog({
            title: 'Delete this block?',
            body: `Block #${blockId} will be removed. This cannot be undone.`,
            detail,
            confirmLabel: 'Delete'
          })
        )

        if (!confirmed) {
          return 'The editor declined; nothing changed.'
        }

        await api(`/blocks/${blockId}`, {
          method: 'DELETE'
        })

        refresh()

        return clamp(`Deleted block #${blockId}.`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not delete block'))
      }
    }
  },
  {
    name: 'insert_image',
    description:
      'Insert an existing library image into the open page. To CREATE a new image, switch to design mode and use generate_image; this tool only places an image that already exists in the library.',
    inputSchema: {
      type: 'object',
      properties: {
        asset_id: { type: 'number' },
        after_block_id: { type: 'number' },
        caption: { type: 'string' }
      },
      required: ['asset_id']
    },
    async execute(input: any, _client: any) {
      try {
        const page = getState().openPage
        if (!page) return 'No page is open. Use open_page from site mode first.'

        const assetId = Number(input?.asset_id)

        if (!Number.isFinite(assetId)) {
          return 'asset_id must be a number.'
        }

        const body: any = {
          type: 'image',
          content: String(input?.caption ?? ''),
          asset_id: assetId
        }

        const afterBlockId = input?.after_block_id !== undefined ? Number(input.after_block_id) : undefined
        if (afterBlockId !== undefined && Number.isFinite(afterBlockId)) {
          body.after_block_id = afterBlockId
        }

        const block = await api(`/pages/${page.id}/blocks`, {
          method: 'POST',
          body: JSON.stringify(body)
        })

        refresh()
        patchState({ cursorBlockId: block?.id ?? null })

        return clamp(`Inserted image block #${block?.id} on "${page.title}".`)
      } catch (e) {
        return clamp(errorMessage(e, 'Could not insert image'))
      }
    }
  }
]
