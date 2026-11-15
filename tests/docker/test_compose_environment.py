"""Verify environment selection without starting or changing containers."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('docker'), 'Docker Compose is required')
class ComposeEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for name in ('compose.yaml', 'compose.local.yaml'):
            shutil.copy(ROOT / name, self.root / name)
        (self.root / '.env.docker').write_text('APP_ENV=local\nAPP_NAME=LocalOnly\nLOCAL_ONLY_SENTINEL=must-not-leak\n')
        (self.root / 'selected.env').write_text(
            'APP_NAME=Selected\nAPP_ENV=production\nDB_DATABASE=selected_db\n'
            'DB_USERNAME=selected_user\nDB_PASSWORD=selected-password\n'
            'MYSQL_ROOT_PASSWORD=selected-root\n')
        self.env = {key: value for key, value in os.environ.items()
                    if not key.startswith(('COMPOSE_', 'MAPR_'))}
        self.env.update(DB_DATABASE='mapr', DB_USERNAME='mapr',
                        DB_PASSWORD='test-only', MYSQL_ROOT_PASSWORD='test-root-only')

    def config(self, local=False):
        args = ['docker', 'compose', '-f', 'compose.yaml']
        if local:
            args += ['-f', 'compose.local.yaml']
        return subprocess.run(args + ['config', '--format', 'json'], cwd=self.root,
                              env=self.env, capture_output=True, text=True)

    def test_production_never_falls_back_to_local_environment(self):
        result = self.config()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('.env.docker.production', result.stderr)

    def test_local_defaults_to_local_environment(self):
        result = self.config(local=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        services = json.loads(result.stdout)['services']
        for name in ('app', 'queue'):
            self.assertEqual(services[name]['environment']['APP_NAME'], 'LocalOnly')
            self.assertEqual(services[name]['environment']['APP_ENV'], 'local')

    def test_production_defaults_to_production_environment(self):
        (self.root / '.env.docker.production').write_text('APP_ENV=production\nAPP_NAME=ProductionOnly\n')
        result = self.config()
        self.assertEqual(result.returncode, 0, result.stderr)
        for name in ('app', 'queue', 'db'):
            self.assertEqual(json.loads(result.stdout)['services'][name]['environment']['APP_NAME'], 'ProductionOnly')
            self.assertNotIn('LOCAL_ONLY_SENTINEL', json.loads(result.stdout)['services'][name]['environment'])

    def test_db_initialization_uses_selected_file_despite_conflicting_host_values(self):
        self.env['MAPR_ENV_FILE'] = 'selected.env'
        selected = dict(DB_DATABASE='selected_db', DB_USERNAME='selected_user',
                        DB_PASSWORD='selected-password', MYSQL_ROOT_PASSWORD='selected-root')
        with (self.root / 'selected.env').open('a') as stream:
            stream.write(''.join(f'{key}={value}\n' for key, value in selected.items()))
        for local in (False, True):
            with self.subTest(local=local):
                with (self.root / 'selected.env').open('a') as stream:
                    stream.write('APP_ENV=' + ('local' if local else 'production') + '\n')
                result = self.config(local=local)
                self.assertEqual(result.returncode, 0, result.stderr)
                services = json.loads(result.stdout)['services']
                db = services['db']
                self.assertEqual(db['environment']['MYSQL_ROOT_PASSWORD'], selected['MYSQL_ROOT_PASSWORD'])
                # Execute the rendered initialization command against a fake MySQL
                # entrypoint, without starting a database or exposing credentials.
                fake = self.root / 'docker-entrypoint.sh'
                fake.write_text("#!/bin/sh\n[ \"$1\" = mysqld ] || exit 2\n[ \"$2\" = --log-bin-trust-function-creators=1 ] || exit 3\npython3 -c 'import os,json; print(json.dumps(dict(os.environ)))'\n")
                fake.chmod(0o755)
                env = dict(db['environment'], PATH=str(self.root) + ':' + os.environ['PATH'])
                # Compose config escapes dollars for a subsequent Compose load.
                command = [part.replace('$$', '$') for part in db['command']]
                run = subprocess.run(command, env=env, capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, run.stderr)
                initialized = json.loads(run.stdout)
                for mysql, key in (('MYSQL_DATABASE', 'DB_DATABASE'), ('MYSQL_USER', 'DB_USERNAME'),
                                   ('MYSQL_PASSWORD', 'DB_PASSWORD')):
                    self.assertEqual(initialized[mysql], selected[key])
                    for service in ('app', 'queue'):
                        self.assertEqual(services[service]['environment'][key], selected[key])

                for required in ('DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD'):
                    invalid = dict(env, **{required: ''})
                    run = subprocess.run(command, env=invalid, capture_output=True, text=True)
                    self.assertNotEqual(run.returncode, 0)
                    self.assertIn(required, run.stderr)
                    for value in selected.values():
                        self.assertNotIn(value, run.stderr)

    def test_wrong_environment_is_rejected_by_db_before_initialization(self):
        self.env['MAPR_ENV_FILE'] = 'selected.env'
        for local, actual in ((True, 'production'), (False, 'local'), (False, '')):
            with self.subTest(local=local, actual=actual):
                (self.root / 'selected.env').write_text(
                    f'APP_ENV={actual}\nDB_DATABASE=wrong_db\nDB_USERNAME=wrong_user\n'
                    'DB_PASSWORD=wrong-password\nMYSQL_ROOT_PASSWORD=wrong-root\n')
                result = self.config(local=local)
                self.assertEqual(result.returncode, 0, result.stderr)
                services = json.loads(result.stdout)['services']
                db = services['db']
                fake = self.root / 'docker-entrypoint.sh'
                fake.write_text('#!/bin/sh\nexit 0\n')
                fake.chmod(0o755)
                env = dict(db['environment'], PATH=str(self.root) + ':' + os.environ['PATH'])
                command = [part.replace('$$', '$') for part in db['command']]
                run = subprocess.run(command, env=env, capture_output=True, text=True)
                self.assertNotEqual(run.returncode, 0)
                self.assertIn('APP_ENV', run.stderr)
                for name in ('app', 'queue'):
                    self.assertEqual(services[name]['environment']['APP_ENV'], actual)
                    self.assertEqual(services[name]['environment']['MAPR_EXPECTED_ENV'],
                                     'local' if local else 'production')

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI is required for the FPM listener probe')
    def test_web_waits_for_fpm_listener(self):
        result = self.config(local=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        services = json.loads(result.stdout)['services']
        self.assertEqual(services['web']['depends_on']['app']['condition'], 'service_healthy')
        self.assertNotIn('healthcheck', services['queue'])
        command = services['app']['healthcheck']['test']
        self.assertEqual(command[0], 'CMD')
        with socket.socket() as listener:
            try:
                listener.bind(('127.0.0.1', 9000))
            except OSError:
                self.skipTest('Port 9000 is already in use')
            listener.listen()
            self.assertEqual(subprocess.run(command[1:], capture_output=True).returncode, 0)
        self.assertNotEqual(subprocess.run(command[1:], capture_output=True).returncode, 0)

    def test_explicit_environment_applies_to_app_queue_and_db(self):
        self.env['MAPR_ENV_FILE'] = 'selected.env'
        result = self.config()
        self.assertEqual(result.returncode, 0, result.stderr)
        services = json.loads(result.stdout)['services']
        for name in ('app', 'queue', 'db'):
            with self.subTest(service=name):
                environment = services[name]['environment']
                self.assertEqual(environment['APP_NAME'], 'Selected')
                self.assertEqual(environment['APP_ENV'], 'production')
                self.assertEqual(environment['MAPR_EXPECTED_ENV'], 'production')
                self.assertEqual(environment['DB_DATABASE'], 'selected_db')
                self.assertEqual(environment['DB_USERNAME'], 'selected_user')
                self.assertEqual(environment['DB_PASSWORD'], 'selected-password')
                self.assertEqual(environment['MYSQL_ROOT_PASSWORD'], 'selected-root')
