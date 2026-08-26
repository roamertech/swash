<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ThemeController
{
    private function site(): Site
    {
        return Site::with('theme')->firstOrFail();
    }

    public function show()
    {
        $theme = $this->site()->theme;

        return [
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'tokens' => $theme->tokens,
            ],
        ];
    }

    public function update(Request $request, ThemeService $service)
    {
        $site = $this->site();
        $theme = $site->theme;

        $data = $request->validate([
            'palette' => ['nullable', 'array'],
            'type_pair' => ['nullable', 'in:editorial-serif,modern-sans,technical,warm-humanist,bold-display'],
            'scale' => ['nullable', 'array'],
            'mood' => ['nullable', 'string', 'max:120'],
        ]);

        Cache::put('swash.theme.previous', $theme->tokens);

        $theme->tokens = $service->merge(
            $theme->tokens ?? [],
            collect($data)->filter(fn ($value) => ! is_null($value))->all()
        );

        $theme->save();

        return [
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'tokens' => $theme->tokens,
            ],
            'css' => $service->css($theme),
        ];
    }

    public function revert(ThemeService $service)
    {
        $theme = $this->site()->theme;

        if (Cache::has('swash.theme.previous')) {
            $theme->tokens = Cache::get('swash.theme.previous');
            $theme->save();

            Cache::forget('swash.theme.previous');

            return [
                'theme' => [
                    'id' => $theme->id,
                    'name' => $theme->name,
                    'tokens' => $theme->tokens,
                ],
                'reverted' => true,
            ];
        }

        return [
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'tokens' => $theme->tokens,
            ],
            'reverted' => false,
        ];
    }
}
