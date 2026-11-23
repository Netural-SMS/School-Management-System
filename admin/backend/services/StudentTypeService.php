<?php
/**
 * Endrias Film College — membership types removed.
 *
 * Sunday School student type concepts (regular, special_regular, honorary)
 * have been removed entirely. Film college students do not use student
 * type classification.
 *
 * This file is retained as a compatibility shim so existing code that
 * references App\Services\StudentTypeService does not fatally. All methods
 * return empty/null — callers should not rely on student type data.
 */

namespace App\Services;

final class StudentTypeService
{
    public const KEYS = [];

    private const FALLBACK = [];

    public static function labels(\mysqli $conn): array { return []; }
    public static function labelAm(\mysqli $conn, string $key): string { return ''; }
    public static function labelEn(\mysqli $conn, string $key): string { return ''; }
    public static function saveLabel(\mysqli $conn, string $key, string $labelAm, string $labelEn): array
    {
        return ['status' => 'error', 'message' => 'Student types have been removed for Endrias Film College.'];
    }
}
