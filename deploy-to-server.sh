#!/usr/bin/env bash
# Deploy the working tree through a permission-normalized source artifact.
# SSH keys are required for DEPLOY_USER and DEPLOY_ADMIN_USER.
set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-danya_user}"
DEPLOY_ADMIN_USER="${DEPLOY_ADMIN_USER:-root}"
DEPLOY_HOST="${DEPLOY_HOST:-194.87.104.42}"
REMOTE_PATH="${REMOTE_PATH:-/var/www/products-backend}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
[[ "$DEPLOY_USER" =~ ^[A-Za-z0-9_-]+$ && "$DEPLOY_ADMIN_USER" =~ ^[A-Za-z0-9_-]+$ ]] || exit 1
[[ "$DEPLOY_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || exit 1
[[ "$REMOTE_PATH" =~ ^/[A-Za-z0-9_./-]+$ && "$REMOTE_PATH" != / ]] || exit 1
[[ "$PHP_BIN" =~ ^/[A-Za-z0-9_./-]+$ ]] || exit 1
[[ "$PHP_FPM_SERVICE" =~ ^[A-Za-z0-9_.@-]+$ ]] || exit 1
HOST="${DEPLOY_USER}@${DEPLOY_HOST}"
ADMIN_HOST="${DEPLOY_ADMIN_USER}@${DEPLOY_HOST}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=10)

# Fail before uploading if the service-user smoke check and worker recovery
# cannot be performed. A successful copy is not a successful deployment.
ssh "${SSH_OPTS[@]}" "$ADMIN_HOST" \
  "test \"\$(id -u)\" = 0 && test -x '$PHP_BIN' && command -v runuser >/dev/null && command -v supervisorctl >/dev/null && command -v systemctl >/dev/null"
ssh "${SSH_OPTS[@]}" "$ADMIN_HOST" \
  "test -f '$REMOTE_PATH/artisan' && test -f '$REMOTE_PATH/composer.json'"

STAGING_DIR="$(mktemp -d "${TMPDIR:-/tmp}/products-deploy.XXXXXXXX")"
trap 'rm -rf -- "$STAGING_DIR"' EXIT
bash "$SCRIPT_DIR/scripts/prepare-deploy-source.sh" "$SCRIPT_DIR" "$STAGING_DIR"

echo "Загружаю подготовленный код в $HOST:$REMOTE_PATH"
export COPYFILE_DISABLE=1
# Existing server permissions stay intact. New files inherit safe permissions
# from the staged artifact, including when the source disk reports mode 0700.
rsync -avz --delete --no-owner --no-group --no-perms --omit-dir-times \
  --rsync-path='umask 002; exec rsync' \
  --exclude-from="$SCRIPT_DIR/scripts/deploy-excludes.txt" \
  -e 'ssh -o BatchMode=yes -o ConnectTimeout=10' \
  "$STAGING_DIR/" "${HOST}:${REMOTE_PATH}/"

ssh "${SSH_OPTS[@]}" "$ADMIN_HOST" \
  "PHP_BIN='$PHP_BIN' PHP_FPM_SERVICE='$PHP_FPM_SERVICE' bash '$REMOTE_PATH/scripts/deploy-runtime.sh' '$REMOTE_PATH'"
echo 'Готово: приложение проверено от www-data, обработчики очередей работают.'
