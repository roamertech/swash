import type { ToolDef, ModeName, SharedState } from './types';
import { patchState, getState } from './state';
import { siteTools } from './tools/site';
import { writeTools } from './tools/write';
import { designTools } from './tools/design';
import { presetTools } from './tools/preset';
import { publishTools } from './tools/publish';
import { selectionTools } from './tools/selection';

const MODES: Record<ModeName, () => ToolDef[]> = {
    site: () => siteTools,
    write: () => writeTools,
    design: () => [...presetTools, ...designTools],
    publish: () => publishTools,
};

let modeController: AbortController | null = null;
let selectionController: AbortController | null = null;
let currentMode: ModeName = 'site';
let registeredCount = 0;
const failedTools: string[] = [];

export function getMode(): ModeName {
    return currentMode;
}

/**
 * Registers a group and reports how many tools actually landed.
 * A failed registration must never be silent: "the object exists" and
 * "the agent can see 34 tools" are different claims, and only the second
 * one matters. The count is surfaced in the editor status badge.
 */
async function registerGroup(tools: ToolDef[], signal: AbortSignal): Promise<number> {
    if (!document.modelContext) {
        return 0;
    }

    let registered = 0;

    for (const tool of tools) {
        if (signal.aborted) {
            return registered;
        }

        try {
            await document.modelContext.registerTool(tool, { signal });
            registered += 1;
        } catch (error) {
            console.warn(`[swash] tool "${tool.name}" failed to register`, error);
            failedTools.push(tool.name);
        }
    }

    return registered;
}

export async function enterMode(mode: ModeName): Promise<void> {
    modeController?.abort();
    modeController = new AbortController();
    currentMode = mode;

    failedTools.length = 0;
    const always = await registerGroup(alwaysOnTools(), modeController.signal);
    const group = await registerGroup(MODES[mode](), modeController.signal);
    registeredCount = always + group;

    patchState({ mode });
    document.dispatchEvent(
        new CustomEvent('swash:mode', {
            detail: { mode, registered: registeredCount, failed: [...failedTools] },
        }),
    );
}

export async function setSelectionTools(active: boolean): Promise<void> {
    if (active) {
        if (selectionController) {
            return;
        }

        selectionController = new AbortController();
        await registerGroup(selectionTools, selectionController.signal);
        return;
    }

    if (!selectionController) {
        return;
    }

    selectionController.abort();
    selectionController = null;
}

function alwaysOnTools(): ToolDef[] {
    return [
        {
            name: 'switch_mode',
            description:
                'Switches the workspace mode. Modes are: site (pages, navigation), write (edit the open page), design (theme and images), publish (SEO, links, publishing). Each mode exposes a different set of tools.',
            inputSchema: {
                type: 'object',
                properties: {
                    mode: {
                        type: 'string',
                        enum: ['site', 'write', 'design', 'publish'],
                        description: 'The mode to switch to.',
                    },
                },
                required: ['mode'],
            },
            execute: async (input?: { mode?: unknown }) => {
                const mode = typeof input?.mode === 'string' ? input.mode : '';

                if (!['site', 'write', 'design', 'publish'].includes(mode)) {
                    return `Unknown mode "${mode}". Valid modes: site, write, design, publish.`;
                }

                const state = getState() as SharedState | undefined;

                if (mode === 'write' && !state?.openPage) {
                    return 'No page is open. Use open_page from site mode first, which switches to write mode automatically.';
                }

                await enterMode(mode as ModeName);
                return `Switched to ${mode} mode.`;
            },
        } as ToolDef,
    ];
}

export async function bootWebMCP(): Promise<void> {
    if (!document.modelContext) {
        console.warn(
            '[swash] WebMCP is not available in this browser. Enable chrome://flags/#enable-webmcp-testing or use the ChatGPT desktop browser.',
        );
        return;
    }

    await enterMode('site');
}

export function wrongMode(needed: ModeName, action: string): string {
    return `Cannot ${action} in ${currentMode} mode. Call switch_mode with mode "${needed}" first, then retry.`;
}

export function diagnostics(): { available: boolean; registered: number; failed: string[] } {
    return {
        available: typeof document.modelContext?.registerTool === 'function',
        registered: registeredCount,
        failed: [...failedTools],
    };
}
