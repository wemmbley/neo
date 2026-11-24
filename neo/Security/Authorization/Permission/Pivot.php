<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Permission;

use Neo\Information\Persistence\Database\Connection;

/**
 * Операции над связующими таблицами (user_roles, role_permissions ...) без загрузки моделей.
 * Все операции идемпотентны: повторная выдача того же не плодит дубликатов.
 */
final class Pivot
{
    /** @param list<int|string> $ids */
    public static function attach(Connection $db, string $table, string $ownerColumn, mixed $ownerId, string $relatedColumn, array $ids): void
    {
        $existing = array_map('strval', $db->table($table)->where($ownerColumn, '=', $ownerId)->pluck($relatedColumn));

        foreach ($ids as $id) {
            if (in_array((string) $id, $existing, true)) {
                continue;
            }

            $db->table($table)->insert([$ownerColumn => $ownerId, $relatedColumn => $id]);
            $existing[] = (string) $id;
        }
    }

    /** @param list<int|string> $ids */
    public static function detach(Connection $db, string $table, string $ownerColumn, mixed $ownerId, string $relatedColumn, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $db->table($table)->where($ownerColumn, '=', $ownerId)->whereIn($relatedColumn, $ids)->delete();
    }

    /** Приводит набор связей владельца к ТОЧНОМУ списку id. @param list<int|string> $ids */
    public static function sync(Connection $db, string $table, string $ownerColumn, mixed $ownerId, string $relatedColumn, array $ids): void
    {
        $db->transaction(static function () use ($db, $table, $ownerColumn, $ownerId, $relatedColumn, $ids): void {
            $query = $db->table($table)->where($ownerColumn, '=', $ownerId);

            if ($ids !== []) {
                $query->whereNotIn($relatedColumn, $ids);
            }

            $query->delete();

            self::attach($db, $table, $ownerColumn, $ownerId, $relatedColumn, $ids);
        });
    }
}
