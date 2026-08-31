import type { ToolDef, ModeName, SharedState } from './types';
import { patchState, getState } from './state';
import { siteTools } from './tools/site';
import { writeTools } from './tools/write';
import { designTools } from './tools/design';
import { presetTools } from './tools/preset';
import { publishTools } from './tools/publish';
import { selectionTools } from './tools/selection';
import { getModelContext, hasModelContext } from './context';

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
let modeGeneration = 0;

/**
 * Everything the agent can see right now, by name.
 *
 * WebMCP is a one-way surface: the page hands tools out, and only the agent
 * calls them. That leaves a person with no way to exercise a tool by hand,
 * which makes manual testing depend on having an agent attached. Keeping the
 * registered definitions here lets the console call the same execute the
 * agent would, with the same input, and nothing else.
 */
const active = new Map<string, ToolDef>();
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
async function registerGroup(
    tools: ToolDef[],
    signal: AbortSignal,
    failures: string[] = failedTools,
): Promise<number> {
    const modelContext = getModelContext();

    if (!modelContext) {
        return 0;
    }

    let registered = 0;

    for (const tool of tools) {
        if (signal.aborted) {
            return registered;
        }

        try {
            await modelContext.registerTool(tool, { signal });
            registered += 1;

            active.set(tool.name, tool);
            signal.addEventListener('abort', () => active.delete(tool.name), { once: true });
        } catch (error) {
            // A mode switch that has been superseded aborts the registration it
            // was in the middle of. That is the design working, not a failure —
            // reporting it as one puts a warning in the console during ordinary
            // use and pushes a healthy tool onto the failed list.
            if (signal.aborted || (error as { name?: string } | null)?.name === 'AbortError') {
                return registered;
            }

            console.warn(`[swash] tool "${tool.name}" failed to register`, error);
            failures.push(tool.name);
        }
    }

    return registered;
}

export async function enterMode(mode: ModeName): Promise<void> {
    const generation = ++modeGeneration;

    // A selection belongs to the page and mode it was made in. Leaving it
    // registered across a switch let rewrite_selection fire against a block on
    // a page that is no longer open, silently editing the wrong page.
    void setSelectionTools(false);
    patchState({ selection: null });

    modeController?.abort();
    modeController = new AbortController();
    const signal = modeController.signal;
    currentMode = mode;

    // Failures collect locally. Sharing the module-level array let a call that
    // had already lost the race contaminate the winner's report.
    const failures: string[] = [];
    const always = await registerGroup(alwaysOnTools(), signal, failures);
    const group = await registerGroup(MODES[mode](), signal, failures);

    // A newer enterMode started while this one was awaiting registration, so
    // it owns the shared state now. Writing here would publish this call's
    // mode and count over the newer one's, leaving currentMode, state.mode and
    // the tools actually registered describing three different things — the
    // agent then calls tools that were never registered and gets an opaque
    // "tool not found" with nothing pointing at the cause.
    if (generation !== modeGeneration) {
        return;
    }

    registeredCount = always + group;
    failedTools.length = 0;
    failedTools.push(...failures);

    patchState({ mode });
    document.dispatchEvent(
        new CustomEvent('swash:mode', {
            detail: { mode, registered: registeredCount, failed: [...failedTools] },
        }),
    );
}

/**
 * Chrome 152 and earlier cancel an in-flight tool when the AbortSignal used to
 * register that tool is aborted. A tool that switches its own mode must let
 * its execute callback settle before replacing the registration group.
 */
let deferredSwitch: Promise<void> = Promise.resolve();

/**
 * Resolves once a mode change queued by a tool has finished registering.
 *
 * enterMode sets currentMode synchronously but registers asynchronously, so
 * there is a window where getMode() already reports the new mode and no tool
 * is in scope yet. An agent never sees it — it calls across turns — but a
 * caller chaining awaits in one context lands in it every time.
 */
export function modeSettled(): Promise<void> {
    return deferredSwitch;
}

export function enterModeAfterTool(mode: ModeName): void {
    // Remember where the world was when this was scheduled. If anything else
    // changed mode in the meantime — a human clicking a tab, or the agent
    // calling switch_mode straight after this tool — that intent is newer than
    // ours, and firing here would silently undo it. The generation guard inside
    // enterMode cannot catch this case: the deferred call genuinely starts
    // later, so it legitimately wins the race it should not be in.
    const scheduledAt = modeGeneration;

    deferredSwitch = new Promise<void>((resolve) => {
        window.setTimeout(() => {
            if (modeGeneration !== scheduledAt) {
                resolve();
                return;
            }

            void enterMode(mode).finally(resolve);
        }, 0);
    });
}

export async function setSelectionTools(active: boolean): Promise<void> {
    // rewrite_selection and explain_selection act on a block inside the open
    // page, so they only mean anything in write mode. Nothing stopped the
    // editor registering them after a selection made in site, design or
    // publish mode: the agent was shown two tools that could not work there,
    // and the visible count went past its cap.
    if (active && currentMode !== 'write') {
        return;
    }

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

                enterModeAfterTool(mode as ModeName);
                return `Switched to ${mode} mode.`;
            },
        } as ToolDef,
    ];
}

export async function bootWebMCP(): Promise<void> {
    if (!hasModelContext()) {
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

/** Tool names the agent can see at this moment. */
export function currentTools(): string[] {
    return [...active.keys()];
}

/**
 * Call a registered tool the way the agent would.
 *
 * The client argument is null, so confirmWithUser falls back to the page's own
 * dialog: a destructive tool still stops and waits for a click, which is the
 * behaviour worth testing rather than skipping.
 */
export async function invokeTool(name: string, input: Record<string, unknown> = {}): Promise<unknown> {
    const tool = active.get(name);

    if (!tool) {
        throw new Error(
            `"${name}" is not registered. Mode is "${currentMode}"; in scope: ${[...active.keys()].join(', ')}`,
        );
    }

    return (tool as unknown as { execute(input: unknown, client: unknown): Promise<unknown> })
        .execute(input, null);
}

export function diagnostics(): { available: boolean; registered: number; failed: string[] } {
    return {
        available: hasModelContext(),
        registered: registeredCount,
        failed: [...failedTools],
    };
}
