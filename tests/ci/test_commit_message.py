"""Run with python3 -B -m unittest discover -s tests/ci -v."""
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/ci/check-commit-message.sh'
TYPES = ('feature', 'fix', 'hotfix', 'refactor', 'docs', 'test',
         'chore', 'ci', 'style', 'perf', 'build', 'revert')


def check(message, *flags):
    with tempfile.NamedTemporaryFile('w', suffix='.txt') as file:
        file.write(message)
        file.flush()
        return subprocess.run([str(SCRIPT), *flags, file.name],
                              capture_output=True, text=True)


class CommitMessageTest(unittest.TestCase):
    def assertAccepted(self, message):
        result = check(message)
        self.assertEqual(result.returncode, 0, result.stderr)

    def assertRejected(self, message):
        result = check(message)
        self.assertEqual(result.returncode, 1, f'accepted {message!r}')
        self.assertIn('Example: feature/', result.stderr)

    def test_accepts_every_change_type(self):
        for change_type in TYPES:
            with self.subTest(change_type=change_type):
                self.assertAccepted(f'{change_type}/update resident report\n')

    def test_accepts_one_character_description(self):
        self.assertAccepted('fix/a\n')
        self.assertAccepted('fix/1\n')

    def test_rejects_trailing_whitespace(self):
        self.assertRejected('fix/login redirect \n')
        self.assertRejected('fix/a\t\n')

    def test_usage_errors(self):
        for args in ([], ['/missing/commit-message'], ['one', 'two']):
            result = subprocess.run([str(SCRIPT), *args], capture_output=True)
            self.assertEqual(result.returncode, 2)

    def test_checks_only_the_subject_line(self):
        self.assertAccepted('fix/handle empty barangay list\n\nLonger body. Any Case.\n')

    def test_ignores_git_comment_lines(self):
        self.assertAccepted('# Please enter the commit message\nfeature/add map legend\n')

    def test_allows_git_generated_merge_and_revert_subjects(self):
        self.assertAccepted('Merge pull request #12 from 4LPHACODER/develop\n')
        self.assertAccepted('Revert "feature/add map legend"\n')

    def test_pr_title_rejects_merge_prefix(self):
        for title in ('Merge develop', 'Merge pull request #12 from 4LPHACODER/develop'):
            with self.subTest(title=title):
                result = check(title + '\n', '--pr-title')
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn('Example: feature/', result.stderr)

    def test_pr_title_rejects_revert_prefix(self):
        result = check('Revert "feature/add map legend"\n', '--pr-title')
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn('Example: feature/', result.stderr)

    def test_pr_title_accepts_valid_title(self):
        for title in ('revert/add map legend', 'feature/add resident export', 'fix/a'):
            with self.subTest(title=title):
                result = check(title + '\n', '--pr-title')
                self.assertEqual(result.returncode, 0, result.stderr)

    def test_pr_title_enforces_existing_format_rules(self):
        for title in ('fix: login redirect', 'fix/Login redirect', 'fix/login.',
                      'fix/login ', 'fix/' + 'a' * 69, ''):
            with self.subTest(title=title):
                self.assertEqual(check(title, '--pr-title').returncode, 1)

    def test_pr_title_usage_errors(self):
        result = subprocess.run([str(SCRIPT), '--pr-title'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertIn('usage:', result.stderr)
        for flags in (('--unknown',), ('--pr-title', '--unknown'),
                      ('--pr-title', '--pr-title')):
            with self.subTest(flags=flags):
                result = check('feature/add map legend', *flags)
                self.assertEqual(result.returncode, 2)
                self.assertIn('usage:', result.stderr)

    def test_rejects_colon_style(self):
        self.assertRejected('feat: add resident export\n')
        self.assertRejected('fix: login redirect\n')

    def test_rejects_unknown_type(self):
        self.assertRejected('feat/add resident export\n')
        self.assertRejected('update/resident export\n')

    def test_rejects_missing_type(self):
        self.assertRejected('add resident export\n')

    def test_rejects_uppercase_or_blank_description_start(self):
        self.assertRejected('fix/Login redirect\n')
        self.assertRejected('fix/ login redirect\n')

    def test_rejects_trailing_period(self):
        self.assertRejected('fix/login redirect.\n')

    def test_limits_subject_to_72_characters(self):
        self.assertAccepted('fix/' + 'a' * 68 + '\n')
        self.assertRejected('fix/' + 'a' * 69 + '\n')

    def test_rejects_empty_message(self):
        self.assertRejected('')
        self.assertRejected('# only a comment\n')

@unittest.skipUnless(subprocess.run(['git', '--version'], capture_output=True).returncode == 0,
                     'git is required')
class CommitMsgHookTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI Test')
        self.git('config', 'user.email', 'ci@mapr.test')
        self.git('config', 'commit.gpgsign', 'false')
        self.git('config', 'core.hooksPath', str(ROOT / '.githooks'))

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.repo,
                              capture_output=True, text=True)

    def commit(self, message):
        return self.git('commit', '-q', '--allow-empty', '-m', message)

    def test_hook_accepts_valid_message(self):
        result = self.commit('feature/add map legend')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_hook_blocks_invalid_message(self):
        result = self.commit('feat: add map legend')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Invalid commit message', result.stderr)
        self.assertNotEqual(self.git('rev-parse', '--verify', 'HEAD').returncode, 0)

    def test_hook_accepts_git_generated_subjects(self):
        for subject in ('Merge develop', 'Revert "feature/add map legend"'):
            with self.subTest(subject=subject):
                result = self.commit(subject)
                self.assertEqual(result.returncode, 0, result.stderr)
