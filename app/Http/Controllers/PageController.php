<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Site;
use App\Services\ThemeService;

class PageController extends Controller
{
    public function home(ThemeService $themes)
    {
        $site = Site::with('theme')->firstOrFail();

        $page = Page::query()
            ->where('site_id', $site->id)
            ->where('slug', 'home')
            ->with([
                'blocks' => static function ($query): void {
                    $query->orderBy('position')->orderBy('id');
                },
                'blocks.asset',
                'article',
                'submissions',
            ])
            ->first();

        if (! $page) {
            $page = Page::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->orderBy('id')
                ->with([
                    'blocks' => static function ($query): void {
                        $query->orderBy('position')->orderBy('id');
                    },
                    'blocks.asset',
                    'article',
                    'submissions',
                ])
                ->firstOrFail();
        }

        return view('site.page', compact('site', 'page') + [
            'themeCss' => $site->theme ? $themes->css($site->theme) : '',
            'fontsUrl' => $site->theme ? $themes->googleFontsUrl($site->theme) : null,
        ]);
    }

    public function show(ThemeService $themes, Page $page)
    {
        $page->loadMissing([
            'blocks' => static function ($query): void {
                $query->orderBy('position')->orderBy('id');
            },
            'blocks.asset',
            'article',
            'submissions',
        ]);

        $site = Site::with('theme')
            ->whereKey($page->site_id)
            ->firstOrFail();

        return view('site.page', compact('site', 'page') + [
            'themeCss' => $site->theme ? $themes->css($site->theme) : '',
            'fontsUrl' => $site->theme ? $themes->googleFontsUrl($site->theme) : null,
        ]);
    }
}
