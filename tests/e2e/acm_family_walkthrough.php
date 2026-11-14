<?php
/**
 * ACM family-linking gaps walkthrough against a real MariaDB.
 * ------------------------------------------------------------
 * G3: ticket link-suggestions query the real 069 pair columns.
 * (G2 verification + G1 parent-children delivery extend this file.)
 *
 * COMMAND LINE ONLY, and destructive: it seeds users, members,
 * guardians, persons, tickets and suggestions, then deletes them
 * again. Disposable database only (destructive_guard interlock).
 *
 * Usage (sandbox, migrations through 091 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_family_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins
 * the same behaviors statically
 * (tests/security/test_acm_family_gaps.py).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$envFile = $root . '/.fkss_env.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .fkss_env.php\n");
    exit(3);
}
require $envFile;
require __DIR__ . '/destructive_guard.php';
require_once $root . '/acm/autoload.php';

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_family_walkthrough');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

try {
    $pdo->query('SELECT 1 FROM acm_activation_tickets LIMIT 1');
    $pdo->query('SELECT 1 FROM guardian_link_suggestions LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "acm/suggestions tables are not present in {$dbName}\n");
    exit(3);
}

$failures = [];
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures[] = sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $label,
            json_encode($expected, JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_UNESCAPED_UNICODE)
        );
        echo "  FAIL  {$label}\n";
    } else {
        echo "  ok    {$label}\n";
    }
}

echo "== seed ==\n";
$pdo->exec("INSERT INTO users(username,full_name,role,password_hash,is_active) VALUES('fam_e2e','Fam E2E','accounts_office','x',1)");
$uid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO members(full_name_am,student_name,father_name,gender) VALUES('ሰላም ተስፋ','ሰላም','ተስፋ','female')");
$mid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO guardians(display_name,phone_primary,status) VALUES('አቶ ተስፋ','0911000001','active'),('ወሮ ሰላም','0911000002','active')");
$gidA = (int)$pdo->query("SELECT id FROM guardians WHERE phone_primary='0911000001'")->fetchColumn();
$gidB = (int)$pdo->query("SELECT id FROM guardians WHERE phone_primary='0911000002'")->fetchColumn();
$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$person = $persons->create(['full_name_am' => 'አቶ ተስፋ', 'guardian_id' => $gidA], $uid);
$ticket = $tickets->create(['person_id' => $person['id'], 'account_type' => 'parent'], $uid);
check('seeded user+member+guardians+person+ticket', true, $uid > 0 && $mid > 0 && $gidB > 0 && $ticket['id'] > 0);

echo "== G3: link suggestions ==\n";
$pdo->exec("INSERT INTO guardian_link_suggestions(guardian_a,guardian_b,member_id,score,band,status) VALUES({$gidA},{$gidB},{$mid},0.82,'review','pending'),({$gidB},{$gidA},NULL,0.95,'auto','pending')");
$got = $tickets->get((int)$ticket['id']);
check('G3 two suggestions returned', 2, count($got['suggestions']));
$cols = array_keys($got['suggestions'][0]);
check('G3 pair columns present', true, in_array('guardian_a', $cols, true) && in_array('guardian_b', $cols, true));
check('G3 no phantom column', false, in_array('guardian_id', $cols, true));
$personM = $persons->create(['full_name_am' => 'ሰላም ተስፋ', 'member_id' => $mid], $uid);
$ticketM = $tickets->create(['person_id' => $personM['id'], 'account_type' => 'member'], $uid);
$gotM = $tickets->get((int)$ticketM['id']);
check('G3 guardian-less ticket: empty', [], $gotM['suggestions']);

echo "== cleanup + residue ==\n";
try {
    $pdo->exec("DELETE FROM acm_ticket_events WHERE ticket_id IN (" . (int)$ticket['id'] . ',' . (int)$ticketM['id'] . ')');
    $pdo->exec("DELETE FROM acm_activation_tickets WHERE id IN (" . (int)$ticket['id'] . ',' . (int)$ticketM['id'] . ')');
    $pdo->exec("DELETE FROM acm_person_accounts WHERE person_id IN (" . (int)$person['id'] . ',' . (int)$personM['id'] . ')');
    $pdo->exec("DELETE FROM acm_persons WHERE id IN (" . (int)$person['id'] . ',' . (int)$personM['id'] . ')');
    $pdo->exec("DELETE FROM guardian_link_suggestions WHERE guardian_a IN ({$gidA},{$gidB}) OR guardian_b IN ({$gidA},{$gidB})");
    $pdo->exec("DELETE FROM member_guardians WHERE guardian_id IN ({$gidA},{$gidB}) OR member_id={$mid}");
    $pdo->exec("DELETE FROM guardian_aliases WHERE guardian_id IN ({$gidA},{$gidB})");
    $pdo->exec("DELETE FROM guardians WHERE id IN ({$gidA},{$gidB})");
    $pdo->exec("DELETE FROM members WHERE id={$mid}");
    $pdo->exec("DELETE FROM users WHERE id={$uid}");
    $residue = (int)$pdo->query(
        "SELECT (SELECT COUNT(*) FROM users WHERE username='fam_e2e')"
        . "+(SELECT COUNT(*) FROM members WHERE student_name='ሰላም' AND father_name='ተስፋ')"
        . "+(SELECT COUNT(*) FROM guardians WHERE phone_primary IN ('0911000001','0911000002'))"
    )->fetchColumn();
    check('residue zero', 0, $residue);
    echo "  ok    seeded rows removed\n";
} catch (Throwable $e) {
    $failures[] = 'cleanup: ' . $e->getMessage();
    echo "  FAIL  cleanup: {$e->getMessage()}\n";
}

echo "\n{$checks} checks, " . count($failures) . " failures\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }
    exit(1);
}
echo "WALKTHROUGH GREEN\n";
