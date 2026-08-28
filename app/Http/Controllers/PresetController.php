<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PresetController extends Controller
{
    public function index()
    {
        $presets = config('swash_presets.presets', []);

        $items = [];

        foreach ($presets as $slug => $preset) {
            $items[] = [
                'slug' => $slug,
                'name' => $preset['name'] ?? $slug,
                'blurb' => $preset['blurb'] ?? '',
                'palette' => $preset['tokens']['palette'] ?? [],
                'type_pair' => $preset['tokens']['type_pair'] ?? null,
                'mood' => $preset['tokens']['mood'] ?? null,
            ];
        }

        return ['presets' => $items];
    }

    public function apply(Request $request, ThemeService $svc)
    {
        $presets = config('swash_presets.presets', []);
        $slugs = array_keys($presets);

        $validator = Validator::make($request->all(), [
            'preset' => ['required', 'string', Rule::in($slugs)],
        ], [
            'preset.required' => 'A preset slug is required. Valid presets: '.implode(', ', $slugs).'.',
            'preset.in' => 'Invalid preset. Valid presets: '.implode(', ', $slugs).'.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first('preset'),
            ], 422);
        }

        $slug = $validator->validated()['preset'];
        $preset = $presets[$slug];

        $site = Site::query()->with('theme')->firstOrFail();
        $theme = $site->theme;

        abort_if(! $theme, 422, 'The site has no theme.');

        $current = $theme->tokens ?? $svc->defaults();

        Cache::forever('swash.theme.previous', [
            'name' => $theme->name,
            'tokens' => $current,
        ]);

        $theme->tokens = $svc->merge($current, $preset['tokens'] ?? []);
        $theme->name = $preset['name'] ?? $theme->name;
        $theme->save();

        return [
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'tokens' => $theme->tokens,
            ],
            'css' => $svc->css($theme),
            'applied' => $preset['name'] ?? $slug,
        ];
    }
}
