<?php

namespace App\Core\Settings;

/**
 * How an override row says who it applies to. A plain string column with a unique index,
 * because a nullable shop_id cannot be made unique on SQLite or on Postgres before 15.
 */
final class OverrideScope
{
    public const GLOBAL = '*';

    public static function for(?string $shopId): string
    {
        return $shopId ?? self::GLOBAL;
    }
}
