<?php

namespace App\Http\Controllers;

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\Article;
use App\Models\Block;
use App\Models\Page;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SiteController
{
    public function show(): JsonResponse
    {
        $site = $this->site();

        return response()->json([
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'tagline' => $site->tagline,
                'nav' => $site->nav ?? [],
                'footer' => $site->footer ?? [],
            ],
            'theme' => [
                'id' => $site->theme?->id,
                'name' => $site->theme?->name,
                'tokens' => $site->theme?->tokens ?? [],
            ],
            'page_count' => $site->pages()->count(),
        ]);
    }

    public function updateNav(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nav.items' => ['required', 'array'],
            'nav.items.*.label' => ['required', 'string', 'max:60'],
            'nav.items.*.slug' => ['required', 'string', 'max:200'],
        ]);

        $site = $this->site();
        $site->nav = ['items' => $validated['nav']['items']];
        $site->save();

        return response()->json(['nav' => $site->nav]);
    }

    public function updateIdentity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:180'],
        ]);

        $site = $this->site();
        $site->name = trim($validated['name']);
        $site->tagline = isset($validated['tagline']) ? trim((string) $validated['tagline']) ?: null : null;
        $site->save();

        return response()->json([
            'name' => $site->name,
            'tagline' => $site->tagline,
        ]);
    }

    public function updateFooter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'footer' => ['required', 'array'],
            'footer.eyebrow' => ['nullable', 'string', 'max:100'],
            'footer.statement' => ['required', 'string', 'max:360'],
            'footer.contact_label' => ['nullable', 'string', 'max:60'],
            'footer.contact_email' => ['required', 'email', 'max:120'],
            'footer.copyright' => ['nullable', 'string', 'max:160'],
        ]);

        $site = $this->site();
        $footer = $validated['footer'];

        foreach (['eyebrow', 'statement', 'contact_label', 'contact_email', 'copyright'] as $key) {
            if (isset($footer[$key]) && is_string($footer[$key])) {
                $footer[$key] = trim($footer[$key]);
            }
        }

        $site->footer = $footer;
        $site->save();

        return response()->json(['footer' => $site->footer]);
    }

    public function listPages(): JsonResponse
    {
        $site = $this->site();

        $pages = Page::where('site_id', $site->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['pages' => $pages->map(fn (Page $page) => $this->pageArray($page))->all()]);
    }

    public function createPage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in($this->enumValues(PageKind::class))],
        ]);

        $site = $this->site();

        $page = new Page();
        $page->site_id = $site->id;
        $page->title = $validated['title'];
        $page->slug = $this->uniqueSlug($site->id, $validated['title']);
        $page->kind = $this->enumFrom(PageKind::class, $validated['kind']);
        $page->status = $this->enumFrom(PageStatus::class, 'draft');
        $page->position = $this->nextPagePosition($site->id);
        $page->save();

        if ($validated['kind'] === 'post') {
            $article = new Article();
            $article->page_id = $page->id;
            $article->save();
        }

        return response()->json($this->pageArray($page->refresh()), 201);
    }

    public function showPage(Page $page): JsonResponse
    {
        $blocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'kind' => $this->enumValue($page->kind),
            'status' => $this->enumValue($page->status),
            'position' => $page->position,
            'blocks' => $blocks->map(fn (Block $block) => [
                'id' => $block->id,
                'type' => $this->enumValue($block->type),
                'content' => $block->content,
                'asset_id' => $block->asset_id,
                // The editor loads a page through this endpoint, not through
                // BlockController, so the resolved path has to be here too.
                'asset_path' => $block->asset?->path,
                'position' => $block->position,
            ])->all(),
            'article' => $page->article ? [
                'seo_title' => $page->article->seo_title,
                'seo_description' => $page->article->seo_description,
                'cover_asset_id' => $page->article->cover_asset_id,
                'published_at' => $this->dateValue($page->article->published_at),
            ] : null,
        ]);
    }

    public function updatePage(Request $request, Page $page): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in($this->enumValues(PageStatus::class))],
            'kind' => ['nullable', Rule::in($this->enumValues(PageKind::class))],
        ]);

        if (array_key_exists('title', $validated) && $validated['title'] !== null) {
            $page->title = $validated['title'];
        }

        if (array_key_exists('slug', $validated) && $validated['slug'] !== null) {
            $slug = Str::slug($validated['slug']) ?: ($page->slug ?: 'page');

            if ($this->slugTaken($page->site_id, $slug, $page->id)) {
                $alternative = $this->uniqueSlug($page->site_id, $slug, $page->id);

                return response()->json([
                    'message' => "The slug '{$slug}' is already taken. A free alternative is '{$alternative}'.",
                    'alternative' => $alternative,
                ], 422);
            }

            $page->slug = $slug;
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $page->status = $this->enumFrom(PageStatus::class, $validated['status']);
        }

        $kindValue = $this->enumValue($page->kind);

        if (array_key_exists('kind', $validated) && $validated['kind'] !== null) {
            $page->kind = $this->enumFrom(PageKind::class, $validated['kind']);
            $kindValue = $validated['kind'];
        }

        $page->save();

        if ($kindValue === 'post' && ! $page->article()->exists()) {
            $article = new Article();
            $article->page_id = $page->id;
            $article->save();
        }

        return response()->json($this->pageArray($page->refresh()));
    }

    public function deletePage(Page $page): JsonResponse
    {
        // blocks, articles, submissions and revisions all carry ON DELETE
        // CASCADE, so those four statements added no scope — but they ran
        // outside a transaction, so an interruption between them left a page
        // stripped of its content while the page row itself survived. One
        // delete inside a transaction cannot produce that state.
        DB::transaction(static function () use ($page): void {
            $page->delete();
        });

        return response()->json(['deleted' => true, 'id' => $page->id]);
    }

    public function reorderPages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:pages,id'],
        ]);

        $site = $this->site();
        $result = [];

        DB::transaction(function () use ($validated, $site, &$result) {
            foreach ($validated['order'] as $position => $pageId) {
                $updated = Page::where('site_id', $site->id)
                    ->where('id', $pageId)
                    ->update(['position' => $position]);

                if ($updated > 0) {
                    $result[$pageId] = $position;
                }
            }
        });

        return response()->json(['pages' => $result]);
    }

    private function site(): Site
    {
        return Site::with('theme')->firstOrFail();
    }

    private function pageArray(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'kind' => $this->enumValue($page->kind),
            'status' => $this->enumValue($page->status),
            'position' => $page->position,
            'updated_at' => $this->dateValue($page->updated_at),
        ];
    }

    private function nextPagePosition(int $siteId): int
    {
        $max = Page::where('site_id', $siteId)->max('position');

        return $max === null ? 0 : ((int) $max + 1);
    }

    private function slugTaken(int $siteId, string $slug, ?int $ignorePageId = null): bool
    {
        return Page::where('site_id', $siteId)
            ->where('slug', $slug)
            ->when($ignorePageId, fn ($query) => $query->where('id', '!=', $ignorePageId))
            ->exists();
    }

    private function uniqueSlug(int $siteId, string $source, ?int $ignorePageId = null): string
    {
        $base = Str::slug($source) ?: 'page';
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($siteId, $slug, $ignorePageId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function enumValues(string $enum): array
    {
        $values = method_exists($enum, 'values') ? $enum::values() : $enum::cases();

        return array_values(array_map(fn ($value) => $this->enumValue($value), $values));
    }

    private function enumFrom(string $enum, mixed $value): mixed
    {
        if (method_exists($enum, 'from')) {
            return $enum::from($value);
        }

        if (method_exists($enum, 'fromValue')) {
            return $enum::fromValue($value);
        }

        return $value;
    }

    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toISOString')) {
            return $value->toISOString();
        }

        return (string) $value;
    }
}
