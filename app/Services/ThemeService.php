<?php

namespace App\Services;

use App\Models\Theme;
use Illuminate\Support\Arr;

class ThemeService
{
    public function css(Theme $theme): string
    {
        $defaults = $this->defaults();
        $tokens = $this->tokens($theme);

        $palette = array_merge(
            $defaults['palette'],
            array_filter($this->arrayValue(Arr::get($tokens, 'palette')), static fn ($value) => ! is_null($value))
        );

        $scale = array_merge(
            $defaults['scale'],
            array_filter($this->arrayValue(Arr::get($tokens, 'scale')), static fn ($value) => ! is_null($value))
        );

        $pair = $this->pair(Arr::get($tokens, 'type_pair'));

        $css = ":root {\n";
        $css .= '  --swash-bg: ' . ($palette['bg'] ?? $defaults['palette']['bg']) . ";\n";
        $css .= '  --swash-surface: ' . ($palette['surface'] ?? $defaults['palette']['surface']) . ";\n";
        $css .= '  --swash-ink: ' . ($palette['ink'] ?? $defaults['palette']['ink']) . ";\n";
        $css .= '  --swash-ink-muted: ' . ($palette['ink_muted'] ?? $defaults['palette']['ink_muted']) . ";\n";
        $css .= '  --swash-accent: ' . ($palette['accent'] ?? $defaults['palette']['accent']) . ";\n";
        $css .= '  --swash-border: ' . ($palette['border'] ?? $defaults['palette']['border']) . ";\n";
        $css .= '  --swash-base-size: ' . $this->pixelValue($scale['base_size'] ?? $defaults['scale']['base_size']) . ";\n";
        $css .= '  --swash-line-height: ' . $this->rawValue($scale['line_height'] ?? $defaults['scale']['line_height']) . ";\n";
        $css .= '  --swash-spacing: ' . $this->numberValue($scale['spacing'] ?? $defaults['scale']['spacing']) . ";\n";
        $css .= '  --swash-radius: ' . $this->pixelValue($scale['radius'] ?? $defaults['scale']['radius']) . ";\n";
        $css .= '  --swash-font-heading: ' . ($pair['heading'] ?? 'system-ui, sans-serif') . ";\n";
        $css .= '  --swash-font-body: ' . ($pair['body'] ?? 'system-ui, sans-serif') . ";\n";
        $css .= '}';

        return $css;
    }

    public function googleFontsUrl(Theme $theme): ?string
    {
        $pair = $this->pair(Arr::get($this->tokens($theme), 'type_pair'));
        $google = $pair['google'] ?? null;

        if (! is_string($google) || trim($google) === '') {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?family=' . trim($google) . '&display=swap';
    }

    public function merge(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_null($value)) {
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $current[$key] = [];
                    continue;
                }

                $current[$key] = $this->merge($this->arrayValue($current[$key] ?? null), $value);
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    public function defaults(): array
    {
        return [
            'palette' => [
                'bg' => '#ffffff',
                'surface' => '#f8fafc',
                'ink' => '#0f172a',
                'ink_muted' => '#475569',
                'accent' => '#2563eb',
                'border' => '#e2e8f0',
            ],
            'type_pair' => 'modern-sans',
            'scale' => [
                'base_size' => 16,
                'line_height' => 1.6,
                'spacing' => 1,
                'radius' => 8,
            ],
            'mood' => 'clean, modern, readable',
        ];
    }

    private function tokens(Theme $theme): array
    {
        $tokens = $theme->tokens;

        return is_array($tokens) ? $tokens : [];
    }

    private function pair(mixed $typePair): array
    {
        $pairs = $this->arrayValue(config('swash.type_pairs', []));

        if ($pairs === []) {
            return [
                'heading' => 'system-ui, sans-serif',
                'body' => 'system-ui, sans-serif',
                'google' => null,
            ];
        }

        if (is_string($typePair) && isset($pairs[$typePair]) && is_array($pairs[$typePair])) {
            return $pairs[$typePair];
        }

        $first = reset($pairs);

        return is_array($first)
            ? $first
            : [
                'heading' => 'system-ui, sans-serif',
                'body' => 'system-ui, sans-serif',
                'google' => null,
            ];
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function numberValue(mixed $value): string
    {
        // spacing is a unitless multiplier: the stylesheet uses calc(1rem * var(--swash-spacing)),
        // so emitting "8px" here would break every padding on the site.
        $n = is_numeric($value) ? (float) $value : 1.0;

        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '1';
    }

    private function pixelValue(mixed $value): string
    {
        if (is_numeric($value)) {
            return $value . 'px';
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return '0px';
    }

    private function rawValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '1';
    }
}
