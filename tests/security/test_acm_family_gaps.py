"""Static pins for the ACM family-linking gaps (G3 -> G2 -> G1).

G3: ticket link-suggestions must query the real 069 columns
    (guardian_a/guardian_b), never the nonexistent guardian_id.
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


if __name__ == "__main__":
    unittest.main()
