"""Run with python3 -B -m unittest discover -s tests/ci -v."""
from pathlib import Path
import subprocess
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/ci/check-branch-flow.sh'


def check(base, head):
    return subprocess.run([str(SCRIPT), base, head], capture_output=True, text=True)


class BranchFlowTest(unittest.TestCase):
    def assertAllowed(self, base, head):
        result = check(base, head)
        self.assertEqual(result.returncode, 0, result.stderr)

    def assertBlocked(self, base, head):
        result = check(base, head)
        self.assertEqual(result.returncode, 1, f'allowed {head} -> {base}')
        self.assertIn(f"got '{head}'", result.stderr)

    def test_develop_accepts_typed_work_branches(self):
        for head in ('feature/resident-export', 'fix/login-redirect', 'hotfix/pdf-fonts', 'docs/ci-flow'):
            with self.subTest(head=head):
                self.assertAllowed('develop', head)

    def test_develop_rejects_untyped_or_malformed_branches(self):
        for head in ('feat/resident-export', 'Feature/Export', 'resident-export', 'feature/', 'main', 'testing'):
            with self.subTest(head=head):
                self.assertBlocked('develop', head)

    def test_testing_accepts_only_develop(self):
        self.assertAllowed('testing', 'develop')
        self.assertBlocked('testing', 'feature/resident-export')
        self.assertBlocked('testing', 'main')

    def test_main_accepts_only_testing(self):
        self.assertAllowed('main', 'testing')
        self.assertBlocked('main', 'develop')
        self.assertBlocked('main', 'fix/login-redirect')

    def test_other_bases_are_not_restricted(self):
        self.assertAllowed('feature/resident-export', 'fix/typo')

    def test_requires_two_arguments(self):
        self.assertEqual(subprocess.run([str(SCRIPT), 'develop'], capture_output=True).returncode, 2)
