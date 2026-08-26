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

        $coverId = $page->cover_media_asset_id ?? $article?->cover_media_asset_id ?? null;

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
            if ($block->type === 'heading') {
                $headingCount++;
            }

            if ($block->type === 'paragraph') {
                $paragraphCount++;
            }

            if ($block->type === 'image') {
                $assetId = $block->media_asset_id ?? null;

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

        if ((string) $oldStatus !== (string) $page->status) {
            $changes[] = 'Status: ' . ($oldStatus ?? '') . ' → ' . ($page->status ?? '');
        }

        $currentBlocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'type', 'content', 'position'])
            ->keyBy(fn ($block) => (string) $block->id);

        $snapshotBlocks = collect($snapshot['blocks'] ?? [])
            ->keyBy(fn ($block) => (string) ($block['id'] ?? ''));

        foreach ($currentBlocks as $id => $block) {
            if ($id === '') {
                continue;
            }

            if (! $snapshotBlocks->has($id)) {
                $changes[] = 'Block #' . $block->id . ' (' . $block->type . ') added';
                continue;
            }

            $old = $snapshotBlocks->get($id);

            $edited = ($old['content'] ?? null) !== $block->content
                || ($old['type'] ?? null) !== $block->type
                || (int) ($old['position'] ?? 0) !== (int) $block->position;

            if ($edited) {
                $changes[] = 'Block #' . $block->id . ' (' . $block->type . ') edited';
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
                ->get(['id', 'type', 'content', 'position'])
                ->map(fn ($block) => [
                    'id' => $block->id,
                    'type' => $block->type,
                    'content' => $block->content,
                    'position' => $block->position,
                ])
                ->all();

            $snapshot = [
                'title' => $page->title,
                'status' => $page->status,
                'blocks' => $blocks,
            ];

            $revision = new Revision();
            $revision->page_id = $page->id;
            $revision->author = 'agent';
            $revision->snapshot = $snapshot;
            $revision->save();

            $page->status = 'published';

            if (! $page->published_at) {
                $page->published_at = now();
            }

            $page->save();

            if (($page->kind ?? null) === 'post') {
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
        $publishedAt = $page->published_at ?? $article?->published_at;

        return [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'status' => $page->status,
                'published_at' => $this->iso($publishedAt),
            ],
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
                $block->page_id = $page->id;
                $block->type = $blockData['type'] ?? 'paragraph';
                $block->content = $blockData['content'] ?? '';
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
