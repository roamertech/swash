<?php

declare(strict_types=1);

namespace App\Enums;

enum BlockType: string
{
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Image = 'image';
    case Quote = 'quote';
    case List_ = 'list';
    case Code = 'code';
    case Divider = 'divider';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
