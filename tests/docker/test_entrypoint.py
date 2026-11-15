"""Run with python3 -B -m unittest discover -s tests/docker -v."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ENTRYPOINT = Path(__file__).resolve().parents[2] / 'docker/scripts/entrypoint.sh'


class StorageLinkTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'public').mkdir()
        self.link = self.root / 'public/storage'
        # Exercise link handling without root-only ownership/user switching.
        (self.root / 'bin').mkdir()
        identity = self.root / 'bin/id'
        identity.write_text('#!/bin/sh\necho 1001\n')
        identity.chmod(0o755)
        # Each test selects its own environment; ignore the caller's stack.
        self.env = {key: value for key, value in os.environ.items()
                    if key not in ('APP_ENV', 'MAPR_EXPECTED_ENV')}
        self.env['PATH'] = str(self.root / 'bin') + ':' + os.environ['PATH']

    def start(self):
        return subprocess.Popen(['sh', str(ENTRYPOINT), 'true'], cwd=self.root,
                                env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    def assert_success(self, process):
        _, error = process.communicate()
        self.assertEqual(process.returncode, 0, error.decode())

    def test_environment_mismatch_is_rejected_before_storage_changes(self):
        for expected, actual in (('local', 'production'), ('production', 'local'),
                                 ('production', '')):
            with self.subTest(expected=expected, actual=actual):
                self.env.update(MAPR_EXPECTED_ENV=expected, APP_ENV=actual)
                process = self.start()
                _, error = process.communicate()
                self.assertNotEqual(process.returncode, 0)
                self.assertIn(b'APP_ENV', error)
                self.assertFalse((self.root / 'storage').exists())
                self.assertFalse(self.link.exists())

    def test_matching_environment_allows_cli_bootstrap(self):
        for mode in ('local', 'production'):
            with self.subTest(mode=mode):
                self.env.update(MAPR_EXPECTED_ENV=mode, APP_ENV=mode)
                self.assert_success(self.start())

    def test_missing_link_resolves_to_uploads(self):
        self.assert_success(self.start())
        self.assertEqual(os.readlink(self.link), '../storage/app/public')
        self.assertEqual(self.link.resolve(), self.root / 'storage/app/public')

    def test_host_and_dangling_links_are_replaced(self):
        (self.root / 'host-uploads').mkdir()
        for target in (str(self.root / 'host-uploads'), '/missing/host/storage/app/public'):
            with self.subTest(target=target):
                self.link.symlink_to(target)
                self.assert_success(self.start())
                self.assertEqual(os.readlink(self.link), '../storage/app/public')
                self.link.unlink()

    def test_correct_link_is_untouched(self):
        self.link.symlink_to('../storage/app/public')
        inode = self.link.lstat().st_ino
        self.assert_success(self.start())
        self.assertEqual(self.link.lstat().st_ino, inode)

    def test_real_file_and_directory_are_preserved(self):
        for directory in (False, True):
            with self.subTest(directory=directory):
                if directory:
                    self.link.mkdir()
                else:
                    self.link.write_text('keep me')
                process = self.start()
                _, error = process.communicate()
                self.assertNotEqual(process.returncode, 0)
                self.assertIn(b'public/storage', error)
                self.assertFalse(self.link.is_symlink())
                if directory:
                    self.link.rmdir()
                else:
                    self.assertEqual(self.link.read_text(), 'keep me')
                    self.link.unlink()

    def test_concurrent_startup(self):
        self.link.symlink_to('/missing/host/storage/app/public')
        processes = [self.start() for _ in range(8)]
        for process in processes:
            self.assert_success(process)
        self.assertEqual(os.readlink(self.link), '../storage/app/public')
        self.assertEqual(list((self.root / 'public').glob('.storage-link.*')), [])
