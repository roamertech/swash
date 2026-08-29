<?php

namespace App\Services;

use App\Enums\AssetKind;
use App\Enums\AssetSource;
use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\Theme;
use Illuminate\Http\Client\Response;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImageGenerationService
{
    public function __construct(protected ThemeService $themes)
    {
    }

    /**
     * Take one slot from today's paid-image budget, or explain why not.
     *
     * Two things were wrong with counting rows in media_assets. The count was
     * read and acted on without a lock, so concurrent requests all saw a
     * number under the limit and all called the paid API. And demo/reset runs
     * migrate:fresh, which drops media_assets — and the cache table too — so
     * anyone could reset the counter to zero by pressing a button and spend
     * without any cap at all.
     *
     * The counter therefore lives in the file cache, which migrate:fresh does
     * not touch, behind a lock that serialises check-and-increment. The slot
     * is taken before the API call rather than after, so a failed generation
     * still costs a slot: over-counting wastes a slot, under-counting spends
     * real money.
     *
     * Returns null when a slot was taken, or a human-readable reason when it
     * was not.
     */
    private function reserveDailySlot(): ?string
    {
        $limit = (int) config('swash.image_daily_limit', 200);

        if ($limit <= 0) {
            return null;
        }

        $store = Cache::store('file');
        $key = 'swash:images:' . now()->toDateString();
        $lock = $store->lock('swash:images:lock', 10);

        try {
            $lock->block(3);

            $used = (int) $store->get($key, 0);

            if ($used >= $limit) {
                return 'daily image limit reached';
            }

            $store->put($key, $used + 1, now()->addDay());

            return null;
        } catch (LockTimeoutException $e) {
            return 'image generation is busy right now, try again in a moment';
        } finally {
            optional($lock)->release();
        }
    }

    public function generate(Site $site, Theme $theme, string $prompt, string $placement, bool $transparent = false): array
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            $prompt = 'Abstract visual';
        }

        $key = trim((string) config('swash.openai_key'));

        if ($key === '') {
            return $this->svgFallback($site, $theme, $prompt, $placement, 'no API key configured');
        }

        $model = trim((string) ($transparent
            ? config('swash.image_model_transparent')
            : config('swash.image_model')));

        if ($model === '') {
            return $this->svgFallback($site, $theme, $prompt, $placement, 'no image model configured');
        }

        $reservation = $this->reserveDailySlot();

        if ($reservation !== null) {
            return $this->svgFallback($site, $theme, $prompt, $placement, $reservation);
        }

        $palette = $this->palette($theme);
        $tokens = $this->tokens($theme);
        $mood = (string) ($tokens['mood'] ?? 'clean, balanced, professional');

        $enrichedPrompt = sprintf(
            '%s. Visual style: %s. Colour palette: %s, %s, %s. No text, no watermarks, no logos, no signatures.',
            $prompt,
            $mood,
            $palette['accent'],
            $palette['bg'],
            $palette['ink']
        );

        $size = $this->sizeForPlacement($placement);

        $payload = [
            'model' => $model,
            'prompt' => $enrichedPrompt,
            'size' => $size,
            'n' => 1,
        ];

        if ($transparent) {
            $payload['background'] = 'transparent';
        }

        try {
            $response = Http::timeout(180)
                ->withToken($key)
                ->acceptJson()
                ->asJson()
                ->post('https://api.openai.com/v1/images/generations', $payload);

            if ($response->failed()) {
                return $this->svgFallback(
                    $site,
                    $theme,
                    $prompt,
                    $placement,
                    $this->truncate($this->responseErrorMessage($response), 120)
                );
            }

            $item = $response->json('data.0');

            if (! is_array($item)) {
                return $this->svgFallback($site, $theme, $prompt, $placement, 'unexpected image response');
            }

            $bytes = $this->imageBytesFromResponseItem($item);

            if ($bytes === null) {
                return $this->svgFallback($site, $theme, $prompt, $placement, 'unable to read generated image');
            }

            $path = $this->store($bytes, 'png');

            $asset = $this->makeAsset(
                $site,
                $path,
                'raster',
                'generated',
                $prompt,
                $placement,
                $size,
                $this->altFromPrompt($prompt),
                $this->tagsFromPrompt($prompt)
            );

            return ['asset' => $asset, 'fallback' => null];
        } catch (Throwable $e) {
            return $this->svgFallback(
                $site,
                $theme,
                $prompt,
                $placement,
                $this->truncate($e->getMessage(), 120)
            );
        }
    }

    public function regenerate(MediaAsset $asset, string $adjustment): array
    {
        $site = Site::query()->findOrFail($asset->site_id);

        $theme = $site->theme ?? null;

        if (! $theme instanceof Theme) {
            $theme = Theme::query()->first();
        }

        if (! $theme instanceof Theme) {
            $theme = new Theme();
            $theme->tokens = $this->themes->defaults();
        }

        $placement = (string) data_get($asset, 'placement.slot', 'inline');

        $prompt = trim((string) ($asset->prompt ?? ''));
        $adjustment = trim($adjustment);

        if ($adjustment !== '') {
            $prompt = trim(($prompt !== '' ? $prompt . ', ' : '') . $adjustment);
        }

        if ($prompt === '') {
            $prompt = 'Abstract visual';
        }

        $result = $this->generate($site, $theme, $prompt, $placement, false);

        $result['asset']->parent_asset_id = $asset->id;
        $result['asset']->save();

        return $result;
    }

    public function svgGraphic(Site $site, Theme $theme, string $kind, ?string $text, string $palette = 'theme'): MediaAsset
    {
        $kind = in_array($kind, ['banner', 'icon', 'divider', 'chart'], true) ? $kind : 'icon';

        $colors = $palette === 'mono'
            ? [
                'bg' => '#f9fafb',
                'surface' => '#e5e7eb',
                'ink' => '#111827',
                'ink_muted' => '#6b7280',
                'accent' => '#4b5563',
                'border' => '#d1d5db',
            ]
            : $this->palette($theme);

        $size = match ($kind) {
            'banner' => '1536x512',
            'divider' => '1200x120',
            'chart' => '1024x640',
            default => '512x512',
        };

        [$width, $height] = array_map('intval', explode('x', $size));

        $label = $text !== null && trim($text) !== '' ? trim($text) : null;
        $escaped = $label !== null ? htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8') : null;
        $font = 'font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif"';

        if ($kind === 'banner') {
            $titleY = intdiv($height, 2) + 18;
            $subtitleY = $height - 96;

            $title = $escaped !== null
                ? "<text x=\"64\" y=\"{$titleY}\" font-size=\"72\" font-weight=\"700\" fill=\"{$colors['ink']}\" {$font}>{$escaped}</text>"
                : '';

            $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="{$colors['bg']}" />
  <rect width="{$width}" height="14" fill="{$colors['accent']}" />
  <rect x="64" y="64" width="180" height="14" rx="7" fill="{$colors['accent']}" opacity="0.85" />
  <rect x="64" y="{$subtitleY}" width="260" height="14" rx="7" fill="{$colors['border']}" />
  {$title}
</svg>
SVG;
        } elseif ($kind === 'icon') {
            $centerX = intdiv($width, 2);
            $centerY = intdiv($height, 2) - 36;
            $textY = (int) round($height * 0.76);

            $title = $escaped !== null
                ? "<text x=\"50%\" y=\"{$textY}\" text-anchor=\"middle\" font-size=\"42\" font-weight=\"700\" fill=\"{$colors['ink']}\" {$font}>{$escaped}</text>"
                : '';

            $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" rx="96" fill="{$colors['surface']}" stroke="{$colors['border']}" stroke-width="8" />
  <circle cx="{$centerX}" cy="{$centerY}" r="120" fill="{$colors['accent']}" opacity="0.22" />
  <circle cx="{$centerX}" cy="{$centerY}" r="64" fill="{$colors['accent']}" />
  {$title}
</svg>
SVG;
        } elseif ($kind === 'divider') {
            $midY = intdiv($height, 2);
            $centerX = intdiv($width, 2);

            $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="{$colors['bg']}" />
  <rect x="0" y="{$midY}" width="{$width}" height="2" fill="{$colors['border']}" />
  <circle cx="{$centerX}" cy="{$midY}" r="10" fill="{$colors['accent']}" />
  <rect x="0" y="{$midY}" width="{$width}" height="2" fill="{$colors['accent']}" opacity="0.18" />
</svg>
SVG;
        } else {
            $innerX = 40;
            $innerY = 40;
            $innerWidth = $width - 80;
            $innerHeight = $height - 80;

            $chartLeft = 120;
            $chartRight = $width - 120;
            $chartTop = 120;
            $chartBottom = $height - 120;

            $axisWidth = $chartRight - $chartLeft;
            $axisHeight = $chartBottom - $chartTop;

            $bars = '';
            $barWidth = 88;
            $gap = 64;
            $x = $chartLeft + 48;
            $factors = [0.34, 0.58, 0.46, 0.76, 0.62];

            foreach ($factors as $factor) {
                $barHeight = (int) round($axisHeight * $factor);
                $y = $chartBottom - $barHeight;

                $bars .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$barWidth}\" height=\"{$barHeight}\" rx=\"10\" fill=\"{$colors['accent']}\" opacity=\"0.82\" />";

                $x += $barWidth + $gap;
            }

            $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="{$colors['bg']}" />
  <rect x="{$innerX}" y="{$innerY}" width="{$innerWidth}" height="{$innerHeight}" rx="24" fill="{$colors['surface']}" stroke="{$colors['border']}" />
  <rect x="{$chartLeft}" y="{$chartBottom}" width="{$axisWidth}" height="4" fill="{$colors['ink']}" opacity="0.35" />
  <rect x="{$chartLeft}" y="{$chartTop}" width="4" height="{$axisHeight}" fill="{$colors['ink']}" opacity="0.35" />
  {$bars}
</svg>
SVG;
        }

        $prompt = trim(ucfirst($kind) . ' graphic' . ($label !== null ? ': ' . $label : ''));
        $path = $this->store($svg, 'svg');

        return $this->makeAsset(
            $site,
            $path,
            'svg',
            'svg',
            $prompt,
            $kind,
            $size,
            $label ?? ucfirst($kind) . ' graphic',
            $this->tagsFromPrompt($prompt)
        );
    }

    private function svgFallback(Site $site, Theme $theme, string $prompt, string $placement, string $reason): array
    {
        $size = $this->sizeForPlacement($placement);
        [$width, $height] = array_map('intval', explode('x', $size));

        $palette = $this->palette($theme);
        $seed = (int) hexdec(substr(md5($prompt . '|' . $placement . '|' . $reason), 0, 8));

        $circles = '';

        $positions = [
            [0.16, 0.24],
            [0.82, 0.20],
            [0.26, 0.76],
            [0.74, 0.68],
            [0.52, 0.44],
        ];

        $radii = [0.18, 0.12, 0.20, 0.10, 0.14];
        $fills = [$palette['accent'], $palette['surface'], $palette['ink'], $palette['border'], $palette['accent']];
        $opacities = [0.16, 0.20, 0.08, 0.18, 0.12];

        foreach ($positions as $index => $position) {
            $baseCx = (int) round($width * $position[0]);
            $baseCy = (int) round($height * $position[1]);

            $jitterX = (int) hexdec(substr(md5($seed . '|cx|' . $index), 0, 8));
            $jitterY = (int) hexdec(substr(md5($seed . '|cy|' . $index), 0, 8));

            $cx = ($baseCx + $jitterX) % max(1, $width);
            $cy = ($baseCy + $jitterY) % max(1, $height);
            $r = (int) round(min($width, $height) * $radii[$index]);

            $fill = $fills[$index];
            $opacity = $opacities[$index];

            $circles .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"{$r}\" fill=\"{$fill}\" opacity=\"{$opacity}\" />";
        }

        $bandOneY = intdiv($height, 3);
        $bandTwoY = intdiv($height * 2, 3);
        $bandOneHeight = max(24, intdiv($height, 6));
        $bandTwoHeight = max(18, intdiv($height, 8));

        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <defs>
    <linearGradient id="swash-fallback" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$palette['bg']}" />
      <stop offset="55%" stop-color="{$palette['surface']}" />
      <stop offset="100%" stop-color="{$palette['accent']}" />
    </linearGradient>
  </defs>
  <rect width="{$width}" height="{$height}" fill="url(#swash-fallback)" />
  <rect y="{$bandOneY}" width="{$width}" height="{$bandOneHeight}" fill="{$palette['accent']}" opacity="0.10" />
  <rect y="{$bandTwoY}" width="{$width}" height="{$bandTwoHeight}" fill="{$palette['ink']}" opacity="0.08" />
  {$circles}
