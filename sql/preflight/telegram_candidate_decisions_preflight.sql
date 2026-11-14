-- telegram_candidate_decisions_preflight.sql: read-only preflight for 094.
-- MariaDB 10.6+. Run BEFORE and AFTER applying 094 on each server.
-- This script creates TEMPORARY objects only; it does not change app data.
-- A BLOCK result raises SQLSTATE 45000 at the end.

DROP TEMPORARY TABLE IF EXISTS ssms_094_preflight;
CREATE TEMPORARY TABLE ssms_094_preflight (
    requirement VARCHAR(120) NOT NULL,
    actual_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK') NOT NULL
);

INSERT INTO ssms_094_preflight
SELECT 'candidate_decisions table exists',
       IF(COUNT(*) > 0, 'present', 'MISSING'),
       'present',
       IF(COUNT(*) > 0, 'PASS', 'BLOCK')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_candidate_decisions';

INSERT INTO ssms_094_preflight
SELECT 'candidate_decisions columns (id, session_id, candidate_member_id, decision, decided_at)',
       CONCAT(COUNT(*), '/5'),
       '5/5',
       IF(COUNT(*) = 5, 'PASS', 'BLOCK')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_candidate_decisions'
   AND COLUMN_NAME IN ('id','session_id','candidate_member_id','decision','decided_at');

INSERT INTO ssms_094_preflight
SELECT 'candidate_decisions indexes (PRIMARY, idx_tg_cand_session, idx_tg_cand_member)',
       CONCAT(COUNT(DISTINCT INDEX_NAME), '/3'),
       '3/3',
       IF(COUNT(DISTINCT INDEX_NAME) = 3, 'PASS', 'BLOCK')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_candidate_decisions'
   AND INDEX_NAME IN ('PRIMARY','idx_tg_cand_session','idx_tg_cand_member');

SELECT requirement, actual_value, expected_value, result
  FROM ssms_094_preflight;

-- Informational only (not gated): adjacent 091 §4/§5 state on this server.
SELECT '091§4 uniq_tg_session_decision should be gone' AS check_name,
       IF(COUNT(*) = 0, 'gone (good)', 'STILL PRESENT') AS state
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_registration_reviews'
   AND INDEX_NAME = 'uniq_tg_session_decision';
SELECT '091§5 sessions.profile_key accepts transfer' AS check_name,
       IF(COLUMN_TYPE LIKE '%transfer%', 'yes (good)', 'NO') AS state
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_registration_sessions'
   AND COLUMN_NAME = 'profile_key';

SELECT COUNT(*) INTO @ssms_094_blocked
  FROM ssms_094_preflight WHERE result = 'BLOCK';
SET @ssms_094_assert := IF(
    @ssms_094_blocked > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '094 preflight blocked: inspect BLOCK rows; apply sql/094 before this server takes bot submits.'",
    "SELECT 'PASS: telegram_candidate_decisions present (094 satisfied)' AS preflight_094"
);
PREPARE ssms_094_assert_stmt FROM @ssms_094_assert;
EXECUTE ssms_094_assert_stmt;
DEALLOCATE PREPARE ssms_094_assert_stmt;
