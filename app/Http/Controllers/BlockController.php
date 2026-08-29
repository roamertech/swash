<?php

namespace App\Http\Controllers;

use App\Enums\BlockType;
use App\Models\Block;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BlockController
{
    public function outline(Page $page): JsonResponse
    {
        $blocks = Block::where('page_id', $page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'page_id' => $page->id,
            'title' => $page->title,
            'blocks' => $blocks->map(fn (Block $block) => [
                'id' => $block->id,
                'type' => $this->enumValue($block->type),
                'position' => $block->position,
                'excerpt' => $this->excerpt($block->content),
            ])->all(),
        ]);
    }

    public function store(Request $request, Page $page): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in($this->enumValues(BlockType::class))],
            'content' => ['nullable', 'string'],
            'after_block_id' => ['nullable', 'exists:blocks,id'],
            'asset_id' => ['nullable', 'exists:media_assets,id'],
        ]);

        if (! empty($validated['after_block_id'])) {
            $afterBlock = Block::where('page_id', $page->id)
                ->where('id', $validated['after_block_id'])
                ->first();

            if (! $afterBlock) {
                return response()->json([
                    'message' => 'The after_block_id does not belong to this page.',
                ], 422);
            }

            $targetPosition = ((int) $afterBlock->position) + 1;
        } else {
            $max = Block::where('page_id', $page->id)->max('position');
            $targetPosition = $max === null ? 0 : ((int) $max + 1);
        }

        $block = DB::transaction(function () use ($page, $validated, $targetPosition) {
            $shiftBlocks = Block::where('page_id', $page->id)
                ->where('position', '>=', $targetPosition)
                ->orderByDesc('position')
                ->orderByDesc('id')
                ->get();

            foreach ($shiftBlocks as $shiftBlock) {
                $shiftBlock->position = ((int) $shiftBlock->position) + 1;
                $shiftBlock->save();
            }

            $block = new Block();
            $block->page_id = $page->id;
            $block->type = $this->enumFrom(BlockType::class, $validated['type']);
            $block->content = $validated['content'] ?? null;
            $block->asset_id = $validated['asset_id'] ?? null;
            $block->position = $targetPosition;
            $block->save();

            return $block;
        });

        return response()->json($this->blockArray($block), 201);
    }

    public function show(Block $block): JsonResponse
    {
        return response()->json($this->blockArray($block));
    }

    public function update(Request $request, Block $block): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in($this->enumValues(BlockType::class))],
            'asset_id' => ['nullable', 'exists:media_assets,id'],
        ]);

        if (array_key_exists('content', $validated)) {
            $block->content = $validated['content'];
        }

        if (array_key_exists('type', $validated) && $validated['type'] !== null) {
            $block->type = $this->enumFrom(BlockType::class, $validated['type']);
        }

        if (array_key_exists('asset_id', $validated)) {
            $block->asset_id = $validated['asset_id'];
        }

        $block->save();

        return response()->json($this->blockArray($block->refresh()));
    }

    public function destroy(Block $block): JsonResponse
    {
        $pageId = $block->page_id;
        $blockId = $block->id;

        DB::transaction(function () use ($block, $pageId) {
            $block->delete();

            $ids = Block::where('page_id', $pageId)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $this->writeBlockPositions($ids);
        });

        return response()->json(['deleted' => true, 'id' => $blockId]);
    }

    public function move(Request $request, Block $block): JsonResponse
    {
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $positions = DB::transaction(function () use ($block, $validated) {
            // The ordering snapshot used to be read outside the transaction
            // with no row locks. Two reorders landing together — a human
            // dragging while an agent calls move_block, which is the ordinary
            // case for this editor — both read the same order and one was
            // silently discarded, or the writes interleaved into duplicate
            // positions, which nothing in the schema prevents. Locking the
            // page's blocks for the duration makes the second reorder wait and
            // re-read instead.
            $ids = Block::where('page_id', $block->page_id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $ids = array_values(array_filter($ids, static fn ($id) => (int) $id !== (int) $block->id));

            $target = min((int) $validated['position'], count($ids));
            array_splice($ids, $target, 0, [$block->id]);

            return $this->writeBlockPositions($ids);
        });

        return response()->json(['blocks' => $positions]);
    }

    private function blockArray(Block $block): array
    {
        return [
            'id' => $block->id,
            'page_id' => $block->page_id,
            'type' => $this->enumValue($block->type),
            'content' => $block->content,
            'asset_id' => $block->asset_id,
            // The editor renders images too. Without the resolved path it has to
            // guess a URL, and the guess was /assets/{id}, which is not a route.
            'asset_path' => $block->asset?->path,
            'position' => $block->position,
        ];
    }

    private function excerpt(?string $content): string
    {
        $content = (string) $content;

        if (mb_strlen($content) <= 80) {
            return $content;
        }

        return mb_substr($content, 0, 80).'…';
    }

    private function writeBlockPositions(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $max = Block::whereIn('id', $ids)->max('position') ?? 0;
        $offset = ((int) $max) + count($ids) + 1000;

        foreach ($ids as $index => $id) {
            Block::whereKey($id)->update(['position' => $offset + $index]);
        }

        foreach ($ids as $index => $id) {
            Block::whereKey($id)->update(['position' => $index]);
        }

        $result = [];

        foreach ($ids as $index => $id) {
            $result[] = [
                'id' => $id,
                'position' => $index,
            ];
        }

        return $result;
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
}
