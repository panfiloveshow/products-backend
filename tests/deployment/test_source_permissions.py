"""Regression for a source disk exposing every file as 0700. No live SSH."""
import json
import os
import pathlib
import shutil
import stat
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]


class SourceArtifactPermissionsTest(unittest.TestCase):
    def test_existing_and_new_files_remain_readable_and_runtime_files_are_preserved(self):
        with tempfile.TemporaryDirectory(prefix="products-deploy-test-") as directory:
            root = pathlib.Path(directory)
            source, staging, target = (root / name for name in ("source", "staging", "target"))
            for path in (source, staging, target):
                path.mkdir()
            (source / "scripts").mkdir()
            shutil.copy(ROOT / "scripts/deploy-excludes.txt", source / "scripts/deploy-excludes.txt")
            for name in ("existing.php", "new.php", "artisan", ".env", "start.sh"):
                path = source / name
                path.write_text("source")
                path.chmod(0o700)
            (source / "storage").mkdir()
            (source / "storage/runtime.log").write_text("local")
            (target / "existing.php").write_text("old")
            (target / "existing.php").chmod(0o664)
            (target / ".env").write_text("server environment")
            (target / "storage").mkdir()
            (target / "storage/runtime.log").write_text("server log")

            subprocess.run(["bash", str(ROOT / "scripts/prepare-deploy-source.sh"), str(source), str(staging)], check=True)
            subprocess.run(["rsync", "-az", "--delete", "--no-owner", "--no-group", "--no-perms",
                            "--exclude-from=" + str(source / "scripts/deploy-excludes.txt"),
                            str(staging) + "/", str(target) + "/"], check=True)

            for name in ("existing.php", "new.php"):
                self.assertEqual("source", (target / name).read_text())
                self.assertTrue(stat.S_IMODE((target / name).stat().st_mode) & stat.S_IROTH)
            self.assertEqual(0o664, stat.S_IMODE((target / "existing.php").stat().st_mode))
            self.assertTrue((target / "artisan").stat().st_mode & stat.S_IXUSR)
            self.assertEqual("server environment", (target / ".env").read_text())
            self.assertEqual("server log", (target / "storage/runtime.log").read_text())

    def test_runtime_recovers_fatal_workers_and_fails_if_they_cannot_start(self):
        with tempfile.TemporaryDirectory(prefix="products-worker-test-") as directory:
            root = pathlib.Path(directory)
            commands = root / "bin"
            commands.mkdir()
            state = root / "state.json"
            fpm_reload = root / "fpm-reload.txt"
            names = [f"products-backend-{group}:worker_00" for group in (
                "worker",
                "ue-worker",
                "locality-worker",
                "autoplanning-worker",
                "price-refresh-worker",
                "supply-status-worker",
                "ue-enrichment-worker",
                "ue-enrichment-worker-extra",
            )]
            for command, content in {
                "id": "#!/bin/sh\necho 0\n",
                "runuser": "#!/bin/sh\nexit 0\n",
                "systemctl": '#!/bin/sh\nprintf "%s\\n" "$*" > "$FPM_TEST_RELOAD"\n[ -z "${FPM_TEST_FAIL:-}" ]\n',
                "supervisorctl": '''#!/usr/bin/env python3
import json, os, pathlib, sys
p = pathlib.Path(os.environ['WORKER_TEST_STATE'])
workers = json.loads(p.read_text())
if sys.argv[1] == 'status':
    if os.environ.get('WORKER_TEST_TRANSIENT'):
        workers = {name: ('RUNNING' if status == 'BACKOFF' else status) for name, status in workers.items()}
        p.write_text(json.dumps(workers))
    for name, status in workers.items(): print(name, status)
    sys.exit(0 if all(s == 'RUNNING' for s in workers.values()) else 3)
if sys.argv[1] == 'start':
    if os.environ.get('WORKER_TEST_FAIL'): sys.exit(1)
    workers[sys.argv[2]] = 'BACKOFF' if os.environ.get('WORKER_TEST_TRANSIENT') else 'RUNNING'
    p.write_text(json.dumps(workers))
    sys.exit(1 if os.environ.get('WORKER_TEST_TRANSIENT') else 0)
''',
            }.items():
                path = commands / command
                path.write_text(content)
                path.chmod(0o755)
            env = os.environ.copy()
            env.update(PATH=str(commands) + os.pathsep + env['PATH'], WORKER_TEST_STATE=str(state),
                       FPM_TEST_RELOAD=str(fpm_reload), PHP_FPM_SERVICE='php8.2-fpm')
            state.write_text(json.dumps(dict.fromkeys(names, 'FATAL')))
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('reload php8.2-fpm\n', fpm_reload.read_text())
            self.assertEqual({'RUNNING'}, set(json.loads(state.read_text()).values()))
            state.write_text(json.dumps(dict.fromkeys(names, 'FATAL')))
            env['WORKER_TEST_TRANSIENT'] = '1'
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual({'RUNNING'}, set(json.loads(state.read_text()).values()))
            env.pop('WORKER_TEST_TRANSIENT')
            state.write_text(json.dumps(dict.fromkeys(names, 'FATAL')))
            env['WORKER_TEST_FAIL'] = '1'
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            env.pop('WORKER_TEST_FAIL')
            env['FPM_TEST_FAIL'] = '1'
            state.write_text(json.dumps(dict.fromkeys(names, 'RUNNING')))
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            env.pop('FPM_TEST_FAIL')
            missing_locality = {k: 'RUNNING' for k in names if not k.startswith('products-backend-locality-worker:')}
            state.write_text(json.dumps(missing_locality))
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)

    def test_runtime_uses_explicit_php_bin_for_composer_and_artisan(self):
        with tempfile.TemporaryDirectory(prefix="products-phpbin-test-") as directory:
            root = pathlib.Path(directory)
            commands = root / "bin"
            commands.mkdir()
            state = root / "state.json"
            fpm_reload = root / "fpm-reload.txt"
            php_log = root / "php-calls.log"
            names = [f"products-backend-{group}:worker_00" for group in (
                "worker",
                "ue-worker",
                "locality-worker",
                "autoplanning-worker",
                "price-refresh-worker",
                "supply-status-worker",
                "ue-enrichment-worker",
                "ue-enrichment-worker-extra",
            )]
            for command, content in {
                "id": "#!/bin/sh\necho 0\n",
                "runuser": '#!/bin/sh\nwhile [ "$#" -gt 0 ] && [ "$1" != "--" ]; do shift; done\nshift\nexec "$@"\n',
                "php": '#!/bin/sh\necho "UNEXPECTED_BARE_PHP $*" >> "$PHP_TEST_LOG"\nexit 99\n',
                "php8.4": '#!/bin/sh\nprintf "%s\\n" "$*" >> "$PHP_TEST_LOG"\n',
                "composer": '#!/bin/sh\necho "UNEXPECTED_BARE_COMPOSER $*" >> "$PHP_TEST_LOG"\nexit 98\n',
                "systemctl": '#!/bin/sh\nprintf "%s\\n" "$*" > "$FPM_TEST_RELOAD"\n',
                "supervisorctl": '''#!/usr/bin/env python3
import json, os, pathlib, sys
p = pathlib.Path(os.environ['WORKER_TEST_STATE'])
workers = json.loads(p.read_text())
if sys.argv[1] == 'status':
    for name, status in workers.items(): print(name, status)
    sys.exit(0)
''',
            }.items():
                path = commands / command
                path.write_text(content)
                path.chmod(0o755)

            env = os.environ.copy()
            env.update(
                PATH=str(commands) + os.pathsep + env['PATH'],
                WORKER_TEST_STATE=str(state),
                FPM_TEST_RELOAD=str(fpm_reload),
                PHP_TEST_LOG=str(php_log),
                PHP_BIN=str(commands / "php8.4"),
                PHP_FPM_SERVICE="php8.4-fpm",
                PHP_BINARY="/usr/bin/php8.2",
            )
            (root / "bootstrap" / "cache").mkdir(parents=True)
            stale_routes = root / "bootstrap" / "cache" / "routes-v7.php"
            stale_routes.write_text("<?php // stale L12 routes cache\n")
            (commands / "php8.4").write_text(
                '#!/bin/sh\n'
                'printf "%s|PHP_BINARY=%s|ROUTES_EXISTS=%d\\n" "$*" "${PHP_BINARY:-}" "$(test -e bootstrap/cache/routes-v7.php && echo 1 || echo 0)" >> "$PHP_TEST_LOG"\n'
            )
            (commands / "php8.4").chmod(0o755)
            state.write_text(json.dumps(dict.fromkeys(names, 'RUNNING')))
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-runtime.sh'), str(root)],
                                    env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('reload php8.4-fpm\n', fpm_reload.read_text())
            calls = php_log.read_text().splitlines()
            composer_bin = str(commands / "composer")
            expected_php = str(commands / "php8.4")
            self.assertIn(
                f"{composer_bin} install --no-dev --optimize-autoloader --no-interaction|PHP_BINARY={expected_php}|ROUTES_EXISTS=0",
                calls,
            )
            for artisan_cmd in (
                "artisan migrate --force",
                "artisan config:cache",
                "artisan route:cache",
                "artisan view:cache",
                "artisan --version",
                "artisan queue:restart",
            ):
                self.assertIn(f"{artisan_cmd}|PHP_BINARY={expected_php}|ROUTES_EXISTS=0", calls)

    def test_repo_cron_and_supervisor_templates_pin_php84(self):
        template_paths = [
            *sorted((ROOT / "scripts" / "cron.d").glob("*")),
            *sorted((ROOT / "scripts" / "supervisor").glob("*.conf")),
            ROOT / "scripts" / "unit-economics-sync.cron",
        ]
        for path in template_paths:
            if path.name.startswith("._") or not path.is_file():
                continue
            for lineno, line in enumerate(path.read_text().splitlines(), 1):
                stripped = line.strip()
                if not stripped or stripped.startswith("#"):
                    continue
                self.assertNotRegex(
                    stripped,
                    r'(?:^|[\s&;])(?:/usr/bin/)?php\s+artisan\b|command=php\s+',
                    f"{path.relative_to(ROOT)}:{lineno} must pin /usr/bin/php8.4",
                )


if __name__ == "__main__":
    unittest.main()
