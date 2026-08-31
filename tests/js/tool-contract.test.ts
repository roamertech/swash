import { describe, expect, it, beforeEach } from 'vitest';
import { installFakeModelContext } from './fake-model-context';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

import { siteTools } from '../../resources/js/webmcp/tools/site';
import { writeTools } from '../../resources/js/webmcp/tools/write';
import { designTools } from '../../resources/js/webmcp/tools/design';
import { presetTools } from '../../resources/js/webmcp/tools/preset';
import { publishTools } from '../../resources/js/webmcp/tools/publish';
import { selectionTools } from '../../resources/js/webmcp/tools/selection';
import type { ToolDef } from '../../resources/js/webmcp/types';

const ROOT = resolve(__dirname, '../..');

/**
 * What the agent should see in each mode. Hardcoded on purpose: the point of a
 * contract test is to fail when the set changes, so that a change to the tool
 * split has to be a decision someone makes rather than something that drifts.
 */
const MODE_TOOLS: Record<string, ToolDef[]> = {
  site: siteTools,
  write: writeTools,
  design: [...presetTools, ...designTools],
  publish: publishTools,
};

const ALWAYS_ON = ['switch_mode'];

const EXPECTED: Record<string, string[]> = {
  site: [
    'get_site_overview', 'set_site_identity', 'set_footer', 'list_pages',
    'open_page', 'create_page', 'set_slug', 'reorder_pages',
    'set_navigation', 'delete_page',
  ],
  write: [
    'get_outline', 'read_block', 'replace_block', 'insert_block',
    'move_block', 'delete_block', 'insert_image',
  ],
  design: [
    'list_presets', 'apply_preset', 'get_theme', 'set_theme', 'set_type_pair',
    'revert_theme', 'generate_image', 'regenerate_image', 'create_svg_graphic',
    'search_media', 'set_image_alt',
  ],
  publish: [
    'apply_seo', 'check_seo', 'link_to_page', 'check_links', 'read_submissions',
    'preview_changes', 'publish_page', 'list_revisions', 'revert_to_revision',
  ],
};

/**
 * The most tools the agent can see at once, across every mode and with a text
 * selection active. The spec's first claim is that the agent only ever sees a
 * handful; this is the number that claim has to be written against.
 */
const VISIBILITY_CAP = 12;

const ALL_TOOLS: ToolDef[] = [
  ...siteTools, ...writeTools, ...designTools,
  ...presetTools, ...publishTools, ...selectionTools,
];

/**
 * Where each required parameter comes from.
 *
 * Four separate defects were the same shape: a tool needed a value that no
 * tool in its own mode could produce, so an agent restricted to tool calls
 * simply could not complete the action. Declaring the source here means the
 * fifth one fails this test instead of shipping.
 */
const PARAM_SOURCES: Record<string, Record<string, string>> = {
  switch_mode: { mode: "enum in the schema" },
  set_site_identity: { site_name: "the editor types it" },
  set_footer: { statement: "the editor types it", contact_email: "the editor types it" },
  create_page: { title: "the editor types it", kind: "enum in the schema" },
  set_slug: { page_id: "list_pages, same mode", slug: "the editor types it" },
  reorder_pages: { order: "list_pages, same mode" },
  set_navigation: { items: "list_pages, same mode" },
  delete_page: { page_id: "list_pages, same mode" },
  read_block: { block_id: "get_outline, same mode" },
  replace_block: { block_id: "get_outline, same mode", content: "the editor types it" },
  insert_block: { type: "enum in the schema" },
  move_block: { block_id: "get_outline, same mode", position: "get_outline reports positions" },
  delete_block: { block_id: "get_outline, same mode" },
  set_type_pair: { type_pair: "enum in the schema" },
  generate_image: { prompt: "the editor types it", placement: "enum in the schema" },
  regenerate_image: { asset_id: "search_media, same mode", adjustment: "the editor types it" },
  create_svg_graphic: { kind: "enum in the schema" },
  set_image_alt: { asset_id: "search_media, same mode", alt: "the editor types it" },
  apply_preset: { preset: "list_presets, same mode" },
  link_to_page: { text: "the editor types it" },
  rewrite_selection: { instruction: "the editor types it" },
};
describe('character budgets', () => {
  it.each(ALL_TOOLS.map((tool) => [tool.name, tool] as const))(
    '%s stays inside the name and description budgets',
    (name, tool) => {
      expect(name.length, `tool name "${name}"`).toBeLessThanOrEqual(30);
      expect(tool.description.length, `description of "${name}"`).toBeLessThanOrEqual(500);
    },
  );

  it('every parameter description stays inside 150 characters', () => {
    const over: string[] = [];

    for (const tool of ALL_TOOLS) {
      const properties = (tool.inputSchema as any)?.properties ?? {};

      for (const [param, schema] of Object.entries<any>(properties)) {
        const description = schema?.description;

        if (typeof description === 'string' && description.length > 150) {
          over.push(`${tool.name}.${param} (${description.length})`);
        }
      }
    }

    expect(over).toEqual([]);
  });
});

describe('naming', () => {
  it('names are unique across every mode', () => {
    const names = ALL_TOOLS.map((tool) => tool.name);
    const duplicates = names.filter((name, index) => names.indexOf(name) !== index);

    expect(duplicates).toEqual([]);
  });

  it('names are snake_case', () => {
    const wrong = ALL_TOOLS.map((t) => t.name).filter((n) => !/^[a-z]+(_[a-z]+)*$/.test(n));

    expect(wrong).toEqual([]);
  });
});

