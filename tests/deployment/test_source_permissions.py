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
            names = [f"products-backend-{group}:worker_00" for group in
                     ("worker", "ue-worker", "price-refresh-worker", "ue-enrichment-worker")]
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


if __name__ == "__main__":
    unittest.main()
