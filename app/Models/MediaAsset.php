<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PostgresArray;
use App\Enums\AssetKind;
use App\Enums\AssetSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    protected $table = 'media_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => PostgresArray::class,
            'placement' => 'array',
            'kind' => AssetKind::class,
            'source' => AssetSource::class,
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'parent_asset_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'parent_asset_id');
    }
}
