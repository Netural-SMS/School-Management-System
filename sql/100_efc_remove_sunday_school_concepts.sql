-- ============================================================
-- Migration 100: Remove Sunday School concepts for Endrias Film College
-- ============================================================
-- Removes:
--   1. age_group column from members and classes tables
--   2. section column from classes table
--   3. current_section column from members table
--   4. member_type column from members table
--   5. member_type_settings table
-- ============================================================

-- ── 1. Drop member_type_settings table ──
DROP TABLE IF EXISTS `member_type_settings`;

-- ── 2. Remove age_group and current_section from members ──
SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'members'
                 AND column_name = 'age_group'),
        'ALTER TABLE `members` DROP COLUMN `age_group`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'members'
                 AND column_name = 'current_section'),
        'ALTER TABLE `members` DROP COLUMN `current_section`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'members'
                 AND column_name = 'member_type'),
        'ALTER TABLE `members` DROP COLUMN `member_type`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Remove section and age_group from classes ──
SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'classes'
                 AND column_name = 'section'),
        'ALTER TABLE `classes` DROP COLUMN `section`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'classes'
                 AND column_name = 'age_group'),
        'ALTER TABLE `classes` DROP COLUMN `age_group`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Remove member_type from any other tables that have it ──
SET @m := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'users'
                 AND column_name = 'member_type'),
        'ALTER TABLE `users` DROP COLUMN `member_type`', 'SELECT 1'
    )
);
PREPARE stmt FROM @m; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- End of migration 100
-- ============================================================
