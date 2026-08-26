<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Site;
use App\Services\ThemeService;

class EditorController extends Controller
{
    public function index(ThemeService $themes)
    {
        $site = Site::with('theme')->firstOrFail();

        $pages = Page::query()
            ->where('site_id', $site->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'slug', 'kind', 'status', 'position']);

        return view('editor', compact('site', 'pages') + [
            'themeCss' => $site->theme ? $themes->css($site->theme) : '',
            'fontsUrl' => $site->theme ? $themes->googleFontsUrl($site->theme) : null,
        ]);
    }
}
