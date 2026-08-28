import { api } from './webmcp/api';
import { bootWebMCP, enterMode, getMode, setSelectionTools, diagnostics } from './webmcp/modes';
import { patchState, getState } from './webmcp/state';
import { showConfirmDialog } from './webmcp/confirm';
import type { ModeName, ToolDef } from './webmcp/types';
import { siteTools } from './webmcp/tools/site';
import { selectionTools } from './webmcp/tools/selection';
import { writeTools } from './webmcp/tools/write';
import { designTools } from './webmcp/tools/design';
import { publishTools } from './webmcp/tools/publish';
import { presetTools } from './webmcp/tools/preset';


// Counted from the tool arrays themselves. A hardcoded total goes stale the
// moment a tool is added — and this number is the whole argument of the pane.
const TOTAL_TOOLS =
  1 + // switch_mode, always registered alongside every group
  siteTools.length + writeTools.length + designTools.length +
  publishTools.length + presetTools.length + selectionTools.length;

interface Block {
  id: number;
  type: string;
  content: string;
  asset_id: number | null;
  asset_path: string | null;
  position: number;
}

let openPageId: number | null = null;
let blocks: Block[] = [];
let selectionTimer: number | null = null;
const saveTimers = new Map<number, number>();

export function initEditor(): void {
  if (!document.querySelector('.editor')) return;

  const canvas = document.querySelector<HTMLElement>('#canvas');
  const pageList = document.querySelector<HTMLElement>('#page-list');
  const toolList = document.querySelector<HTMLElement>('#tool-list');
  const statusBadge = document.querySelector<HTMLElement>('#webmcp-status');
  const resetButton = document.querySelector<HTMLButtonElement>('#reset-demo');

  // The badge reports what actually happened, not merely that the object exists.
  // "document.modelContext is present" and "the agent can see the tools" are
  // different claims; only the second one is useful to a reviewer.
  const paintStatus = (): void => {
    if (!statusBadge) return;
    const d = diagnostics();
    statusBadge.classList.remove('ok', 'warn');

    if (!d.available) {
      statusBadge.classList.add('warn');
      statusBadge.textContent = 'WebMCP not detected';
      statusBadge.title =
        'ChatGPT desktop: Settings > Browser > Permissions > Enable site tools, and use GPT-5.6 Sol or Terra (Luna has WebMCP disabled). Chrome 149+: enable chrome://flags/#enable-webmcp-testing and restart.';
      showSetupHint();
      return;
    }

    if (d.failed.length > 0) {
      statusBadge.classList.add('warn');
      statusBadge.textContent = `${d.registered} tools · ${d.failed.length} failed`;
      statusBadge.title = `Failed to register: ${d.failed.join(', ')}`;
      return;
    }

    statusBadge.classList.add('ok');
    statusBadge.textContent = `${d.registered} tools registered`;
    statusBadge.title = 'The agent can see these tools right now. Switching mode replaces the set.';
  };

  const showSetupHint = (): void => {
    if (document.querySelector('.setup-hint')) return;
    const bar = document.querySelector('.editor-main');
    if (!bar) return;
    const hint = document.createElement('div');
    hint.className = 'setup-hint';
    const h = document.createElement('strong');
    h.textContent = 'This browser is not exposing site tools yet.';
    const p1 = document.createElement('p');
    p1.textContent =
      'ChatGPT desktop app: open Settings > Browser > Permissions and turn on "Enable site tools". Site tools need GPT-5.6 Sol or GPT-5.6 Terra — Luna has WebMCP disabled — and are unavailable in Enterprise and Edu workspaces.';
    const p2 = document.createElement('p');
    p2.textContent =
      'Chrome 149 or later: enable chrome://flags/#enable-webmcp-testing, then fully restart the browser.';
    const p3 = document.createElement('p');
    p3.textContent =
      'The editor still works normally without it — you just cannot drive it with an agent.';
    hint.append(h, p1, p2, p3);
    bar.parentElement?.insertBefore(hint, bar);
  };

  paintStatus();

  const syncModeTabs = (): void => {
    const mode = getMode();

    document.querySelectorAll<HTMLButtonElement>('.mode-tab').forEach((button) => {
      const isActive = button.dataset.mode === mode;
      button.setAttribute('aria-selected', String(isActive));
      button.classList.toggle('is-active', isActive);
    });
  };

  // Tabs switch immediately. Tool-triggered transitions are deferred until the
  // native execute call settles so Chrome 152 and earlier do not cancel it.
  document.querySelectorAll<HTMLButtonElement>('.mode-tab').forEach((button) => {
    button.addEventListener('click', async () => {
      const mode = button.dataset.mode as ModeName | undefined;
      if (!mode) return;

      try {
        await enterMode(mode);
      } catch (error) {
        console.error(error);
      }
    });
  });

  document.addEventListener('swash:mode', () => {
    paintStatus();
    syncModeTabs();
    void refreshToolList();
  });

  pageList?.addEventListener('click', (event) => {
    const item = (event.target as Element | null)?.closest<HTMLElement>('.page-item');
    if (!item) return;

    const id = Number(item.dataset.pageId);
    if (Number.isFinite(id)) {
      void openPage(id);
    }
  });

  // Tool calls update the shared WebMCP state directly. Keep the human-facing
  // canvas in lockstep when an agent opens, creates, or deletes a page.
  document.addEventListener('swash:state', (event) => {
    const state = (event as CustomEvent).detail as ReturnType<typeof getState> | undefined;
    const nextId = Number(state?.openPage?.id);

    if (!state?.openPage) {
      if (openPageId !== null) {
        openPageId = null;
        blocks = [];
        render();
      }
      return;
    }

    if (!Number.isFinite(nextId) || nextId === openPageId) return;

    void (async () => {
      try {
        const response = await api<any>('GET', `/pages/${nextId}`);
        const page = response?.data ?? response;

        // Ignore a stale response if another tool opened a page meanwhile.
        if (Number(getState().openPage?.id) !== nextId) return;

        openPageId = nextId;
        blocks = normalizeBlocks(page?.blocks);
        render();
        await refreshPageList();
      } catch (error) {
        console.error(error);
      }
    })();
  });

  document.addEventListener('selectionchange', () => {
    if (selectionTimer !== null) window.clearTimeout(selectionTimer);

    selectionTimer = window.setTimeout(() => {
      selectionTimer = null;

      const selection = window.getSelection();
      const text = selection?.toString() ?? '';
      let blockId: number | null = null;

      if (selection && selection.anchorNode) {
        const node =
          selection.anchorNode instanceof Element
            ? selection.anchorNode
            : selection.anchorNode.parentElement;

        if (!node?.closest('.blk-tag')) {
          const blockElement = node?.closest<HTMLElement>('.blk') ?? null;

          if (blockElement && canvas?.contains(blockElement)) {
            const rawId = blockElement.dataset.blockId;
            if (rawId) {
              const parsed = Number(rawId);
              if (Number.isFinite(parsed)) blockId = parsed;
            }
          }
        }
      }

      if (text.trim().length > 0 && blockId !== null) {
        patchState({ selection: { text, blockId } });
        void setSelectionTools(true);
      } else if (!getState().selection) {
        // Native tool discovery can briefly collapse the DOM Selection while
        // moving focus. Keep an existing selection context until an explicit
        // human pointer/keyboard action clears it, otherwise the agent can see
        // a selection tool and lose it immediately before invocation.
        patchState({ selection: null });
        void setSelectionTools(false);
      }

      void refreshToolList();
    }, 150);
  });

  const clearSelectionContext = (): void => {
    if (!getState().selection) return;

    patchState({ selection: null });
    void setSelectionTools(false);
    void refreshToolList();
  };

  document.addEventListener('pointerdown', clearSelectionContext, true);
  document.addEventListener('keyup', () => {
    if ((window.getSelection()?.toString() ?? '').trim() === '') {
      clearSelectionContext();
    }
  }, true);

  document.addEventListener('swash:refresh', async () => {
    if (openPageId !== null) {
      try {
        const response = await api<any>('GET', `/pages/${openPageId}`);
        const page = response?.data ?? response;
        blocks = normalizeBlocks(page?.blocks);
        render();
      } catch (error) {
        console.error(error);
      }
    }

    await refreshPageList();
  });

  resetButton?.addEventListener('click', async () => {
    const confirmed = await showConfirmDialog({
      title: 'Reset the demo?',
      body: 'All pages, blocks and generated images will be restored to their original state.',
      confirmLabel: 'Reset',
    });

    if (!confirmed) return;

    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    try {
      await fetch('/api/demo/reset', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-CSRF-TOKEN': token,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      });
    } catch (error) {
      console.error(error);
    }

    location.reload();
  });

  syncModeTabs();
  void bootWebMCP();
  void refreshToolList();

  async function openPage(id: number): Promise<void> {
    try {
      const response = await api<any>('GET', `/pages/${id}`);
      const page = response?.data ?? response;

      openPageId = id;
      blocks = normalizeBlocks(page?.blocks);

      patchState({
        openPage: {
          id: page?.id ?? id,
          title: page?.title ?? '',
          kind: page?.kind ?? 'page',
        },
        status: page?.status,
        cursorBlockId: null,
      });

      await enterMode('write');
      render();
    } catch (error) {
      console.error(error);
    }
  }

  function normalizeBlocks(value: unknown): Block[] {
    if (!Array.isArray(value)) return [];

    return value
      .filter((block: any) => block && typeof block.id === 'number')
      .map((block: any) => ({
        id: block.id,
        type: typeof block.type === 'string' ? block.type : 'paragraph',
        content: typeof block.content === 'string' ? block.content : '',
        asset_id: typeof block.asset_id === 'number' ? block.asset_id : null,
        asset_path: typeof block.asset_path === 'string' ? block.asset_path : null,
        position: typeof block.position === 'number' ? block.position : 0,
      }))
      .sort((a: Block, b: Block) => a.position - b.position);
  }

  function render(): void {
    if (!canvas) return;

    saveTimers.forEach((timer) => window.clearTimeout(timer));
    saveTimers.clear();
    canvas.replaceChildren();

    if (openPageId === null) {
      const empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = 'Open a page from the list to start editing.';
      canvas.appendChild(empty);
      return;
    }

    for (const block of blocks) {
      const wrapper = document.createElement('div');
      wrapper.className = `blk blk-${block.type}`;
      wrapper.dataset.blockId = String(block.id);
      wrapper.tabIndex = 0;

      const tag = document.createElement('span');
      tag.className = 'blk-tag';
      tag.contentEditable = 'false';
      tag.textContent = `#${block.id} ${block.type}`;
      wrapper.appendChild(tag);

      const editable = renderBlockContent(wrapper, block);

      if (editable) {
        attachEditableListeners(editable, block);
      }

      wrapper.addEventListener('focusin', () => {
        patchState({ cursorBlockId: block.id });
      });

      canvas.appendChild(wrapper);
    }
  }

  function renderBlockContent(wrapper: HTMLElement, block: Block): HTMLElement | null {
    switch (block.type) {
      case 'heading': {
        const heading = document.createElement('h2');
        heading.contentEditable = 'true';
        heading.textContent = block.content;
        wrapper.appendChild(heading);
        return heading;
      }

      case 'paragraph':
      case 'quote': {
        const paragraph = document.createElement('p');
        paragraph.contentEditable = 'true';
        paragraph.textContent = block.content;
        wrapper.appendChild(paragraph);
        return paragraph;
      }

      case 'list': {
        const list = document.createElement('ul');
        list.contentEditable = 'true';

        const lines = block.content.length > 0 ? block.content.split('\n') : [''];

        for (const line of lines) {
          const item = document.createElement('li');
          item.textContent = line;
          list.appendChild(item);
        }

        wrapper.appendChild(list);
        return list;
      }

      case 'code': {
        const pre = document.createElement('pre');
        pre.contentEditable = 'true';
        pre.textContent = block.content;
        wrapper.appendChild(pre);
        return pre;
      }

      case 'divider': {
        wrapper.appendChild(document.createElement('hr'));
        return null;
      }

      case 'image': {
        const figure = document.createElement('figure');

        const image = document.createElement('img');
        // Use the path the server resolved. Building one here produced
        // /assets/{id}, which has no route and 404s, so every image in the
        // editor was broken while the public site rendered fine.
        image.src =
          block.asset_path || 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
        image.alt = block.content || `Image ${block.id}`;

        const caption = document.createElement('figcaption');
        caption.contentEditable = 'true';
        caption.textContent = block.content;

        figure.appendChild(image);
        figure.appendChild(caption);
        wrapper.appendChild(figure);

        return caption;
      }

      default: {
        const paragraph = document.createElement('p');
        paragraph.contentEditable = 'true';
        paragraph.textContent = block.content;
        wrapper.appendChild(paragraph);
        return paragraph;
      }
    }
  }

  function attachEditableListeners(element: HTMLElement, block: Block): void {
    element.addEventListener('input', () => {
      patchState({ hasUnsavedChanges: true });

      const existing = saveTimers.get(block.id);
      if (existing !== undefined) window.clearTimeout(existing);

      const timer = window.setTimeout(() => {
        saveTimers.delete(block.id);
        void saveBlock(element, block);
      }, 600);

      saveTimers.set(block.id, timer);
    });

    element.addEventListener('focus', () => {
      patchState({ cursorBlockId: block.id });
    });
  }

  async function saveBlock(element: HTMLElement, block: Block): Promise<void> {
    try {
      const content = serializeEditable(element, block);
      await api<unknown>('PATCH', `/blocks/${block.id}`, { content });

      block.content = content;
      patchState({ hasUnsavedChanges: false });
    } catch (error) {
      console.error(error);
      patchState({ hasUnsavedChanges: true });
    }
  }

  function serializeEditable(element: HTMLElement, block: Block): string {
    if (block.type === 'list') {
      return Array.from(element.querySelectorAll('li'))
        .map((item) => item.textContent ?? '')
        .join('\n');
    }

    return element.textContent ?? '';
  }

  async function refreshPageList(): Promise<void> {
    if (!pageList) return;

    try {
      const response = await api<any>('GET', '/pages');
      const payload = response?.data ?? response;

      const pages = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
          ? payload.data
          : Array.isArray(payload?.pages)
            ? payload.pages
            : [];

      pageList.replaceChildren();

      for (const page of pages) {
        const item = document.createElement('li');
        item.className = 'page-item';
        item.dataset.pageId = String(page.id);
        item.tabIndex = 0;
        item.textContent = page.title ?? `Page ${page.id}`;

        if (page.id === openPageId) {
          item.classList.add('is-active');
        }

        pageList.appendChild(item);
      }
    } catch (error) {
      console.error(error);
    }
  }

  // Must mirror MODES in webmcp/modes.ts exactly. If the two drift apart, the
  // pane claims the agent can see something it cannot — which is worse than
  // showing nothing, because the whole point of the pane is to be trustworthy.
  function toolsForMode(mode: ModeName | null | undefined): ToolDef[] {
    switch (mode) {
      case 'site':
        return siteTools;
      case 'write':
        return writeTools;
      case 'design':
        return [...presetTools, ...designTools];
      case 'publish':
        return publishTools;
      default:
        return siteTools;
    }
  }

  async function refreshToolList(): Promise<void> {
    if (!toolList) return;

    const mode = getMode();
    const visibleTools: Array<ToolDef | { name: string }> = [
      { name: 'switch_mode' },
      ...toolsForMode(mode),
    ];

    if (getState().selection) {
      visibleTools.push(...selectionTools);
    }

    const seen = new Set<string>();
    toolList.replaceChildren();

    for (const tool of visibleTools) {
      if (!tool?.name || seen.has(tool.name)) continue;

      seen.add(tool.name);

      const item = document.createElement('li');
      const name = document.createElement('code');
      name.textContent = tool.name;

      item.appendChild(name);
      toolList.appendChild(item);
    }

    const count = document.createElement('li');
    count.className = 'tool-count';
    count.textContent = `${seen.size} tools in scope · ${TOTAL_TOOLS} in the app`;
    toolList.appendChild(count);
  }
}
