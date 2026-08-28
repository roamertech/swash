<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Block;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Revision;
use App\Models\Site;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublishController
{
    private function site(): Site
    {
        return Site::with('theme')->firstOrFail();
    }

    private function iso($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return $value === null ? null : (string) $value;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function snapshot(Revision $revision): array
    {
        $snapshot = $revision->snapshot ?? $revision->data ?? [];

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true) ?? [];
        }

        return is_array($snapshot) ? $snapshot : [];
    }

    public function seo(Request $request, Page $page)
    {
        $this->site();

        $limits = [
            'seo_title' => 70,
            'seo_description' => 180,
        ];

        $labels = [
            'seo_title' => 'SEO title',
            'seo_description' => 'Meta description',
        ];

        $messages = [];

        foreach ($limits as $field => $limit) {
            $value = $request->input($field);

            if (is_string($value) && mb_strlen($value) > $limit) {
                $messages[$field . '.max'] = $labels[$field]
                    . ' must be ' . $limit
                    . ' characters or fewer; supplied length is '
                    . mb_strlen($value) . '.';
            }
        }

        $data = $request->validate([
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
        ], $messages);

        $article = Article::where('page_id', $page->id)->first();

        if (! $article) {
            $article = new Article();
            $article->page_id = $page->id;
        }

        foreach ($data as $key => $value) {
            $article->{$key} = $value;
        }

        $article->save();

        return [
            'article' => [
                'seo_title' => $article->seo_title,
                'seo_description' => $article->seo_description,
            ],
        ];
    }

    public function seoCheck(Page $page)
    {
        $this->site();

        $issues = [];

        $article = Article::where('page_id', $page->id)->first();

        $title = $article?->seo_title;
        $description = $article?->seo_description;

        if (blank($title)) {
            $issues[] = 'SEO title is missing.';
        } else {
            $length = mb_strlen((string) $title);

            if ($length > 70) {
                $issues[] = 'SEO title is ' . $length . ' characters; the limit is 70.';
            }
        }

        if (blank($description)) {
            $issues[] = 'Meta description is missing.';
        } else {
            $length = mb_strlen((string) $description);

            if ($length > 180) {
                $issues[] = 'Meta description is ' . $length . ' characters; the limit is 180.';
            }
        }

        $coverId = $article?->cover_asset_id;

        if (blank($coverId)) {
            $issues[] = 'No cover image is set.';
        }

        $blocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $headingCount = 0;
        $paragraphCount = 0;

        foreach ($blocks as $block) {
            $blockType = $this->enumValue($block->type);

            if ($blockType === 'heading') {
                $headingCount++;
            }

            if ($blockType === 'paragraph') {
                $paragraphCount++;
            }

            if ($blockType === 'image') {
                $assetId = $block->asset_id;

                if (! $assetId && is_string($block->content)) {
                    $content = trim($block->content);

                    if (is_numeric($content)) {
                        $assetId = (int) $content;
                    } else {
                        $decoded = json_decode($content, true);

                        if (is_array($decoded)) {
                            $assetId = $decoded['media_asset_id']
                                ?? $decoded['asset_id']
                                ?? $decoded['id']
                                ?? null;
                        }
                    }
                }

                if ($assetId) {
                    $asset = MediaAsset::find($assetId);

                    if ($asset && blank($asset->alt)) {
                        $issues[] = 'Image in block #' . $block->id . ' has no alt text.';
                    }
                }
            }
        }

        if ($headingCount === 0) {
            $issues[] = 'The page has no heading block.';
        }

        if ($paragraphCount === 0) {
            $issues[] = 'The page has no body text.';
        }

        return [
            'issues' => $issues,
        ];
    }

    public function linksCheck(Page $page)
    {
        $site = $this->site();

        $broken = [];
        $checked = 0;

        $blocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($blocks as $block) {
            $content = (string) $block->content;

            if ($content === '') {
                continue;
            }

            $links = [];

            if (preg_match_all(
                '/\[([^\]]*)\]\(\/p\/([^)\s]+)(?:\s[^)]*)?\)/',
                $content,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $links[] = [
                        'text' => $match[1],
                        'slug' => $match[2],
                    ];
                }
            }

            $seenHrefs = [];

            if (preg_match_all(
                '/<a[^>]*href=["\']\/p\/([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
                $content,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $slug = $match[1];
                    $text = trim(strip_tags($match[2]));

                    $links[] = [
                        'text' => $text !== '' ? $text : $slug,
                        'slug' => $slug,
                    ];

                    $seenHrefs[] = $slug;
                }
            }

            if (preg_match_all(
                '/href=["\']\/p\/([^"\']+)["\']/i',
                $content,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    if (in_array($match[1], $seenHrefs, true)) {
                        continue;
                    }

                    $links[] = [
                        'text' => $match[1],
                        'slug' => $match[1],
                    ];
                }
            }

            foreach ($links as $link) {
                $checked++;

                $slug = rawurldecode(trim($link['slug'], '/'));

                $exists = Page::where('slug', $slug)
                    ->where('site_id', $site->id)
                    ->exists();

                if (! $exists) {
                    $broken[] = [
                        'block_id' => $block->id,
                        'text' => $link['text'],
                        'slug' => $slug,
                    ];
                }
            }
        }

        return [
            'broken' => $broken,
            'checked' => $checked,
        ];
    }

    public function addLink(Request $request, Page $page)
    {
        $site = $this->site();

        $data = $request->validate([
            'target_page_id' => ['required', 'exists:pages,id'],
            'block_id' => ['required', 'exists:blocks,id'],
            'text' => ['required', 'string', 'max:200'],
        ]);

        $target = Page::where('id', $data['target_page_id'])
            ->where('site_id', $site->id)
            ->firstOrFail();

        $block = Block::where('id', $data['block_id'])
            ->where('page_id', $page->id)
            ->firstOrFail();

        $link = '[' . $data['text'] . '](/p/' . $target->slug . ')';

        $block->content = trim(trim((string) $block->content) . ' ' . $link);
        $block->save();

        return [
            'block' => [
                'id' => $block->id,
                'content' => $block->content,
            ],
        ];
    }

    public function diff(Page $page)
    {
        $this->site();

        $revision = Revision::where('page_id', $page->id)
            ->latest('id')
            ->first();

        if (! $revision) {
            return [
                'changes' => ['This page has never been published.'],
                'has_changes' => true,
            ];
        }

        $snapshot = $this->snapshot($revision);
        $changes = [];

        $oldTitle = $snapshot['title'] ?? null;

        if ((string) $oldTitle !== (string) $page->title) {
            $changes[] = sprintf(
                'Title changed from "%s" to "%s"',
                $oldTitle ?? '',
                $page->title ?? ''
            );
        }

        $oldStatus = $snapshot['status'] ?? null;

        $currentStatus = $this->enumValue($page->status);

        if ((string) $oldStatus !== (string) $currentStatus) {
            $changes[] = 'Status: ' . ($oldStatus ?? '') . ' → ' . ($currentStatus ?? '');
        }

        $currentBlocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'type', 'content', 'asset_id', 'position'])
            ->keyBy(fn ($block) => (string) $block->id);

        $snapshotBlocks = collect($snapshot['blocks'] ?? [])
            ->keyBy(fn ($block) => (string) ($block['id'] ?? ''));

        foreach ($currentBlocks as $id => $block) {
            if ($id === '') {
                continue;
            }

            if (! $snapshotBlocks->has($id)) {
                $changes[] = 'Block #' . $block->id . ' (' . $this->enumValue($block->type) . ') added';
                continue;
            }

            $old = $snapshotBlocks->get($id);
            $blockType = $this->enumValue($block->type);

            $edited = ($old['content'] ?? null) !== $block->content
                || ($old['type'] ?? null) !== $blockType
                || ($old['asset_id'] ?? null) !== $block->asset_id
                || (int) ($old['position'] ?? 0) !== (int) $block->position;

            if ($edited) {
                $changes[] = 'Block #' . $block->id . ' (' . $blockType . ') edited';
            }
        }

        foreach ($snapshotBlocks as $id => $old) {
            if ($id === '') {
                continue;
            }

            if (! $currentBlocks->has($id)) {
                $changes[] = 'Block #' . $id . ' removed';
            }
        }

        return [
            'changes' => $changes,
            'has_changes' => count($changes) > 0,
        ];
    }

    public function publish(Page $page)
    {
        $this->site();

        DB::transaction(function () use ($page) {
            $blocks = Block::where('page_id', $page->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'type', 'content', 'asset_id', 'position'])
                ->map(fn ($block) => [
                    'id' => $block->id,
                    'type' => $this->enumValue($block->type),
                    'content' => $block->content,
                    'asset_id' => $block->asset_id,
                    'position' => $block->position,
                ])
                ->all();

            $snapshot = [
                'title' => $page->title,
                // A revision represents the published state, not the draft
                // status that happened to exist one line before publishing.
                'status' => 'published',
                'blocks' => $blocks,
            ];

            $revision = new Revision();
            $revision->page_id = $page->id;
            $revision->author = 'agent';
            $revision->snapshot = $snapshot;
            $revision->save();

            $page->status = 'published';
            $page->save();

            if ($this->enumValue($page->kind) === 'post') {
                $article = Article::where('page_id', $page->id)->first();

                if (! $article) {
                    $article = new Article();
                    $article->page_id = $page->id;
                }

                if (! $article->published_at) {
                    $article->published_at = now();
                }

                $article->save();
            }
        });

        $page->refresh();

        $article = Article::where('page_id', $page->id)->first();
        $publishedAt = $article?->published_at ?? $page->updated_at;

        return [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'status' => $page->status,
                'published_at' => $this->iso($publishedAt),
            ],
        ];
    }

    /**
     * List the saved revisions of a page, newest first.
     *
     * revert_to_revision accepts a revision_id, but until this existed no
     * tool could produce one, so an agent could only ever fall back to "the
     * previous revision". Restoring a specific version was unreachable
     * through the tool layer.
     *
     * The payload stays deliberately thin: a tool response is capped at 1.5K
     * characters, so this returns a short header per revision rather than any
     * snapshot content. Use revert_to_revision to act on one.
     */
    public function revisions(Page $page)
    {
        $this->site();

        $revisions = Revision::where('page_id', $page->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return [
            'revisions' => $revisions->map(function (Revision $revision): array {
                $snapshot = $this->snapshot($revision);

                return [
                    'id' => $revision->id,
                    'author' => $revision->author,
                    'created_at' => $revision->created_at?->toIso8601String(),
                    'title' => $snapshot['title'] ?? null,
                    'blocks' => count($snapshot['blocks'] ?? []),
                ];
            })->all(),
            'total' => $revisions->count(),
        ];
    }

    public function revert(Request $request, Page $page)
    {
        $this->site();

        $data = $request->validate([
            'revision_id' => ['nullable', 'exists:revisions,id'],
        ]);

        $revision = array_key_exists('revision_id', $data) && $data['revision_id']
            ? Revision::findOrFail($data['revision_id'])
            : Revision::where('page_id', $page->id)->latest('id')->firstOrFail();

        abort_if($revision->page_id !== $page->id, 404);

        $snapshot = $this->snapshot($revision);
        $restored = 0;

        DB::transaction(function () use ($page, $snapshot, &$restored) {
            $page->title = $snapshot['title'] ?? $page->title;
            $page->status = $snapshot['status'] ?? $page->status;
            $page->save();

            Block::where('page_id', $page->id)->delete();

            foreach ($snapshot['blocks'] ?? [] as $blockData) {
                $block = new Block();
                $block->id = $blockData['id'] ?? null;
                $block->page_id = $page->id;
                $block->type = $blockData['type'] ?? 'paragraph';
                $block->content = $blockData['content'] ?? '';
                $block->asset_id = $blockData['asset_id'] ?? null;
                $block->position = $blockData['position'] ?? 0;
                $block->save();

                $restored++;
            }
        });

        $page->refresh();

        return [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'status' => $page->status,
            ],
            'restored_blocks' => $restored,
        ];
    }

    public function submissions(Page $page)
    {
        $this->site();

        $submissions = Submission::where('page_id', $page->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($submission) => [
                'id' => $submission->id,
                'submitter_name' => $submission->submitter_name,
                'body' => $submission->body,
                'created_at' => $this->iso($submission->created_at),
            ])
            ->all();

        return [
            'submissions' => $submissions,
        ];
    }

    public function storeSubmission(Request $request, Page $page)
    {
        $this->site();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'submitter_name' => ['nullable', 'string', 'max:64'],
        ]);

        $submission = new Submission();
        $submission->page_id = $page->id;
        $submission->body = $data['body'];
        $submission->submitter_name = $data['submitter_name'] ?? null;
        $submission->save();

        if (! $request->expectsJson()) {
            return redirect()->back()->with('message', 'Thanks — your note has been received.');
        }

        return [
            'submission' => [
                'id' => $submission->id,
            ],
        ];
    }
}
