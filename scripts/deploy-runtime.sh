#!/usr/bin/env bash
# Run as the deployment administrator after the source artifact is uploaded.
set -euo pipefail
REMOTE_PATH="${1:?Project directory required}"
[[ "$REMOTE_PATH" =~ ^/[A-Za-z0-9_./-]+$ && "$REMOTE_PATH" != / ]] || exit 1
[[ "$(id -u)" == 0 ]] || { echo 'Runtime preparation requires root' >&2; exit 1; }

PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
[[ "$PHP_BIN" =~ ^/[A-Za-z0-9_./-]+$ ]] || exit 1

runuser -u www-data -- bash -euc '
  cd "$1"
  PHP_BIN="$2"
  COMPOSER_BIN="$(command -v composer)"
  umask 002
  "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
  "$PHP_BIN" artisan migrate --force
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:cache
  "$PHP_BIN" artisan view:cache
  "$PHP_BIN" artisan --version
  "$PHP_BIN" artisan queue:restart
' _ "$REMOTE_PATH" "$PHP_BIN"

# Production disables OPcache timestamp checks. A graceful FPM reload is
# required for HTTP requests to use the uploaded code and refreshed config.
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
[[ "$PHP_FPM_SERVICE" =~ ^[A-Za-z0-9_.@-]+$ ]] || exit 1
systemctl reload "$PHP_FPM_SERVICE"

# queue:restart gracefully replaces running workers. FATAL/STOPPED processes
# need an explicit Supervisor start; autorestart does not recover FATAL.
python3 - <<'PY'
import subprocess
import time


def statuses():
    result = subprocess.run(['supervisorctl', 'status'], text=True, capture_output=True)
    if result.returncode not in (0, 3):
        raise RuntimeError(result.stderr or result.stdout)
    return {row.split()[0]: row.split()[1] for row in result.stdout.splitlines()
            if row.startswith('products-backend')}


workers = statuses()
for required in ('products-backend-worker:', 'products-backend-ue-worker:',
                 'products-backend-price-refresh-worker:', 'products-backend-ue-enrichment-worker:'):
    if not any(name.startswith(required) for name in workers):
        raise RuntimeError('Missing required Supervisor group: ' + required)
for name, state in workers.items():
    if state in ('FATAL', 'STOPPED', 'EXITED'):
        result = subprocess.run(['supervisorctl', 'start', name], text=True, capture_output=True)
        # A bounded worker can finish a batch of expired jobs before startsecs.
        # Supervisor may report a spawn error while already retrying that worker.
        if result.returncode and statuses().get(name) not in ('STARTING', 'BACKOFF', 'RUNNING'):
            raise RuntimeError('Could not start ' + name + ': ' + result.stdout + result.stderr)
deadline = time.monotonic() + 30
while True:
    workers = statuses()
    if workers and all(state == 'RUNNING' for state in workers.values()):
        print(f'Confirmed {len(workers)} running products workers')
        break
    if time.monotonic() >= deadline:
        raise RuntimeError('Products workers did not recover: ' + repr(workers))
    time.sleep(1)
PY
