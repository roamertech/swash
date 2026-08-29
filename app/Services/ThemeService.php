<?php

namespace App\Services;

use App\Models\Theme;
use Illuminate\Support\Arr;

class ThemeService
{
    /**
     * Font pairings live in two places: the five built-in pairs in config/swash.php
     * and the ten curated preset pairs in config/swash_presets.php. Merging them here
     * rather than inside a config file avoids the config-load-order trap, where
     * calling config() from within a config file silently yields nothing.
     */
    /** Public so validation can allow every pair, not just the base five. */
    public function typePairs(): array
    {
        return array_merge(
            config('swash.type_pairs', []),
            config('swash_presets.type_pairs', []),
        );
    }

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
        $css .= '  --swash-bg: ' . $this->colourValue($palette['bg'] ?? null, $defaults['palette']['bg']) . ";\n";
        $css .= '  --swash-surface: ' . $this->colourValue($palette['surface'] ?? null, $defaults['palette']['surface']) . ";\n";
        $css .= '  --swash-ink: ' . $this->colourValue($palette['ink'] ?? null, $defaults['palette']['ink']) . ";\n";
        $css .= '  --swash-ink-muted: ' . $this->colourValue($palette['ink_muted'] ?? null, $defaults['palette']['ink_muted']) . ";\n";
        $css .= '  --swash-accent: ' . $this->colourValue($palette['accent'] ?? null, $defaults['palette']['accent']) . ";\n";
        $css .= '  --swash-border: ' . $this->colourValue($palette['border'] ?? null, $defaults['palette']['border']) . ";\n";
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
        $pairs = $this->arrayValue($this->typePairs());

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

    /**
     * Reject anything that could leave the stylesheet.
     *
     * css() concatenates these values into a <style> block that a Blade
     * template prints with {!! !!}. A value carrying </style>, a quote or a
     * comment opener stops being a CSS value and becomes markup. Presets
     * write tokens straight to the model, bypassing controller validation,
     * so the check has to live here, at the point of interpolation.
     */
    private function cssSafe(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        if ($value === '' || preg_match('/[<>;{}"\'\\\\]|\/\*/', $value) === 1) {
            return $fallback;
        }

        return $value;
    }

    /**
     * Colours are an allow-list, not a filter: hex, an rgb/hsl function, or a
     * bare CSS colour keyword. Anything else falls back to the default.
     */
    private function colourValue(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $candidate = trim($value);

        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $candidate) === 1) {
            return $candidate;
        }

        if (preg_match('/^(rgb|rgba|hsl|hsla)\\(\\s*[0-9a-zA-Z.,%\\s\\/]+\\)$/', $candidate) === 1) {
            return $candidate;
        }

        if (preg_match('/^[a-zA-Z]{3,24}$/', $candidate) === 1) {
            return $candidate;
        }

        return $fallback;
    }

    private function pixelValue(mixed $value): string
    {
        if (is_numeric($value)) {
            return $value . 'px';
        }

        if (is_string($value) && trim($value) !== '') {
            return $this->cssSafe($value, '0px');
        }

        return '0px';
    }

    private function rawValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return $this->cssSafe($value, '1');
        }

        return '1';
    }
}
