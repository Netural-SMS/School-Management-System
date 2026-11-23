<?php
/**
 * Endrias Film College — student classification.
 *
 * Sunday School age group / section concepts (7_13, 14_17, 18_plus,
 * ህጻናት, ማዕከላዊያን, ወጣቶች) have been removed entirely.
 * Film college students are classified by course level / program tier
 * instead, which is handled by the classes table (class_name, class_code,
 * level_order) and program configuration in school_config.php.
 *
 * This file is retained as a compatibility shim so existing code that
 * references App\Services\StudentCategory does not fatally. All methods
 * return null/empty — callers should use class/program data instead.
 */

namespace App\Services;

final class StudentCategory
{
    // Age group / section concepts removed — no canonical groups remain.
    private const BY_LETTER = [];

    private const LABELS_AM = [];
    private const LABELS_EN = [];
    private const SECTION_META = [];
    private const SECTION_ALIASES = [];

    public static function normalizeGroup(?string $ageGroup): ?string { return null; }
    public static function letterFor(?string $ageGroup): ?string { return null; }
    public static function groupFor(string $letter): ?string { return null; }
    public static function labelsAm(): array { return []; }
    public static function labelAm(string $letter): string { return ''; }
    public static function labelEn(string $letter): string { return ''; }
    public static function letters(): array { return []; }
    public static function groups(): array { return []; }
    public static function sections(): array { return []; }
    public static function sectionAm(?string $ageGroup): ?string { return null; }
    public static function sectionEn(?string $ageGroup): ?string { return null; }
    public static function ageRangeLabel(?string $ageGroup): ?string { return null; }
    public static function groupForAge(?int $age): ?string { return null; }
    public static function ageGroupForSectionAm(?string $name): ?string { return null; }
    public static function normalizeSectionAm(?string $value): ?string { return null; }
    public static function canonicalizeSection(?string $section, ?string $ageGroup = null): string { return ''; }
    public static function sqlSectionExpr(string $tableAlias = 'm', string $column = 'current_section', string $ageGroupCol = 'age_group'): string { return "''"; }
}
