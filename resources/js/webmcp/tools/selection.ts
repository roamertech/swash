import type { ToolDef } from '../types';
import { api } from '../api';
import { getState, patchState } from '../state';

/**
 * Every other tool file caps its return value. This one did not, and
 * selection.text has no length limit upstream — the 200-char truncation in
 * state.ts only applies to the compact copy sent to provideContext. Selecting
 * a paragraph therefore produced a reply over the 1.5K single-output limit,
 * which the host drops silently: the agent gets nothing back from the very
 * tools this group exists for.
 */
function clamp(value: string, max = 1400): string {
  const text = String(value ?? '');
  return text.length <= max ? text : `${text.slice(0, Math.max(0, max - 20))}… [truncated]`;
}

function snippet(value: string, limit = 80): string {
  return value.length > limit ? `${value.slice(0, Math.max(0, limit - 1))}…` : value;
}

export const selectionTools: ToolDef[] = [
  {
    name: 'rewrite_selection',
    description:
      'Rewrites the currently selected text. Accepts a plain-language instruction such as "more conversational" or "half as long". Only available while the editor has text selected.',
    inputSchema: {
      type: 'object',
      properties: {
        instruction: {
          type: 'string',
          description: 'How the text should change, in plain language.',
        },
        replacement: {
          type: 'string',
          description:
            'Optional. The exact replacement text. When omitted the agent should supply it after reading the selection.',
        },
      },
      required: ['instruction'],
    },
    annotations: {
      readOnlyHint: false,
    },
    execute: async (input: unknown) => {
      const selection = getState().selection;

      if (
        !selection ||
        typeof selection.text !== 'string' ||
        selection.text.trim().length === 0 ||
        typeof selection.blockId !== 'number'
      ) {
        return 'No text is selected. Ask the editor to select a passage first.';
      }

      const data = (input ?? {}) as Record<string, unknown>;
      const instruction = typeof data.instruction === 'string' ? data.instruction.trim() : '';

      if (!instruction) {
        return 'Provide a plain-language instruction for how the selected text should change.';
      }

      if (typeof data.replacement !== 'string') {
        return clamp(`Selected text (block #${selection.blockId}): "${selection.text}". Rewrite it as instructed ("${instruction}") and call rewrite_selection again with the replacement parameter.`);
      }

      const replacement = data.replacement;
      let content: string | null = null;

      try {
        const response = await api<any>('GET', `/blocks/${selection.blockId}`);
        const block = response?.data ?? response;

        if (typeof block?.content === 'string') {
          content = block.content;
        }
      } catch {
        content = null;
      }

      if (content === null) {
        const openPage = getState().openPage;

        if (openPage && typeof openPage.id === 'number') {
          try {
            const response = await api<any>('GET', `/pages/${openPage.id}`);
            const page = response?.data ?? response;
            const block = Array.isArray(page?.blocks)
              ? page.blocks.find((candidate: any) => candidate?.id === selection.blockId)
              : null;

            if (typeof block?.content === 'string') {
              content = block.content;
            }
          } catch {
            content = null;
          }
        }
      }

      if (content === null) {
        return `Could not load block #${selection.blockId}. Ask the editor to reopen the page or reselect the text.`;
      }

      const start = content.indexOf(selection.text);

      if (start === -1) {
        return `The selection no longer matches the content of block #${selection.blockId}. Ask the editor to reselect the passage.`;
      }

      const nextContent =
        content.slice(0, start) + replacement + content.slice(start + selection.text.length);

      try {
        await api<unknown>('PATCH', `/blocks/${selection.blockId}`, { content: nextContent });
      } catch (error) {
        return error instanceof Error
          ? `Could not save block #${selection.blockId}: ${error.message}`
          : `Could not save block #${selection.blockId}.`;
      }

      document.dispatchEvent(new CustomEvent('swash:refresh'));
      patchState({ hasUnsavedChanges: false });

      return `Rewrote block #${selection.blockId}. Replaced "${snippet(selection.text)}" with "${snippet(replacement)}" for instruction "${instruction}".`;
    },
  },
  {
    name: 'explain_selection',
    description:
      'Explains what the selected passage says, for an editor reviewing someone else\'s draft.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    annotations: {
      readOnlyHint: true,
    },
    execute: async (_input: unknown) => {
      const selection = getState().selection;

      if (
        !selection ||
        typeof selection.text !== 'string' ||
        selection.text.trim().length === 0 ||
        typeof selection.blockId !== 'number'
      ) {
        return 'No text is selected. Ask the editor to select a passage first.';
      }

      return clamp(`Selected passage (block #${selection.blockId}): "${selection.text}". Explain what this passage says and how it fits the draft.`);
    },
  },
];
