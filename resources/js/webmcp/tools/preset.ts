import type { ToolDef } from '../types';
import { api } from '../api';
import { confirmWithUser, showConfirmDialog } from '../confirm';

const asRecord = (value: unknown): Record<string, any> => {
    if (value && typeof value === 'object') {
        return value as Record<string, any>;
    }

    return {};
};

const payload = (value: unknown): Record<string, any> => {
    const record = asRecord(value);

    if (record.data && typeof record.data === 'object') {
        return asRecord(record.data);
    }

    return record;
};

const confirmResult = (value: unknown): boolean => {
    if (typeof value === 'boolean') {
        return value;
    }

    const record = asRecord(value);

    if (typeof record.confirmed === 'boolean') {
        return record.confirmed;
    }

    if (typeof record.ok === 'boolean') {
        return record.ok;
    }

    return false;
};

const validationMessage = (data: Record<string, any>): string | null => {
    const generic = 'The given data was invalid.';

    if (typeof data.message === 'string' && data.message.length > 0 && data.message !== generic) {
        return data.message;
    }

    if (data.errors && typeof data.errors === 'object') {
        const messages = Object.values(data.errors)
            .flat()
            .filter((message) => typeof message === 'string' && message.length > 0);

        if (messages.length > 0) {
            return messages.join(' ');
        }
    }

    if (typeof data.message === 'string' && data.message.length > 0) {
        return data.message;
    }

    if (typeof data.error === 'string' && data.error.length > 0) {
        return data.error;
    }

    return null;
};

export const presetTools: ToolDef[] = [
    {
        name: 'list_presets',
        description: 'Lists the ten curated design presets. Each preset is a complete look: palette, type pairing, spacing and mood. Use apply_preset to switch the whole site to one.',
        inputSchema: {
            type: 'object',
            properties: {},
            required: [],
        },
        annotations: {
            readOnlyHint: true,
        },
        execute: async () => {
            const response = await api('/presets');
            const data = payload(response);
            const presets = Array.isArray(data.presets) ? data.presets : [];

            const lines = presets.map((preset: unknown) => {
                const record = asRecord(preset);
                const slug = String(record.slug ?? '');
                const name = String(record.name ?? slug);
                const blurb = String(record.blurb ?? '');

                return `${slug} — ${name}: ${blurb}`;
            });

            if (lines.length === 0) {
                return 'No presets found.';
            }

            let text = lines.join('\n');

            if (text.length > 1200) {
                text = `${text.slice(0, 1197)}...`;
            }

            return text;
        },
    },
    {
        name: 'apply_preset',
        description: 'Switches the entire site to one of the ten curated design presets in a single step. Ask list_presets first if the editor has not named one. The previous look can be restored with revert_theme.',
        inputSchema: {
            type: 'object',
            properties: {
                preset: {
                    type: 'string',
                    description: 'The preset slug, for example "editorial-noir" or "porcelain".',
                },
            },
            required: ['preset'],
        },
        annotations: {
            readOnlyHint: false,
        },
        execute: async (input: { preset?: string }, client?: any) => {
            const preset = typeof input?.preset === 'string' ? input.preset.trim() : '';

            if (!preset) {
                return 'Please provide a preset slug.';
            }

            const dialog = {
                title: 'Apply this design?',
                body: `The whole site will switch to the "${preset}" look.`,
                confirmLabel: 'Apply',
            };

            // confirmWithUser feature-detects client.requestUserInteraction itself and
            // falls back to the page modal, so the call site stays the same either way.
            const confirmed = confirmResult(
                await confirmWithUser(client, () => showConfirmDialog(dialog)),
            );

            if (!confirmed) {
                return 'Preset not applied.';
            }

            try {
                const response = await api('/presets/apply', { method: 'POST', body: { preset } });
                const data = payload(response);
                const applied = typeof data.applied === 'string' && data.applied.length > 0 ? data.applied : preset;

                if (typeof window !== 'undefined') {
                    window.dispatchEvent(new CustomEvent('swash:refresh'));
                }

                return `Applied ${applied}. The whole site now uses it. Use revert_theme to go back.`;
            } catch (error) {
                const record = asRecord(error);
                const response = asRecord(record.response);
                const status = response.status ?? record.status;
                const data = payload(response.status !== undefined ? response : record);

                if (Number(status) === 422) {
                    return validationMessage(data) ?? 'The selected preset is invalid.';
                }

                return validationMessage(data) ?? 'Could not apply the preset.';
            }
        },
    },
];
