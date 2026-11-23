<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

/** Нормализация значений для сравнения «объявлено» и «реально в БД». */
final class SchemaSnapshot
{
    public static function normalizeType(string $type): string
    {
        $t = strtolower(trim($type));
        $t = (string) preg_replace('/\s+/', ' ', $t);
        $t = (string) preg_replace('/\s*,\s*/', ',', $t);
        $t = (string) preg_replace('/\s*\(\s*/', '(', $t);
        $t = (string) preg_replace('/\s*\)/', ')', $t);

        # Ширина отображения целых (int(11)) не значима; tinyint(1) значим: так хранится boolean.
        return (string) preg_replace_callback(
            '/\b(tinyint|smallint|mediumint|bigint|int)\((\d+)\)/',
            static fn (array $m): string => ($m[1] === 'tinyint' && $m[2] === '1') ? $m[0] : $m[1],
            $t,
        );
    }

    public static function normalizeDefault(?string $d): ?string
    {
        if ($d === null) {
            return null;
        }

        $d = trim($d);
        $l = strtolower($d);

        if ($l === 'null' || $l === '') {
            return null;
        }
        if (preg_match('/^current_timestamp(\(\d*\))?$/', $l)) {
            return 'current_timestamp';
        }

        return $d;
    }

    /** Подпись колонки для сравнения (без комментария и подсказок). */
    public static function columnSignature(array $c): string
    {
        return json_encode([
            $c['sql'],
            (bool) $c['nullable'],
            $c['default'] ?? null,
            (bool) ($c['auto'] ?? false),
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function indexSignature(array $i): string
    {
        return $i['type'] . ':' . implode(',', $i['columns']);
    }

    public static function foreignSignature(array $f): string
    {
        return implode('|', [$f['column'], $f['on'], $f['references'], $f['onDelete'] ?? '', $f['onUpdate'] ?? '']);
    }
}
