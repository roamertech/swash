import type { ToolDef } from '../types';
import { api } from '../api';
import { patchState, getState } from '../state';
import { confirmWithUser, showConfirmDialog } from '../confirm';

const TYPE_PAIRS = ['editorial-serif', 'modern-sans', 'technical', 'warm-humanist', 'bold-display'] as const;
const PLACEMENTS = ['banner', 'inline', 'thumbnail', 'background'] as const;
const SVG_KINDS = ['banner', 'icon', 'divider', 'chart'] as const;

const clip = (text: string): string => {
    return text.length > 1490 ? `${text.slice(0, 1487)}...` : text;
};

const refresh = (): void => {
    patchState({});
    document.dispatchEvent(new CustomEvent('swash:refresh'));
};

const applyThemeCss = (response: any): void => {
    const style = document.querySelector<HTMLStyleElement>('#swash-theme');

    if (style && typeof response?.css === 'string') {
        style.textContent = response.css;
    }
};

const failure = (error: unknown, fallback: string): string => {
    if (typeof error === 'string' && error.trim() !== '') {
        return clip(error);
    }

    const anyError = error as any;

    if (anyError && typeof anyError === 'object') {
        const data = anyError.data ?? anyError.response?.data ?? anyError;
        const status = anyError.status ?? anyError.statusCode ?? anyError.response?.status ?? data?.status;
        const message = data?.message ?? anyError.message;

        if (status === 422) {
            const parts: string[] = [];

            if (typeof message === 'string' && message.trim() !== '') {
                parts.push(message);
            }

            const errors = data?.errors ?? anyError.errors;

            if (errors && typeof errors === 'object') {
                for (const value of Object.values(errors)) {
                    if (Array.isArray(value)) {
                        parts.push(...value.map((item: unknown) => String(item)));
                    } else if (value != null) {
                        parts.push(String(value));
                    }
                }
            }

            if (parts.length > 0) {
                return clip(parts.join('\n'));
            }
        }

        if (typeof message === 'string' && message.trim() !== '') {
            return clip(message);
        }
    }

    if (error instanceof Error && error.message.trim() !== '') {
        return clip(error.message);
    }

    return fallback;
};

const showValue = (value: unknown): string => {
    if (value === undefined || value === null) {
        return 'unset';
    }

    if (typeof value === 'string') {
        return value;
    }

    return JSON.stringify(value);
};

const isOneOf = (value: unknown, allowed: readonly string[]): boolean => {
    return typeof value === 'string' && allowed.includes(value);
};

