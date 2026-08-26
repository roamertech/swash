<?php

declare(strict_types=1);

namespace App\Enums;

enum PageKind: string
{
    case Page = 'page';
    case Post = 'post';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
