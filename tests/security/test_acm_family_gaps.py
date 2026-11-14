"""Static pins for the ACM family-linking gaps (G3 -> G2 -> G1).

G3: ticket link-suggestions must query the real 069 columns
    (guardian_a/guardian_b), never the nonexistent guardian_id.
G2: verification stamps only on explicit office decisions; auto paths
    stay NULL; 092 grandfathers office-created links only (zero DDL).
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AcmFamilyGapsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tickets = (ROOT / "acm/src/Accounts/AcmTicketService.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/069_guardian_linkage.sql").read_text(encoding="utf-8")
        cls.guardians = (ROOT / "admin/backend/services/GuardianService.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_guardians.php").read_text(encoding="utf-8")
        cls.migration092 = (ROOT / "sql/092_acm_guardian_link_verification.sql").read_text(encoding="utf-8")
        cls.js = (ROOT / "admin/js/family_link.js").read_text(encoding="utf-8")

    def test_suggestions_query_uses_pair_columns(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        self.assertIn("guardian_a", method)
        self.assertIn("guardian_b", method)
        self.assertIn("guardian_link_suggestions", method)

    def test_suggestions_query_has_no_bare_guardian_id_column(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        # The $guardianId parameter may stay; a guardian_id COLUMN must not appear.
        self.assertNotRegex(method, r"SELECT[^;]*\bguardian_id\b")
        self.assertNotRegex(method, r"WHERE[^;]*\bguardian_id\b")

    def test_suggestions_failure_stays_visible(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        self.assertIn("catch", method)
        self.assertIn("error_log", method)

    def test_schema_has_pair_columns_and_no_single_column(self):
        table = self.migration[self.migration.index("CREATE TABLE IF NOT EXISTS `guardian_link_suggestions`"):]
        table = table[: table.index(";", table.index("PRIMARY KEY"))]
        self.assertIn("`guardian_a`", table)
        self.assertIn("`guardian_b`", table)
        self.assertNotRegex(table, r"`guardian_id`")

    def test_link_member_defaults_to_unverified(self):
        sig = re.search(r"function linkMember\([^)]*\)", self.guardians).group(0)
        self.assertIn("bool $officeVerified = false", sig)
        # The derived path keeps the historical statement (no verified cols).
        self.assertIn(
            "ON DUPLICATE KEY UPDATE relation=VALUES(relation), contact_priority=VALUES(contact_priority), is_primary=VALUES(is_primary)",
            self.guardians,
        )

    def test_only_explicit_calls_stamp_verification(self):
        stamped = re.findall(r"linkMember\([^;]*?,\s*true,\s*true\)", self.guardians)
        # picked branch only (api link action lives in api_guardians.php).
        self.assertEqual(1, len(stamped))
        self.assertIn("linkMember($conn, $gid, $mid, $rel, $prio, $prim, true, true)", self.api)

    def test_verify_and_clear_exist_and_audit(self):
        self.assertIn("function verifyLink(", self.guardians)
        self.assertIn("function clearVerification(", self.guardians)
        self.assertIn("Guardian Link Verified", self.guardians)
        self.assertIn("Guardian Link Unverified", self.guardians)

    def test_verify_actions_use_write_gate(self):
        writes = re.search(r"\$__writeActions = \[(.*?)\];", self.api, re.S).group(1)
        self.assertIn("'verify_link'", writes)
        self.assertIn("'unverify_link'", writes)
        self.assertIn("case 'verify_link'", self.api)
        self.assertIn("case 'unverify_link'", self.api)

    def test_children_reports_verification_flag(self):
        self.assertIn("mg.verified_by IS NOT NULL AS is_verified", self.guardians)

    def test_092_grandfathers_office_links_only_and_has_zero_ddl(self):
        body = re.sub(r"--.*", "", self.migration092)
        self.assertNotRegex(body.upper(), r"\bCREATE\s+TABLE\b")
        self.assertNotRegex(body.upper(), r"\bALTER\s+TABLE\b")
        self.assertIn("verified_by = created_by", self.migration092)
        self.assertIn("verified_by IS NULL AND created_by > 0", self.migration092)

    def test_drawer_shows_verify_controls(self):
        self.assertIn("FamilyLink.verify(", self.js)
        self.assertIn("FamilyLink.unverify(", self.js)
        self.assertIn("verify: verify, unverify: unverify", self.js)
        self.assertIn("links_unverified", self.js)


if __name__ == "__main__":
    unittest.main()