describe('input schemas', () => {
  it.each(ALL_TOOLS.map((tool) => [tool.name, tool] as const))(
    '%s declares a usable object schema',
    (name, tool) => {
      const schema = tool.inputSchema as any;

      expect(schema?.type, `${name}.inputSchema.type`).toBe('object');

      const properties = Object.keys(schema?.properties ?? {});

      for (const required of schema?.required ?? []) {
        expect(properties, `${name} requires "${required}" but never declares it`)
          .toContain(required);
      }
    },
  );
});

describe('parameter obtainability', () => {
  it('every required parameter has a declared source in the same mode', () => {
    const undocumented: string[] = [];

    for (const tool of ALL_TOOLS) {
      for (const required of ((tool.inputSchema as any)?.required ?? []) as string[]) {
        if (!PARAM_SOURCES[tool.name]?.[required]) {
          undocumented.push(`${tool.name}.${required}`);
        }
      }
    }

    expect(
      undocumented,
      'A required parameter with no declared source is a tool an agent cannot call. '
      + 'Either make it optional, resolve it inside the tool, or record where it comes from.',
    ).toEqual([]);
  });
});

describe('mode registration', () => {
  let context: ReturnType<typeof installFakeModelContext>;
  let enterMode: typeof import('../../resources/js/webmcp/modes')['enterMode'];
  let setSelectionTools: typeof import('../../resources/js/webmcp/modes')['setSelectionTools'];

  beforeEach(async () => {
    context = installFakeModelContext();
    const modes = await import('../../resources/js/webmcp/modes');
    enterMode = modes.enterMode;
    setSelectionTools = modes.setSelectionTools;
  });

  it.each(Object.keys(EXPECTED))('%s mode registers exactly its own tools', async (mode) => {
    await enterMode(mode as any);

    expect(context.visible().sort()).toEqual([...ALWAYS_ON, ...EXPECTED[mode]].sort());
  });

  it('switching mode replaces the set rather than adding to it', async () => {
    await enterMode('write' as any);
    expect(context.visible()).toContain('replace_block');

    await enterMode('publish' as any);

    expect(context.visible()).not.toContain('replace_block');
    expect(context.visible()).toContain('publish_page');
  });

  it('never exposes more than the visibility cap, even with a selection', async () => {
    for (const mode of Object.keys(EXPECTED)) {
      await enterMode(mode as any);
      await setSelectionTools(true);

      expect(
        context.visible().length,
        `${mode} mode with a selection exposes ${context.visible().length} tools`,
      ).toBeLessThanOrEqual(VISIBILITY_CAP);

      await setSelectionTools(false);
    }
  });

  it('selection tools appear and disappear with the selection', async () => {
    await enterMode('write' as any);
    expect(context.visible()).not.toContain('rewrite_selection');

    await setSelectionTools(true);
    expect(context.visible()).toContain('rewrite_selection');
    expect(context.visible()).toContain('explain_selection');

    await setSelectionTools(false);
    expect(context.visible()).not.toContain('rewrite_selection');
  });

  /**
   * BUG-39. The slower of two overlapping calls used to publish its own mode
   * over the winner's, leaving the tab, the shared state and the registered
   * tools describing three different things.
   */
  it('a slower mode switch cannot overwrite the one that started later', async () => {
    const { getState } = await import('../../resources/js/webmcp/state');

    const slower = enterMode('write' as any);
    const newer = enterMode('design' as any);

    await Promise.all([slower, newer]);

    expect((getState() as any).mode).toBe('design');
    expect(context.visible()).toContain('set_image_alt');
    expect(context.visible()).not.toContain('replace_block');
  });
});

describe('endpoint coverage', () => {
  it('every write endpoint is reachable from some tool', () => {
    const routes = readFileSync(resolve(ROOT, 'routes/web.php'), 'utf8');

    const toolSource = readdirSync(resolve(ROOT, 'resources/js/webmcp/tools'))
      .map((file) => readFileSync(resolve(ROOT, 'resources/js/webmcp/tools', file), 'utf8'))
      .join('\n');

    // Deliberately unreachable, with the reason recorded.
    const NOT_A_TOOL: Record<string, string> = {
      'api/demo/reset': 'the human-facing Reset button, not an agent capability',
      'p/{page}/submissions': 'the public reader form, exposed declaratively in HTML',
    };

    const missing: string[] = [];
    const pattern = /Route::(post|patch|put|delete)\(\s*'([^']+)'/g;

    for (const [, , uri] of routes.matchAll(pattern)) {
      const path = uri.replace(/^\/?(api\/)?/, '');

      if (NOT_A_TOOL[uri] || NOT_A_TOOL[`api/${path}`]) {
        continue;
      }

      // The tools build paths with template literals, so compare on the
      // literal segments rather than the whole string.
      const segments = path.split('/').filter((s) => s && !s.startsWith('{'));

      if (!segments.every((segment) => toolSource.includes(segment))) {
        missing.push(uri);
      }
    }

    expect(missing, 'A write endpoint no tool can reach is a capability the agent does not have')
      .toEqual([]);
  });
});
