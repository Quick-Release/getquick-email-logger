<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Utils;

class Cursor
{
    public static function encode(string $createdAtUtc, int $id): string
    {
        return base64_encode($createdAtUtc . '|' . $id);
    }

    public static function decode(string $cursor): ?array
    {
        $decoded = base64_decode($cursor, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 2) {
            return null;
        }

        if (! ctype_digit($parts[1])) {
            return null;
        }

        return [
            'created_at_utc' => $parts[0],
            'id' => (int) $parts[1],
        ];
    }
}
