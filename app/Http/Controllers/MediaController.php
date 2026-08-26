<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Site;
use App\Services\ImageGenerationService;
use Illuminate\Http\Request;

class MediaController
{
    private function site(): Site
    {
        return Site::with('theme')->firstOrFail();
    }

    private function full(mixed $asset): array
    {
        $data = $asset instanceof MediaAsset ? $asset->attributesToArray() : (array) $asset;

        return [
            'id' => $data['id'] ?? null,
            'path' => $data['path'] ?? null,
            'alt' => $data['alt'] ?? null,
            'tags' => $data['tags'] ?? [],
            'kind' => $data['kind'] ?? null,
            'source' => $data['source'] ?? null,
        ];
    }

    private function minimal(mixed $asset): array
    {
        $data = $asset instanceof MediaAsset ? $asset->attributesToArray() : (array) $asset;

        return [
            'id' => $data['id'] ?? null,
            'path' => $data['path'] ?? null,
            'alt' => $data['alt'] ?? null,
        ];
    }

    public function index(Request $request)
    {
        $site = $this->site();

        $query = MediaAsset::query()->where('site_id', $site->id);

        if ($request->filled('q')) {
            $q = (string) $request->query('q');

            $query->where(function ($w) use ($q) {
                $w->whereRaw("tags && string_to_array(?, ',')", [$q])
                    ->orWhere('alt', 'ilike', '%' . $q . '%');
            });
        }

        $assets = $query->latest('id')->limit(40)->get();

        return [
            'assets' => $assets->map(fn ($asset) => $this->full($asset))->all(),
            'total' => $assets->count(),
        ];
    }

    public function generate(Request $request, ImageGenerationService $service)
    {
        $site = $this->site();
        $theme = $site->theme;

        abort_unless((bool) $theme, 404);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'placement' => ['required', 'in:banner,inline,thumbnail,background'],
            'transparent' => ['nullable', 'boolean'],
        ]);

        $result = $service->generate(
            $site,
            $theme,
            $data['prompt'],
            $data['placement'],
            (bool) ($data['transparent'] ?? false)
        );

        return [
            'asset' => $this->minimal($result['asset']),
            'fallback' => $result['fallback'],
        ];
    }

    public function regenerate(Request $request, MediaAsset $asset, ImageGenerationService $service)
    {
        $site = $this->site();

        abort_unless($asset->site_id === $site->id, 404);

        $data = $request->validate([
            'adjustment' => ['required', 'string', 'max:300'],
        ]);

        $result = $service->regenerate($asset, $data['adjustment']);

        return [
            'asset' => $this->minimal($result['asset']),
            'fallback' => $result['fallback'],
        ];
    }

    public function svg(Request $request, ImageGenerationService $service)
    {
        $site = $this->site();
        $theme = $site->theme;

        abort_unless((bool) $theme, 404);

        $data = $request->validate([
            'kind' => ['required', 'in:banner,icon,divider,chart'],
            'text' => ['nullable', 'string', 'max:120'],
            'palette' => ['nullable', 'in:theme,mono'],
        ]);

        $palette = $request->input('palette', 'theme') ?? 'theme';

        $asset = $service->svgGraphic(
            $site,
            $theme,
            $data['kind'],
            $data['text'] ?? null,
            $palette
        );

        return [
            'asset' => $this->minimal($asset),
        ];
    }

    public function updateAlt(Request $request, MediaAsset $asset)
    {
        $site = $this->site();

        abort_unless($asset->site_id === $site->id, 404);

        $data = $request->validate([
            'alt' => ['required', 'string', 'max:160'],
        ]);

        $asset->alt = $data['alt'];
        $asset->save();

        return [
            'asset' => $this->full($asset),
        ];
    }
}