export const designTools: ToolDef[] = [
    {
        name: 'get_theme',
        description: 'Read the current site theme tokens, including palette, typography, scale, and mood.',
        annotations: {
            readOnlyHint: true,
        },
        inputSchema: {
            type: 'object',
            properties: {},
            additionalProperties: false,
        },
        execute: async () => {
            try {
                const response = await api('/theme');
                const theme = response?.theme ?? {};
                const tokens = theme?.tokens && typeof theme.tokens === 'object' ? theme.tokens : {};
                const palette = tokens?.palette && typeof tokens.palette === 'object' ? tokens.palette : {};
                const scale = tokens?.scale && typeof tokens.scale === 'object' ? tokens.scale : {};

                return clip(
                    `Theme "${theme?.name ?? 'Unnamed'}": palette bg ${palette.bg ?? 'unset'}, ink ${palette.ink ?? 'unset'}, accent ${palette.accent ?? 'unset'}; type pair ${tokens?.type_pair ?? 'unset'}; base size ${scale.base_size ?? 'unset'}px, line height ${scale.line_height ?? 'unset'}, radius ${scale.radius ?? 'unset'}px; mood "${tokens?.mood ?? 'unset'}".`
                );
            } catch (error) {
                return failure(error, 'Failed to read the current theme.');
            }
        },
    },
    {
        name: 'set_theme',
        description:
            'Change the whole-site theme at once. Updates palette, type pairing, scale, and mood across the entire site. The mood is a short natural-language phrase such as "warm editorial" and is reused when generating images.',
        inputSchema: {
            type: 'object',
            properties: {
                palette: {
                    type: 'object',
                    properties: {
                        bg: { type: 'string' },
                        surface: { type: 'string' },
                        ink: { type: 'string' },
                        ink_muted: { type: 'string' },
                        accent: { type: 'string' },
                        border: { type: 'string' },
                    },
                    additionalProperties: false,
                },
                type_pair: {
                    type: 'string',
                    enum: [...TYPE_PAIRS],
                },
                scale: {
                    type: 'object',
                    properties: {
                        base_size: { type: 'number' },
                        line_height: { type: 'number' },
                        spacing: { type: 'number' },
                        radius: { type: 'number' },
                    },
                    additionalProperties: false,
                },
                mood: { type: 'string' },
            },
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>, client: any) => {
            try {
                if (input?.type_pair !== undefined && !isOneOf(input.type_pair, TYPE_PAIRS)) {
                    return `Unknown type pair. Use one of: ${TYPE_PAIRS.join(', ')}.`;
                }

                const current = await api('/theme');
                const currentTokens = current?.theme?.tokens && typeof current.theme.tokens === 'object'
                    ? current.theme.tokens
                    : {};

                const nextTokens = JSON.parse(JSON.stringify(currentTokens ?? {}));
                const changes: string[] = [];

                const currentPalette = currentTokens?.palette && typeof currentTokens.palette === 'object'
                    ? currentTokens.palette
                    : {};
                const paletteInput = input?.palette && typeof input.palette === 'object'
                    ? input.palette
                    : undefined;

                if (paletteInput) {
                    nextTokens.palette = { ...(nextTokens.palette ?? {}) };

                    for (const key of ['bg', 'surface', 'ink', 'ink_muted', 'accent', 'border']) {
                        const newValue = paletteInput[key];

                        if (typeof newValue !== 'string' || newValue.trim() === '') {
                            continue;
                        }

                        const oldValue = currentPalette[key];

                        if (oldValue !== newValue) {
                            changes.push(`palette.${key}: ${showValue(oldValue)} → ${newValue}`);
                            nextTokens.palette[key] = newValue;
                        }
                    }
                }

                if (input?.type_pair !== undefined) {
                    const oldValue = currentTokens?.type_pair;

                    if (oldValue !== input.type_pair) {
                        changes.push(`type_pair: ${showValue(oldValue)} → ${input.type_pair}`);
                        nextTokens.type_pair = input.type_pair;
                    }
                }

                const currentScale = currentTokens?.scale && typeof currentTokens.scale === 'object'
                    ? currentTokens.scale
                    : {};
                const scaleInput = input?.scale && typeof input.scale === 'object'
                    ? input.scale
                    : undefined;

                if (scaleInput) {
                    nextTokens.scale = { ...(nextTokens.scale ?? {}) };

                    for (const key of ['base_size', 'line_height', 'spacing', 'radius']) {
                        const newValue = scaleInput[key];

                        if (typeof newValue !== 'number' || !Number.isFinite(newValue)) {
                            continue;
                        }

                        const oldValue = currentScale[key];

                        if (oldValue !== newValue) {
                            changes.push(`scale.${key}: ${showValue(oldValue)} → ${newValue}`);
                            nextTokens.scale[key] = newValue;
                        }
                    }
                }

                if (typeof input?.mood === 'string' && input.mood.trim() !== '') {
                    const oldValue = currentTokens?.mood;
                    const newValue = input.mood.trim();

                    if (oldValue !== newValue) {
                        changes.push(`mood: ${showValue(oldValue)} → ${newValue}`);
                        nextTokens.mood = newValue;
                    }
                }

                if (changes.length === 0) {
                    return 'No theme changes were made.';
                }

                const confirmed = await confirmWithUser(client, () =>
                    showConfirmDialog({
                        title: 'Change the whole-site theme?',
                        body: 'This updates the entire site at once.',
                        detail: clip(changes.join('\n')),
                        confirmLabel: 'Apply theme',
                    })
                );

                if (!confirmed) {
                    return 'The editor declined to change the theme. Nothing was changed.';
                }

                const response = await api('/theme', {
                    method: 'PATCH',
                    body: JSON.stringify({
                        palette: nextTokens.palette,
                        type_pair: nextTokens.type_pair,
                        scale: nextTokens.scale,
                        mood: nextTokens.mood,
                    }),
                });

                applyThemeCss(response);
                refresh();

                return clip(`Theme updated. Changed:\n${changes.join('\n')}`);
            } catch (error) {
                return failure(error, 'Failed to update the theme.');
            }
        },
    },
    {
        name: 'set_type_pair',
        description:
            'Change only the site-wide type pairing. Options: editorial-serif (calm, print-like), modern-sans (clean product UI), technical (precise documentation), warm-humanist (friendly and approachable), bold-display (loud campaign headers).',
        inputSchema: {
            type: 'object',
            properties: {
                type_pair: {
                    type: 'string',
                    enum: [...TYPE_PAIRS],
                },
            },
            required: ['type_pair'],
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>) => {
            try {
                const typePair = input?.type_pair;

                if (!isOneOf(typePair, TYPE_PAIRS)) {
                    return `Unknown type pair. Use one of: ${TYPE_PAIRS.join(', ')}.`;
                }

                const response = await api('/theme', {
                    method: 'PATCH',
                    body: JSON.stringify({ type_pair: typePair }),
                });

                applyThemeCss(response);
                refresh();

                return `Type pair updated to "${typePair}".`;
            } catch (error) {
                return failure(error, 'Failed to update the type pair.');
            }
        },
    },
    {
        name: 'revert_theme',
        description: 'Revert to the previous site-wide theme.',
        inputSchema: {
            type: 'object',
            properties: {},
            additionalProperties: false,
        },
        execute: async (_input: Record<string, any>, client: any) => {
            try {
                const confirmed = await confirmWithUser(client, () =>
                    showConfirmDialog({
                        title: 'Revert the theme?',
                        body: 'This restores the previous site-wide theme.',
                        confirmLabel: 'Revert theme',
                    })
                );

                if (!confirmed) {
                    return 'The editor declined to revert the theme. Nothing was changed.';
                }

                const response = await api('/theme/revert', {
                    method: 'POST',
                });

                if (response?.reverted === false) {
                    return 'There is no earlier theme to go back to.';
                }

                applyThemeCss(response);
                refresh();

                return 'The previous theme was restored.';
            } catch (error) {
                return failure(error, 'Failed to revert the theme.');
            }
        },
    },
    {
        name: 'generate_image',
        description:
            'Generate an image for a specific page slot. The current theme palette and mood are applied automatically. The editor is asked to approve before anything is generated.',
        inputSchema: {
            type: 'object',
            properties: {
                prompt: { type: 'string' },
                placement: {
                    type: 'string',
                    enum: [...PLACEMENTS],
                },
                transparent: { type: 'boolean' },
            },
            required: ['prompt', 'placement'],
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>, client: any) => {
            try {
                const prompt = typeof input?.prompt === 'string' ? input.prompt.trim() : '';
                const placement = input?.placement;

                if (!prompt) {
                    return 'A prompt is required.';
                }

                if (!isOneOf(placement, PLACEMENTS)) {
                    return `Unknown placement. Use one of: ${PLACEMENTS.join(', ')}.`;
                }

                const page = getState().openPage as any;
                const pageName = page?.title ? ` for "${page.title}"` : '';

                const confirmed = await confirmWithUser(client, () =>
                    showConfirmDialog({
                        title: 'Generate an image?',
                        body: `A ${placement} image will be generated${pageName}. This uses your image quota.`,
                        detail: prompt,
                        confirmLabel: 'Generate',
                    })
                );

                if (!confirmed) {
                    return 'The editor declined to generate the image. Nothing was changed.';
                }

                const body: Record<string, unknown> = { prompt, placement };

                if (input?.transparent !== undefined) {
                    body.transparent = Boolean(input.transparent);
                }

                const response = await api('/media/generate', {
                    method: 'POST',
                    body: JSON.stringify(body),
                });

                refresh();

                const asset = response?.asset;

                if (!asset?.id) {
                    return 'The image request finished, but no asset was returned.';
                }

                const parts: string[] = [];

                if (typeof response?.fallback === 'string' && response.fallback.trim() !== '') {
                    parts.push(
                        `Image generation was unavailable (${response.fallback}), so a vector graphic was created instead.`
                    );
                }

                parts.push(`Created asset #${asset.id} at ${asset.path}. Use insert_image from write mode to place it.`);

                return clip(parts.join(' '));
            } catch (error) {
                return failure(error, 'Failed to generate the image.');
            }
        },
    },
    {
        name: 'regenerate_image',
        description:
            'Re-generate an existing image with an adjustment such as "darker" or "different angle". It keeps the same slot and reuses the original prompt and placement, so you do not have to repeat them.',
        inputSchema: {
            type: 'object',
            properties: {
                asset_id: { type: 'number' },
                adjustment: { type: 'string' },
            },
            required: ['asset_id', 'adjustment'],
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>, client: any) => {
            try {
                const assetId = Number(input?.asset_id);

                if (!Number.isFinite(assetId)) {
                    return 'asset_id must be a number.';
                }

                const adjustment = typeof input?.adjustment === 'string' ? input.adjustment.trim() : '';

                if (!adjustment) {
                    return 'An adjustment is required.';
                }

                const page = getState().openPage as any;
                const pageName = page?.title ? ` for "${page.title}"` : '';

                const confirmed = await confirmWithUser(client, () =>
                    showConfirmDialog({
                        title: 'Regenerate this image?',
                        body: `Image #${assetId} will be regenerated${pageName}. This uses your image quota.`,
                        detail: adjustment,
                        confirmLabel: 'Regenerate',
                    })
                );

                if (!confirmed) {
                    return 'The editor declined to regenerate the image. Nothing was changed.';
                }

                const response = await api(`/media/${assetId}/regenerate`, {
                    method: 'POST',
                    body: JSON.stringify({ adjustment }),
                });

                refresh();

                const asset = response?.asset;

                if (!asset?.id) {
                    return 'The regeneration request finished, but no asset was returned.';
                }

                const parts: string[] = [];

                if (typeof response?.fallback === 'string' && response.fallback.trim() !== '') {
                    parts.push(
                        `Image generation was unavailable (${response.fallback}), so a vector graphic was created instead.`
                    );
                }

                parts.push(`Regenerated asset #${asset.id} at ${asset.path}.`);

                return clip(parts.join(' '));
            } catch (error) {
                return failure(error, 'Failed to regenerate the image.');
            }
        },
    },
    {
        name: 'create_svg_graphic',
        description:
            'Create a vector graphic built from the site palette. No external service, no quota, always available. Best for banners, icons, dividers, and simple charts.',
        inputSchema: {
            type: 'object',
            properties: {
                kind: {
                    type: 'string',
                    enum: [...SVG_KINDS],
                },
                text: { type: 'string' },
                palette: {
                    type: 'string',
                    enum: ['theme', 'mono'],
                },
            },
            required: ['kind'],
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>) => {
            try {
                const kind = input?.kind;

                if (!isOneOf(kind, SVG_KINDS)) {
                    return `Unknown SVG kind. Use one of: ${SVG_KINDS.join(', ')}.`;
                }

                if (input?.palette !== undefined && !isOneOf(input.palette, ['theme', 'mono'])) {
                    return 'palette must be either "theme" or "mono".';
                }

                const body: Record<string, unknown> = { kind };

                if (typeof input?.text === 'string' && input.text.trim() !== '') {
                    body.text = input.text.trim();
                }

                if (input?.palette !== undefined) {
                    body.palette = input.palette;
                }

                const response = await api('/media/svg', {
                    method: 'POST',
                    body: JSON.stringify(body),
                });

                refresh();

                const asset = response?.asset;

                if (!asset?.id) {
                    return 'The SVG request finished, but no asset was returned.';
                }

                return clip(`Created SVG ${kind} asset #${asset.id} at ${asset.path}.`);
            } catch (error) {
                return failure(error, 'Failed to create the SVG graphic.');
            }
        },
    },
    {
        name: 'search_media',
        description: 'Search existing media assets by keyword.',
        annotations: {
            readOnlyHint: true,
        },
        inputSchema: {
            type: 'object',
            properties: {
                query: { type: 'string' },
            },
            additionalProperties: false,
        },
        execute: async (input: Record<string, any>) => {
            try {
                const query = typeof input?.query === 'string' ? input.query.trim() : '';
                const response = await api(`/media?q=${encodeURIComponent(query)}`);
                const assets = Array.isArray(response?.assets) ? response.assets : [];

                if (assets.length === 0) {
                    return query ? `No media matched "${query}".` : 'No media found.';
                }

                const shown = assets.slice(0, 12).map((asset: any) => {
                    const tags = Array.isArray(asset?.tags)
                        ? asset.tags.join(', ')
                        : typeof asset?.tags === 'string'
                          ? asset.tags
                          : '';

                    return `#${asset.id} ${asset.path} — ${asset.alt || 'no alt'} [${tags}]`;
                });

                const more = assets.length - shown.length;
                let output = shown.join('\n');

                if (more > 0) {
                    output += `\n+${more} more result${more === 1 ? '' : 's'}.`;
                }

                return clip(output);
            } catch (error) {
                return failure(error, 'Failed to search media.');
            }
        },
    },
];
