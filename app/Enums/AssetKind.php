<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetKind: string
{
    case Raster = 'raster';
    case Svg = 'svg';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
