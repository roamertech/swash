<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class PostgresArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $value = substr($value, 1, -1);
        }

        if ($value === '') {
            return [];
        }

        $result = [];
        $current = '';
        $inQuotes = false;
        $wasQuoted = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($inQuotes) {
                if ($char === '\\') {
                    $next = $value[$i + 1] ?? '';

                    if ($next === '"' || $next === '\\') {
                        $current .= $next;
                        $i++;
                        continue;
                    }

                    $current .= $char;
                    continue;
                }

                if ($char === '"') {
                    $inQuotes = false;
                    continue;
                }

                $current .= $char;
                continue;
            }

            if ($char === '"') {
                $inQuotes = true;
                $wasQuoted = true;
                continue;
            }

            if ($char === ',') {
                $result[] = $wasQuoted ? $current : trim($current);
                $current = '';
                $wasQuoted = false;
                continue;
            }

            $current .= $char;
        }

        $result[] = $wasQuoted ? $current : trim($current);

        return $result;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $value = $value ?? [];

        if (! is_array($value)) {
            $value = [];
        }

        $parts = [];

        foreach ($value as $part) {
            $part = (string) $part;

            // The bare word null, in any case, is read back by Postgres as a
            // real NULL array element rather than the string. The tag text is
            // then lost: it can never match tags && string_to_array(...), and
            // it round-trips out as an empty string. Quoting keeps it a value.
            if ($part === ''
                || strcasecmp($part, 'null') === 0
                || preg_match('/[{},"\s]/', $part) === 1
                || str_contains($part, '\\')) {
                $part = str_replace(['\\', '"'], ['\\\\', '\"'], $part);
                $parts[] = '"' . $part . '"';
                continue;
            }

            $parts[] = $part;
        }

        return '{' . implode(',', $parts) . '}';
    }
}
