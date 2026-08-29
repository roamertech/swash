<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the foreign keys, and match the revisions index to how it is queried.
 *
 * Every foreign key here was unindexed. Article::where('page_id', ...) runs on
 * essentially every editor action, and each ON DELETE CASCADE had to scan the
 * child table to find its rows. The revisions index was built on
 * (page_id, created_at) while every caller orders by id, so "get the latest
 * revision" pulled the page's whole history and sorted it in memory.
 *
 * Additive only: creating an index cannot change a query's result, and down()
 * removes exactly what up() adds.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string|array<int,string>,2:string}> */
    private array $indexes = [
        ['articles', 'page_id', 'articles_page_id_index'],
        ['articles', 'cover_asset_id', 'articles_cover_asset_id_index'],
        ['themes', 'site_id', 'themes_site_id_index'],
        ['media_assets', 'site_id', 'media_assets_site_id_index'],
        ['media_assets', 'parent_asset_id', 'media_assets_parent_asset_id_index'],
        ['blocks', 'asset_id', 'blocks_asset_id_index'],
        ['revisions', ['page_id', 'id'], 'revisions_page_id_id_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            $required = (array) $columns;

            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $required)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($required, $name): void {
                $blueprint->index($required, $name);
            });
        }
    }

    public function down(): void
    {
        // Blueprint has no dropIndexIfExists in this version, and DROP INDEX
        // on a missing index aborts the rollback. Postgres says it natively.
        foreach ($this->indexes as [, , $name]) {
            DB::statement('drop index if exists ' . $name);
        }
    }
};
