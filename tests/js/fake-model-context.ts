/**
 * A stand-in for the browser's document.modelContext.
 *
 * Registration is the only thing the tool layer asks of the host, so recording
 * the calls is enough to answer the question these tests exist for: which
 * tools can the agent see right now. Registering respects the AbortSignal the
 * real implementation uses, so aborting a group removes it here too.
 */
import type { ToolDef } from '../../resources/js/webmcp/types';

export interface FakeModelContext {
  registerTool(tool: ToolDef, options?: { signal?: AbortSignal }): Promise<unknown>;
  /** Names currently visible to the agent, in registration order. */
  visible(): string[];
  tool(name: string): ToolDef | undefined;
  reset(): void;
}

export function installFakeModelContext(): FakeModelContext {
  const registered = new Map<string, { tool: ToolDef; signal?: AbortSignal }>();

  const context: FakeModelContext = {
    async registerTool(tool, options) {
      if (options?.signal?.aborted) {
        throw new Error(`registration aborted for ${tool.name}`);
      }

      registered.set(tool.name, { tool, signal: options?.signal });

      options?.signal?.addEventListener('abort', () => {
        registered.delete(tool.name);
      });

      return { unregister: () => registered.delete(tool.name) };
    },
    visible() {
      return [...registered.entries()]
        .filter(([, entry]) => !entry.signal?.aborted)
        .map(([name]) => name);
    },
    tool(name) {
      return registered.get(name)?.tool;
    },
    reset() {
      registered.clear();
    },
  };

  (document as unknown as { modelContext: FakeModelContext }).modelContext = context;

  return context;
}