</svg>
SVG;

        $path = $this->store($svg, 'svg');

        $asset = $this->makeAsset(
            $site,
            $path,
            'svg',
            'svg',
            $prompt,
            $placement,
            $size,
            $this->altFromPrompt($prompt),
            $this->tagsFromPrompt($prompt)
        );

        return ['asset' => $asset, 'fallback' => $reason];
    }

    private function imageBytesFromResponseItem(array $item): ?string
    {
        if (! empty($item['b64_json'])) {
            $decoded = base64_decode((string) $item['b64_json']);

            return $decoded === false ? null : $decoded;
        }

        if (! empty($item['url'])) {
            try {
                $response = Http::timeout(180)->get($item['url']);

                if ($response->successful()) {
                    $body = $response->body();

                    return $body === '' ? null : $body;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function makeAsset(
        Site $site,
        string $path,
        string $kind,
        string $source,
        string $prompt,
        string $placement,
        string $size,
        ?string $alt = null,
        ?array $tags = null
    ): MediaAsset {
        $asset = new MediaAsset();

        $asset->site_id = $site->id;
        $asset->path = $path;
        $asset->kind = $this->enum(AssetKind::class, $kind);
        $asset->source = $this->enum(AssetSource::class, $source);
        $asset->prompt = $prompt;
        $asset->placement = ['slot' => $placement, 'size' => $size];
        $asset->alt = $this->altFromPrompt($alt ?? $prompt);
        $asset->tags = $tags ?? $this->tagsFromPrompt($prompt);

        $asset->save();

        return $asset;
    }

    private function enum(string $class, string $value): mixed
    {
        if (enum_exists($class)) {
            if (method_exists($class, 'tryFrom')) {
                $enum = $class::tryFrom($value);

                if ($enum !== null) {
                    return $enum;
                }
            }

            foreach ($class::cases() as $case) {
                if (strcasecmp($case->name, $value) === 0) {
                    return $case;
                }
            }
        }

        return $value;
    }

    private function store(string $contents, string $extension): string
    {
        $disk = Storage::disk('public');
        $directory = 'media';

        if (! $disk->directoryExists($directory)) {
            $disk->makeDirectory($directory);
        }

        $path = $directory . '/' . Str::uuid() . '.' . ltrim($extension, '.');
        $disk->put($path, $contents);

        return '/storage/' . $path;
    }

    private function palette(Theme $theme): array
    {
        $defaults = $this->themes->defaults();
        $tokens = $this->tokens($theme);

        return array_merge($defaults['palette'], $tokens['palette'] ?? []);
    }

    private function tokens(Theme $theme): array
    {
        $tokens = $theme->tokens;

        return is_array($tokens) ? $tokens : [];
    }

    private function sizeForPlacement(string $placement): string
    {
        return match (strtolower($placement)) {
            'banner', 'background' => '1536x1024',
            default => '1024x1024',
        };
    }

    private function altFromPrompt(string $prompt): string
    {
        $alt = $this->truncate($prompt, 120);

        return $alt === '' ? 'Generated asset' : $alt;
    }

    private function tagsFromPrompt(string $prompt): array
    {
        $text = strtolower(trim($prompt));
        $text = (string) preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopwords = [
            'with',
            'and',
            'the',
            'for',
            'from',
            'into',
            'over',
            'under',
            'background',
            'image',
            'photo',
            'illustration',
            'graphic',
            'style',
            'visual',
            'colour',
            'color',
            'palette',
            'text',
            'watermarks',
            'logos',
            'signatures',
        ];

        $tags = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            if (in_array($word, $stopwords, true)) {
                continue;
            }

            if (! in_array($word, $tags, true)) {
                $tags[] = $word;
            }

            if (count($tags) >= 6) {
                break;
            }
        }

        return $tags === [] ? ['generated'] : $tags;
    }

    private function truncate(?string $value, int $limit): string
    {
        $value = trim((string) $value);

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit)
            : substr($value, 0, $limit);
    }

    private function responseErrorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error)) {
            $message = $error['message'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = $response->body();

        return is_string($body) && $body !== '' ? $body : 'request failed';
    }
}
