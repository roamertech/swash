<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            // ThemeService sanitises these again at interpolation time, because
            // presets write tokens without passing through here. This layer
            // just keeps obvious junk out of the stored token set.
            'palette.*' => ['nullable', 'string', 'max:64'],
            // Every preset sets a type_pair from the merged list. Hardcoding
            // the five base pairs here meant applying any of the ten curated
            // presets and then round-tripping the theme through this endpoint
            // failed with 422, blocking all further theme edits.
            'type_pair' => ['nullable', Rule::in(array_keys($service->typePairs()))],
            'scale' => ['nullable', 'array'],
            'mood' => ['nullable', 'string', 'max:120'],
        ]);

        Cache::put('swash.theme.previous', [
            'name' => $theme->name,
            'tokens' => $theme->tokens,
        ]);

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
            $previous = Cache::get('swash.theme.previous');

            // Accept the old tokens-only cache shape so a deploy can safely
            // revert a theme that was changed before this compatibility fix.
            if (is_array($previous) && array_key_exists('tokens', $previous)) {
                $theme->tokens = $previous['tokens'];
                $theme->name = $previous['name'] ?? $theme->name;
            } else {
                $theme->tokens = $previous;
            }

            $theme->save();

            Cache::forget('swash.theme.previous');

            return [
                'theme' => [
                    'id' => $theme->id,
                    'name' => $theme->name,
                    'tokens' => $theme->tokens,
                ],
                'css' => $service->css($theme),
                'reverted' => true,
            ];
        }

        return [
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'tokens' => $theme->tokens,
            ],
            'css' => $service->css($theme),
            'reverted' => false,
        ];
    }
}
