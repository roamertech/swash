<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetSource: string
{
    case Generated = 'generated';
    case Svg = 'svg';
    case Seed = 'seed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
