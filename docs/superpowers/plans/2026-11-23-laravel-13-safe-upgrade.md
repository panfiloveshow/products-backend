# Laravel 13 & PHP 8.4 Safe Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Safely upgrade `products-backend` from PHP 8.2 + Laravel 12.64.0 (31 open CVEs) to PHP 8.4 + Laravel 13.x (0 CVEs) with zero downtime, zero impact on sibling apps sharing `194.87.104.42`, and instant rollback at every phase.

**Architecture:** Split the upgrade into four risk-isolated, independently deployable and reversible phases: (0) git baseline + lightweight server config/schema backup, (1) zero-breaking security patch on Laravel 12 (`12.64.0 -> 12.73+`, closing all 31 CVEs first), (2) side-by-side PHP 8.4 installation via `deb.sury.org` pinned exclusively to `products-backend` while keeping `/usr/bin/php` and `php8.2-fpm` untouched for `financial-dashboard`, `organizer-backend`, and `seo-service`, and (3) codebase hardening + Laravel 13 / Tinker 3 / PHPUnit 12 / Symfony 7.4 LTS upgrade followed by (4) controlled worker-drained production cutover.

**Tech Stack:** PHP 8.4.28 (`deb.sury.org` side-by-side), Laravel 13.x, Symfony 7.4 LTS (`config.platform.php = "8.3.0"`), PHPUnit 12.5+, PhpSpreadsheet 5.12+, ZipStream 3.1+, PostgreSQL 16, Nginx, Supervisor, Ubuntu 24.04.4 LTS.

**Spec:** User-approved target (`2026-11-23`): upgrade `products-backend` to Laravel 13 safely without breaking production or sibling services on `194.87.104.42`.

---

## Global Constraints

- **Zero impact on sibling apps on `194.87.104.42`:** `financial-dashboard`, `organizer-backend`, and `seo-service` MUST remain untouched on `php8.2-fpm` and `/usr/bin/php` (`8.2.30`). Do NOT change the global `/usr/bin/php` alternative.
- **Disk safety on `194.87.104.42`:** `/` is at `94%` (`6.7G` free of `96G`; `65G` is `/var/lib/postgresql`). Do NOT write full uncompressed DB dumps to `/`; require `>= 4G` free before `apt install` and keep config/schema backups under `50 MB`.
- **exFAT workspace constraint on macOS:** `/Volumes/SP PX10/актуальное/products-backend` is on exFAT (AppleDouble `._*` files break Composer zip flattening). Run all local `composer` and `phpunit` commands inside an APFS scratch mirror (e.g. `<scratch>/pb-test`) and copy only `composer.json`/`composer.lock` and source edits back to the workspace.
- **Symfony 7.4 LTS lock:** Set `"php": "^8.3"` and `"config.platform.php": "8.3.0"` in `composer.json` so Composer resolves Symfony `^7.4` LTS (bug fixes to Nov 2028, security to Nov 2029) instead of short-lived Symfony 8.0, while production and CI execute on **PHP 8.4** (security to Dec 2028) and local macOS PHP 8.3/8.4 both remain compatible.
- **Security baseline:** End state MUST pass `composer audit --locked --no-interaction` with **0 advisories**, keep `config/cache.php` `'serializable_classes' => false`, and preserve all explicit cache/Redis/session prefixes.
- **Independent rollback per phase:** Every production change must be reversible in under 2 minutes without database rollbacks (zero destructive schema migrations).

## Review Focus

1. **Laravel 13 `serializable_classes => false` breaks cached Eloquent objects (`OzonWarehouseCluster`):** `App\Models\OzonWarehouseCluster::findByWarehouseName()` and `getWarehousesByCluster()` currently cache an Eloquent `Model` and `Collection` in the `database` cache store; existing tests use `CACHE_STORE=array` (`serialize => false`) and miss `__PHP_Incomplete_Class`. Pinned by Task 5 (`tests/Feature/OzonWarehouseClusterCacheSerializationTest.php` on `database` cache driver).
2. **`deploy-runtime.sh` calling bare `php`/`composer` fails when `/usr/bin/php` stays PHP 8.2:** Once `composer.json` requires `php: ^8.3`, running bare `composer install` or `php artisan` via `/usr/bin/php` (`8.2`) aborts deployment. Pinned by Task 3 (`tests/deployment/test_source_permissions.py` asserting `PHP_BIN` executes both `composer` and `artisan`).
3. **Custom Artisan subclasses extending Laravel internal commands:** `App\Console\Commands\RouteListCommand`, `ScheduleRunCommand`, and `ScheduleListCommand` extend framework command internals and can break on Laravel 13 / Symfony Console signature changes. Pinned by Task 5 (`tests/Feature/ArtisanCompatibilityCommandsTest.php`).
4. **PHPUnit 12 drops docblock `@dataProvider` + PhpSpreadsheet/ZipStream 3.x XLSX export:** `tests/Unit/OzonCredentialHealthServiceTest.php:66` uses `@dataProvider` (silently skipped or fatal in PHPUnit 12), and Excel exports depend on PhpSpreadsheet 5.12 + ZipStream 3.1 under PHP 8.4. Pinned by Task 5 (`OzonCredentialHealthServiceTest` + `tests/Unit/SpreadsheetRoundtripTest.php`).
5. **Queue payload / OPcache / cron version skew during cutover:** Running Laravel 12 and Laravel 13 workers simultaneously against the `jobs` table or having `/etc/cron.d/*` invoke PHP 8.2 on Laravel 13 code causes fatal parse/unserialization errors. Pinned by Task 4 & Task 7 (explicit `/usr/bin/php8.4` audit across all 9 `/etc/cron.d/*` + `root` crontab + removal of duplicate `danya_user` cron + worker pause/drain around cutover).

---

## File Structure Map

**Modified in repo:**
- `composer.json`, `composer.lock` — Phase 1 security updates; Phase 3 Laravel 13 / Tinker 3 / PHPUnit 12 / ZipStream 3.1 / `platform.php: 8.3.0`.
- `deploy-to-server.sh` — Add `PHP_BIN` (`default /usr/bin/php8.4` in Phase 2) and switch `PHP_FPM_SERVICE` default from `php8.2-fpm` to `php8.4-fpm`.
- `scripts/deploy-runtime.sh` — Execute Composer and Artisan via explicit `"$PHP_BIN"`.
- `tests/deployment/test_source_permissions.py` — Verify `PHP_BIN` and `PHP_FPM_SERVICE` behavior in `deploy-runtime.sh`.
- `scripts/supervisor/{products-price-refresh,products-supply-statuses,products-ue-enrichment}.conf` — Pin `command=/usr/bin/php8.4 ...`.
- `scripts/cron.d/{integrations-dedup,ue-price-integrity,wb-refresh-tariffs,wb-stock-warehouses}` — Pin `/usr/bin/php8.4`.
- `app/Models/OzonWarehouseCluster.php` — Cache plain attribute arrays and rehydrate via `newFromBuilder()` / `hydrate()`.
- `config/cache.php` — Explicitly declare `'serializable_classes' => false`.
- `config/sanctum.php` — Update `ValidateCsrfToken::class` to `PreventRequestForgery::class`.
- `tests/Unit/OzonCredentialHealthServiceTest.php` — Migrate `@dataProvider` docblock to `#[DataProvider('authRejectionStatuses')]`.
- `.github/workflows/ci.yml` — Bump `php-version: '8.4'` on both jobs and register new regression tests in `critical-tests`.

**Created in repo:**
- `tests/Feature/OzonWarehouseClusterCacheSerializationTest.php` — Verifies serializing `database` cache store roundtrip with zero `O:` objects in `cache` table.
- `tests/Feature/ArtisanCompatibilityCommandsTest.php` — Verifies `route:list --columns=...`, `schedule:list --columns=...`, `schedule:run --columns=... --whisper`.
- `tests/Unit/SpreadsheetRoundtripTest.php` — Verifies `PhpSpreadsheet\Writer\Xlsx` + `Reader\Xlsx` roundtrip under PHP 8.4 + ZipStream 3.1.

**Modified on production server `194.87.104.42` (Phase 2 only, isolated to `products-backend`):**
- `/etc/php/8.4/fpm/pool.d/www.conf`, `/etc/php/8.4/fpm/php.ini`, `/etc/php/8.4/fpm/conf.d/10-opcache.ini`
- `/etc/nginx/sites-enabled/products-backend` (`php8.2-fpm.sock` -> `php8.4-fpm.sock`)
- `/etc/supervisor/conf.d/{workers.conf (products-backend-* sections only),products-price-refresh.conf,products-supply-statuses.conf,products-ue-enrichment.conf,products-ue-enrichment-extra.conf}`
- `/etc/cron.d/{inventory-sync,products-backend-scheduler,ue-sync-recovery,ozon-storage,integrations-dedup,wb-refresh-tariffs,ue-price-integrity,wb-stock-warehouses,ue-sanity-check}`
- `/var/spool/cron/crontabs/root` (2 `products-backend` lines only) and `/var/spool/cron/crontabs/danya_user` (remove duplicate `unit-economics:sync --all` line)

---

### Task 1 (Phase 0): Git Baseline Checkpoint & Lightweight Production Backup

**Files:**
- Workspace git state on `/Volumes/SP PX10/актуальное/products-backend`
- Backup archive on `194.87.104.42`: `/var/backups/products-backend/pre-laravel13-20261123/`

**Interfaces:**
- Produces: Git tag `pre-laravel-13-upgrade-20261123`, clean upgrade branch `upgrade/laravel-13-php84`, and reversible prod config snapshot at `/var/backups/products-backend/pre-laravel13-20261123/`.

- [ ] **Step 1: Verify and checkpoint current deployed working tree in git**

Inspect `git status` on `/Volumes/SP PX10/актуальное/products-backend` (currently 35 modified/untracked files on `codex/fix-products-sync-recovery-20261116`, already deployed to prod). Confirm with user before committing, then create baseline commit, tag, and upgrade branch:

```bash
git -C "/Volumes/SP PX10/актуальное/products-backend" status --short
git -C "/Volumes/SP PX10/актуальное/products-backend" add -A
git -C "/Volumes/SP PX10/актуальное/products-backend" commit -m "chore: checkpoint deployed state before Laravel 13 upgrade"
git -C "/Volumes/SP PX10/актуальное/products-backend" tag pre-laravel-13-upgrade-20261123
git -C "/Volumes/SP PX10/актуальное/products-backend" checkout -b upgrade/laravel-13-php84
```

Expected: `git status --short` is empty; tag `pre-laravel-13-upgrade-20261123` points to deployed baseline.

- [ ] **Step 2: Verify disk headroom on `194.87.104.42` and save lightweight config + schema backup**

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
avail_kb=$(df -Pk / | awk "NR==2 {print \$4}")
echo "Free on /: $((avail_kb / 1024)) MB"
test "$avail_kb" -ge 4194304 || { echo "ERROR: less than 4 GB free on /"; exit 1; }

BDIR=/var/backups/products-backend/pre-laravel13-20261123
mkdir -p "$BDIR"
tar -czf "$BDIR/configs.tar.gz" \
  /etc/nginx/sites-enabled/products-backend \
  /etc/supervisor/conf.d \
  /etc/cron.d \
  /var/spool/cron/crontabs \
  /etc/php/8.2 \
  /var/www/products-backend/composer.json \
  /var/www/products-backend/composer.lock
chmod 600 "$BDIR/configs.tar.gz"
sudo -u postgres pg_dump --schema-only products_backend | gzip -9 > "$BDIR/schema.sql.gz"
ls -lh "$BDIR"
'
```

Expected: Free space `>= 4096 MB`; `configs.tar.gz` and `schema.sql.gz` exist and total `< 10 MB`. (Optional with user approval: free ~2.4 GB by removing 7-month-old `/root/deploy-backups/20260414_070354_compensation_fix` and running `journalctl --vacuum-size=100M`.)

---

### Task 2 (Phase 1): Zero-Breaking Laravel 12 Security Patch (Close All 31 CVEs First)

**Files:**
- Modify: `composer.json:13` (`phpoffice/phpspreadsheet`: `^5.9.0` -> `^5.12`)
- Modify: `composer.lock` (updated on Laravel `12.73+`, PhpSpreadsheet `5.12+`, Symfony `7.x`, CommonMark, Flysystem — still `"php": "^8.2"` and `"platform.php": "8.2.0"`)

**Interfaces:**
- Consumes: Clean branch `upgrade/laravel-13-php84` from Task 1.
- Produces: Hardened Laravel 12.x release with `0` CVEs live in production before touching PHP version or major framework version.

- [ ] **Step 1: Sync workspace into APFS scratch and bump `phpoffice/phpspreadsheet` + lockfile on Laravel 12**

Update `composer.json` line 13 from `"phpoffice/phpspreadsheet": "^5.9.0"` to `"phpoffice/phpspreadsheet": "^5.12"`. Sync to APFS scratch and run Composer update (keeping PHP 8.2 platform config):

```bash
SCRATCH="/Users/panfiloveshow/.gemini/antigravity/brain/019f809e-75ba-420c-9c20-b40a30f9ba4e/scratch/pb-test"
rsync -a --delete --exclude='vendor' --exclude='.git' --exclude='._*' "/Volumes/SP PX10/актуальное/products-backend/" "$SCRATCH/"
cd "$SCRATCH"
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" update --with-all-dependencies --no-interaction
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" audit --locked --no-interaction
```

Expected: `laravel/framework >= 12.71.0`, `phpoffice/phpspreadsheet >= 5.12.0`, and `composer audit --locked` outputs `No security vulnerability advisories found.` (exit code `0`).

- [ ] **Step 2: Run full test suite in APFS scratch and sync `composer.lock` back**

```bash
cd "$SCRATCH"
/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=512M artisan test
cp "$SCRATCH/composer.lock" "/Volumes/SP PX10/актуальное/products-backend/composer.lock"
```

Expected: All unit & feature tests pass (`0` failures).

- [ ] **Step 3: Commit Phase 1 and deploy security patch to production (still PHP 8.2 + Laravel 12)**

```bash
git -C "/Volumes/SP PX10/актуальное/products-backend" add composer.json composer.lock
git -C "/Volumes/SP PX10/актуальное/products-backend" commit -m "security: update Laravel 12 and PhpSpreadsheet to resolve all 31 CVEs"
bash "/Volumes/SP PX10/актуальное/products-backend/deploy-to-server.sh"
ssh -o BatchMode=yes root@194.87.104.42 'curl -fsS https://products.sellico.ru/up && runuser -u www-data -- php /var/www/products-backend/artisan --version'
```

Expected: Deploy exits `0`, 12 workers `RUNNING`, `/up` returns HTTP 200, `artisan --version` shows `Laravel Framework 12.7x`.

---

### Task 3 (Phase 2A): Parameterize `PHP_BIN` in Deploy Pipeline & Templates (TDD)

**Files:**
- Modify: `tests/deployment/test_source_permissions.py`
- Modify: `scripts/deploy-runtime.sh:8-25`
- Modify: `deploy-to-server.sh:6-43`
- Modify: `scripts/supervisor/products-price-refresh.conf:3`
- Modify: `scripts/supervisor/products-supply-statuses.conf:3`
- Modify: `scripts/supervisor/products-ue-enrichment.conf:3`
- Modify: `scripts/cron.d/integrations-dedup:25`
- Modify: `scripts/cron.d/ue-price-integrity:1-2`
- Modify: `scripts/cron.d/wb-refresh-tariffs:26`
- Modify: `scripts/cron.d/wb-stock-warehouses:24`

**Interfaces:**
- Consumes: Existing `deploy-to-server.sh` and `scripts/deploy-runtime.sh`.
- Produces:
  - `deploy-to-server.sh` env vars: `PHP_BIN` (validated path regex `^/[A-Za-z0-9_./-]+$`, default `/usr/bin/php8.4`), `PHP_FPM_SERVICE` (default `php8.4-fpm`), passed to `scripts/deploy-runtime.sh`.
  - `scripts/deploy-runtime.sh` executes `"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction` and `"$PHP_BIN" artisan ...` under `www-data`, completely independent of `/usr/bin/php`.

- [ ] **Step 1: Write failing deployment test in `tests/deployment/test_source_permissions.py`**

Add a test `test_runtime_uses_explicit_php_bin_for_composer_and_artisan(self)` to `tests/deployment/test_source_permissions.py` that creates a stub `php8.4` binary logging its arguments to `$PHP_TEST_LOG`, a stub `composer` script, and a stub `runuser` executing the command directly, runs `scripts/deploy-runtime.sh` with `PHP_BIN=<stub>/php8.4` and `PHP_FPM_SERVICE=php8.4-fpm`, and asserts:
- exit code is `0`
- `fpm_reload.read_text()` equals `'reload php8.4-fpm\n'`
- `PHP_TEST_LOG` records calls to `composer install --no-dev --optimize-autoloader --no-interaction`, `artisan migrate --force`, `artisan config:cache`, `artisan route:cache`, `artisan view:cache`, `artisan --version`, and `artisan queue:restart` via the explicit `PHP_BIN` (never falling back to bare `php`).

- [ ] **Step 2: Run deployment test to verify it fails**

```bash
python3 -m unittest discover -s "/Volumes/SP PX10/актуальное/products-backend/tests/deployment" -v
```

Expected: FAIL because `scripts/deploy-runtime.sh` currently invokes bare `composer` and bare `php`.

- [ ] **Step 3: Implement `PHP_BIN` support in `scripts/deploy-runtime.sh` and `deploy-to-server.sh`, and update templates**

1. In `scripts/deploy-runtime.sh`:
   - Accept `PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"` and validate `[[ "$PHP_BIN" =~ ^/[A-Za-z0-9_./-]+$ ]] || exit 1`.
   - Default `PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"`.
   - Pass `"$REMOTE_PATH"` and `"$PHP_BIN"` into `runuser -u www-data -- bash -euc '...' _ "$REMOTE_PATH" "$PHP_BIN"`.
   - Inside `runuser`, resolve `COMPOSER_BIN="$(command -v composer)"` and run `"$2" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction` and `"$2" artisan ...`.
2. In `deploy-to-server.sh`:
   - Add `PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"` with regex validation `[[ "$PHP_BIN" =~ ^/[A-Za-z0-9_./-]+$ ]] || exit 1`.
   - Change line 10 default to `PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"`.
   - In pre-flight SSH check (line 22), also verify `test -x '$PHP_BIN'`.
   - Pass `PHP_BIN='$PHP_BIN' PHP_FPM_SERVICE='$PHP_FPM_SERVICE'` on line 42.
3. In `scripts/supervisor/*.conf` and `scripts/cron.d/*`, replace `php` / `/usr/bin/php` with `/usr/bin/php8.4`.

- [ ] **Step 4: Run deployment tests and bash syntax checks**

```bash
bash -n "/Volumes/SP PX10/актуальное/products-backend/deploy-to-server.sh" \
        "/Volumes/SP PX10/актуальное/products-backend/scripts/deploy-runtime.sh"
python3 -m unittest discover -s "/Volumes/SP PX10/актуальное/products-backend/tests/deployment" -v
```

Expected: `bash -n` exits `0`; all unittest cases (`3/3`) PASS.

- [ ] **Step 5: Commit**

```bash
git -C "/Volumes/SP PX10/актуальное/products-backend" add deploy-to-server.sh scripts/deploy-runtime.sh scripts/supervisor scripts/cron.d tests/deployment/test_source_permissions.py
git -C "/Volumes/SP PX10/актуальное/products-backend" commit -m "build(deploy): pin explicit PHP_BIN and PHP 8.4 FPM service for products-backend"
```

---

### Task 4 (Phase 2B): Install PHP 8.4 Side-by-Side on `194.87.104.42` & Cut Over ONLY `products-backend` (Laravel 12 Soak)

**Files (on `194.87.104.42`):**
- Create/configure: `/etc/php/8.4/fpm/pool.d/www.conf`, `/etc/php/8.4/fpm/php.ini`, `/etc/php/8.4/fpm/conf.d/10-opcache.ini`
- Modify: `/etc/nginx/sites-enabled/products-backend`
- Modify: `/etc/supervisor/conf.d/workers.conf` (`products-backend-*` sections ONLY, lines 60–115), `/etc/supervisor/conf.d/products-price-refresh.conf`, `products-supply-statuses.conf`, `products-ue-enrichment.conf`, `products-ue-enrichment-extra.conf`
- Modify: 9 files in `/etc/cron.d/` (`inventory-sync`, `products-backend-scheduler`, `ue-sync-recovery`, `ozon-storage`, `integrations-dedup`, `wb-refresh-tariffs`, `ue-price-integrity`, `wb-stock-warehouses`, `ue-sanity-check`), `/var/spool/cron/crontabs/root` (2 `products-backend` lines), `/var/spool/cron/crontabs/danya_user` (remove duplicate `unit-economics:sync --all` without `--enqueue`)

**Interfaces:**
- Consumes: Task 1 backup + Task 3 `PHP_BIN=/usr/bin/php8.4` deploy scripts.
- Produces: `products-backend` running cleanly on **PHP 8.4.28 + Laravel 12.7x**, while `financial-dashboard`, `organizer-backend`, and `seo-service` stay untouched on **PHP 8.2.30**.

- [ ] **Step 1: Install exact 17 `php8.4-*` packages side-by-side and pin `/usr/bin/php` to `php8.2`**

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
  php8.4 php8.4-bcmath php8.4-cli php8.4-common php8.4-curl php8.4-fpm \
  php8.4-gd php8.4-igbinary php8.4-intl php8.4-mbstring php8.4-opcache \
  php8.4-pgsql php8.4-readline php8.4-redis php8.4-sqlite3 php8.4-xml php8.4-zip
# Ensure global default CLI stays php8.2 so sibling apps are unaffected
update-alternatives --set php /usr/bin/php8.2
php -v | head -n 1
/usr/bin/php8.4 -v | head -n 1
/usr/bin/php8.4 -m | grep -E "^(bcmath|curl|gd|igbinary|intl|mbstring|pdo_pgsql|pdo_sqlite|pgsql|redis|sqlite3|xml|zip)$" | sort
'
```

Expected: `php -v` reports `PHP 8.2.30`; `/usr/bin/php8.4 -v` reports `PHP 8.4.28`; all 13 checked extensions appear.

- [ ] **Step 2: Mirror `php8.2-fpm` pool, `php.ini`, and OPcache tuning onto `php8.4-fpm`**

Configure `/etc/php/8.4/fpm/pool.d/www.conf` with exact parity (`pm = dynamic`, `pm.max_children = 8`, `pm.start_servers = 3`, `pm.min_spare_servers = 2`, `pm.max_spare_servers = 6`, `pm.max_requests = 300`, `listen = /run/php/php8.4-fpm.sock`), set `/etc/php/8.4/fpm/php.ini` (`memory_limit = 256M`, `post_max_size = 50M`, `upload_max_filesize = 50M`, `max_execution_time = 30`), and write `/etc/php/8.4/fpm/conf.d/10-opcache.ini` (matching `8.2` settings: `opcache.enable=1`, `opcache.enable_cli=0`, `opcache.memory_consumption=256`, `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=20000`, `opcache.revalidate_freq=60`, `opcache.validate_timestamps=0`, `opcache.save_comments=1`, `opcache.jit=off` — omitting deprecated `opcache.fast_shutdown`). Validate and start:

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
php-fpm8.4 -t && systemctl enable --now php8.4-fpm && systemctl is-active php8.4-fpm && ls -la /run/php/php8.4-fpm.sock
'
```

Expected: `test is successful`, `active`, socket owned by `www-data:www-data`.

- [ ] **Step 3: Switch ONLY `products-backend` Nginx, Supervisor, and cron entries to PHP 8.4**

1. Edit `/etc/nginx/sites-enabled/products-backend`: change `fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;` -> `unix:/var/run/php/php8.4-fpm.sock;`. Run `nginx -t && systemctl reload nginx`.
2. Edit ONLY `products-backend-*` `command=` lines in `/etc/supervisor/conf.d/workers.conf` and `/etc/supervisor/conf.d/products-*.conf` (`command=php ...` -> `command=/usr/bin/php8.4 ...`). Leave `financial-dashboard-*` and `organizer-backend-*` untouched! Run `supervisorctl reread && supervisorctl update`.
3. Update all 9 `products-backend` files in `/etc/cron.d/` + the 2 `products-backend` lines in `root` crontab to invoke `/usr/bin/php8.4`.
4. Remove the duplicate `unit-economics:sync --all` line (lacking `--enqueue`) from `danya_user` crontab (`crontab -u danya_user -l` backup saved in Task 1).

- [ ] **Step 4: Verify `products-backend` on PHP 8.4 AND sibling apps still healthy on PHP 8.2**

```bash
bash "/Volumes/SP PX10/актуальное/products-backend/deploy-to-server.sh"
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
curl -fsS https://products.sellico.ru/up
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan ue:sync-health
echo "=== products worker binaries ==="
pgrep -af "/var/www/products-backend/artisan"
echo "=== sibling worker binaries (must stay php / php8.2) ==="
pgrep -af "/var/www/(financial-dashboard|organizer-backend)/artisan"
echo "=== supervisor ==="
supervisorctl status
'
```

Expected:
- All 12 `products-backend-*` processes show `/usr/bin/php8.4`, state `RUNNING`.
- All 6 `financial-dashboard-*` and 2 `organizer-backend-*` processes stay on `php` (`8.2`), state `RUNNING`.
- `/up` returns HTTP 200; `ue:sync-health` succeeds; zero PHP 8.4 fatal/deprecation storms in `storage/logs/laravel.log` and Nginx error log over a 15–30 minute soak before starting Phase 3.

---

### Task 5 (Phase 3A): Pre-Laravel 13 Codebase Compatibility Fixes (TDD, Green on Both L12 & L13)

**Files:**
- Create: `tests/Feature/OzonWarehouseClusterCacheSerializationTest.php`
- Modify: `app/Models/OzonWarehouseCluster.php:52-108`
- Modify: `config/cache.php:105-117`
- Modify: `tests/Unit/OzonCredentialHealthServiceTest.php:1-75`
- Create: `tests/Feature/ArtisanCompatibilityCommandsTest.php`
- Create: `tests/Unit/SpreadsheetRoundtripTest.php`

**Interfaces:**
- Produces:
  - `OzonWarehouseCluster::findByWarehouseName(string $warehouseName): ?self` — identical signature & return type, caches `?array` payload (or `'__null__'` sentinel) instead of an Eloquent `Model`, hydrates via `static::newFromBuilder($attributes)`.
  - `OzonWarehouseCluster::getWarehousesByCluster(int $clusterId): \Illuminate\Database\Eloquent\Collection` — caches `array<int, array<string, mixed>>`, hydrates via `static::hydrate($rows)`.
  - `config/cache.php` explicitly sets `'serializable_classes' => false`.

- [ ] **Step 1: Write failing cache serialization test `tests/Feature/OzonWarehouseClusterCacheSerializationTest.php`**

Test asserts (using `config()->set('cache.default', 'database')` after creating the `cache` table if needed in SQLite `:memory:`):
- `test_find_by_warehouse_name_roundtrips_without_serializing_php_objects()`: inserts an `OzonWarehouseCluster` row, calls `findByWarehouseName()` twice (cold miss + warm hit), asserts second call returns `OzonWarehouseCluster` instance with matching `id`, `cluster_id`, `cluster_name`, AND asserts `DB::table('cache')->pluck('value')` contains zero PHP object serialization markers matching `/O:\d+:"/`.
- `test_find_by_warehouse_name_caches_missing_warehouse_as_null()`: calls `findByWarehouseName('NON_EXISTENT_WH')` twice, asserts both return `null`.
- `test_get_warehouses_by_cluster_roundtrips_collection_without_serializing_objects()`: inserts 2 rows in same `cluster_id`, calls `getWarehousesByCluster()` twice, asserts warm hit returns `Illuminate\Database\Eloquent\Collection` of `OzonWarehouseCluster` instances with count `2`, and zero `/O:\d+:"/` markers in `cache` table.

- [ ] **Step 2: Run new test in APFS scratch to verify it fails**

```bash
SCRATCH="/Users/panfiloveshow/.gemini/antigravity/brain/019f809e-75ba-420c-9c20-b40a30f9ba4e/scratch/pb-test"
rsync -a --delete --exclude='vendor' --exclude='.git' --exclude='._*' "/Volumes/SP PX10/актуальное/products-backend/" "$SCRATCH/"
cd "$SCRATCH" && /opt/homebrew/opt/php@8.3/bin/php artisan test tests/Feature/OzonWarehouseClusterCacheSerializationTest.php
```

Expected: FAIL because current `OzonWarehouseCluster` writes serialized `O:32:"App\Models\OzonWarehouseCluster"` and `O:39:"Illuminate\Database\Eloquent\Collection"` into the `cache` table.

- [ ] **Step 3: Refactor `OzonWarehouseCluster` caching and add `'serializable_classes' => false` to `config/cache.php`**

1. In `app/Models/OzonWarehouseCluster.php`:
   - In `findByWarehouseName(string $warehouseName): ?self`: keep the existing `DatabaseStore` + `transactionLevel() > 0` deadlock guard (lines 59–62) intact. For the cached path, store a plain array payload `['found' => true, 'attributes' => $model->getAttributes()]` or `['found' => false]` under `"ozon_warehouse_cluster:v2:{$normalized}"` (v2 prefix avoids colliding with any stale pre-cutover serialized entries!), and hydrate via `static::newFromBuilder($cached['attributes'])`.
   - In `getWarehousesByCluster(int $clusterId)`: cache plain array of raw attribute arrays (`->map(fn (self $m) => $m->getAttributes())->all()`) under `"ozon_cluster_warehouses:v2:{$clusterId}"`, and return `static::hydrate($rows)`.
2. In `config/cache.php`: add `'serializable_classes' => false,` after `'prefix'`.

- [ ] **Step 4: Migrate PHPUnit 12 `@dataProvider` and add Artisan + Spreadsheet regression tests**

1. In `tests/Unit/OzonCredentialHealthServiceTest.php`: import `use PHPUnit\Framework\Attributes\DataProvider;` and replace `* @dataProvider authRejectionStatuses` (line 66) with `#[DataProvider('authRejectionStatuses')]`.
2. Create `tests/Feature/ArtisanCompatibilityCommandsTest.php` asserting `exitCode === 0` for:
   - `$this->artisan('route:list', ['--columns' => 'method,uri'])->assertSuccessful()`
   - `$this->artisan('schedule:list', ['--columns' => 'expression'])->assertSuccessful()`
   - `$this->artisan('schedule:run', ['--columns' => 'ignored', '--whisper' => true])->assertSuccessful()`
3. Create `tests/Unit/SpreadsheetRoundtripTest.php` writing a 2-row workbook via `PhpOffice\PhpSpreadsheet\Spreadsheet` + `Writer\Xlsx` into a temp file, reading it back via `Reader\Xlsx`, and asserting exact cell values (`'SKU-001'`, `1499.5`).

- [ ] **Step 5: Run all new and existing tests in APFS scratch & commit**

```bash
rsync -a --delete --exclude='vendor' --exclude='.git' --exclude='._*' "/Volumes/SP PX10/актуальное/products-backend/" "$SCRATCH/"
cd "$SCRATCH" && /opt/homebrew/opt/php@8.3/bin/php -d memory_limit=512M artisan test
git -C "/Volumes/SP PX10/актуальное/products-backend" add app/Models/OzonWarehouseCluster.php config/cache.php tests/Feature/OzonWarehouseClusterCacheSerializationTest.php tests/Feature/ArtisanCompatibilityCommandsTest.php tests/Unit/SpreadsheetRoundtripTest.php tests/Unit/OzonCredentialHealthServiceTest.php
git -C "/Volumes/SP PX10/актуальное/products-backend" commit -m "refactor: prepare cache serialization, PHPUnit 12 attributes, and command guards for Laravel 13"
git -C "/Volumes/SP PX10/актуальное/products-backend" tag pre-laravel-13-code-cutover-20261123
```

Expected: All tests pass (still on Laravel 12); tag `pre-laravel-13-code-cutover-20261123` provides a clean PHP 8.4-compatible Laravel 12 rollback target.

---

### Task 6 (Phase 3B): Upgrade `composer.json` to Laravel 13 + Tinker 3 + PHPUnit 12 + CI PHP 8.4

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `config/sanctum.php:83`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Task 5 pre-hardened codebase.
- Produces: Verified Laravel 13.x + Symfony 7.4 LTS + PHPUnit 12.5+ lockfile passing 100% of unit, feature, deployment, and security-audit gates.

- [ ] **Step 1: Update `composer.json`, `config/sanctum.php`, and `.github/workflows/ci.yml`**

1. In `composer.json`:
   - `require`: `"php": "^8.3"`, `"laravel/framework": "^13.0"`, `"laravel/tinker": "^3.0"`, `"maennchen/zipstream-php": "^3.1"`, `"phpoffice/phpspreadsheet": "^5.12"`
   - `require-dev`: `"laravel/pail": "^1.2.5"`, `"laravel/pint": "^1.27"`, `"phpunit/phpunit": "^12.5"` (keep `fakerphp/faker: ^1.23`, `laravel/sail: ^1.41`, `mockery/mockery: ^1.6`, `nunomaduro/collision: ^8.6`)
   - `config.platform.php`: `"8.3.0"` (pins Symfony `^7.4` LTS while supporting PHP 8.3 & 8.4 runtimes!)
2. In `config/sanctum.php:83`: change `'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class` to `Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class`.
3. In `.github/workflows/ci.yml`:
   - Change `php-version: '8.3'` -> `'8.4'` in BOTH `critical-tests` (line 37) and `unit-economics-postgres` (line 133).
   - Add `tests/Feature/OzonWarehouseClusterCacheSerializationTest.php`, `tests/Feature/ArtisanCompatibilityCommandsTest.php`, `tests/Unit/SpreadsheetRoundtripTest.php`, and `tests/Unit/OzonCredentialHealthServiceTest.php` to `critical-tests`.
   - Remove `continue-on-error: true` from `composer audit` (optional — makes CI fail on new CVEs going forward).

- [ ] **Step 2: Resolve Laravel 13 dependency graph in APFS scratch**

```bash
SCRATCH="/Users/panfiloveshow/.gemini/antigravity/brain/019f809e-75ba-420c-9c20-b40a30f9ba4e/scratch/pb-test"
rsync -a --delete --exclude='vendor' --exclude='.git' --exclude='._*' "/Volumes/SP PX10/актуальное/products-backend/" "$SCRATCH/"
cd "$SCRATCH"
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" update --with-all-dependencies --no-interaction
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" validate --strict --no-check-publish
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" audit --locked --no-interaction
/opt/homebrew/opt/php@8.3/bin/php artisan --version
/opt/homebrew/opt/php@8.3/bin/php "$(which composer)" show 'symfony/*' | head -n 15
```

Expected:
- `artisan --version` outputs `Laravel Framework 13.x`.
- `symfony/*` packages resolve on `v7.4.x` LTS.
- `composer audit --locked` exits `0` (`No security vulnerability advisories found.`).

- [ ] **Step 3: Run full test suite + PHPUnit 12 strict deprecation check + syntax lint**

```bash
cd "$SCRATCH"
find app routes config database/migrations tests -name '*.php' -print0 | xargs -0 -n1 /opt/homebrew/opt/php@8.3/bin/php -l > /dev/null
/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=512M artisan test
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --display-deprecations --display-warnings --no-progress tests/Unit/OzonCredentialHealthServiceTest.php tests/Feature/OzonWarehouseClusterCacheSerializationTest.php tests/Feature/ArtisanCompatibilityCommandsTest.php tests/Unit/SpreadsheetRoundtripTest.php
```

If any test fails or emits a Laravel 13 / PHPUnit 12 deprecation warning, fix it inline and re-run until `0` failures and `0` PHPUnit attribute warnings. Also run `tests/deployment`:

```bash
python3 -m unittest discover -s "/Volumes/SP PX10/актуальное/products-backend/tests/deployment" -v
```

- [ ] **Step 4: Copy verified `composer.lock` back to workspace and commit**

```bash
cp "$SCRATCH/composer.lock" "/Volumes/SP PX10/актуальное/products-backend/composer.lock"
git -C "/Volumes/SP PX10/актуальное/products-backend" add composer.json composer.lock config/sanctum.php .github/workflows/ci.yml
git -C "/Volumes/SP PX10/актуальное/products-backend" commit -m "chore: upgrade framework to Laravel 13, Tinker 3, PHPUnit 12, and CI PHP 8.4"
```

- [ ] **Step 5: Verify GitHub CI (including `unit-economics-postgres` on real PostgreSQL 16 + PHP 8.4)**

Push `upgrade/laravel-13-php84` (only with user confirmation) and verify BOTH `critical-tests` AND `unit-economics-postgres` (real PG16 + PHP 8.4) pass green before production cutover!

---

### Task 7 (Phase 4): Controlled Zero-Breakage Production Cutover to Laravel 13

**Files:**
- Production deployment on `194.87.104.42:/var/www/products-backend`

**Interfaces:**
- Consumes: Task 4 (`products-backend` already running stably on PHP 8.4) + Task 6 (CI-green Laravel 13 commit).
- Produces: Live `products-backend` running PHP 8.4.28 + Laravel 13.x with all 12 workers `RUNNING` and verified end-to-end.

- [ ] **Step 1: Pre-cutover queue inspection & clean worker pause**

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan tinker --execute="
echo json_encode([\"jobs\"=>DB::table(\"jobs\")->select(\"queue\", DB::raw(\"count(*) as c\"))->groupBy(\"queue\")->get()]);
"
# Pause ONLY products-backend workers cleanly so no L12 worker runs during code swap
supervisorctl stop products-backend-worker:* products-backend-ue-worker:* products-backend-locality-worker:* products-backend-autoplanning-worker:* products-backend-price-refresh-worker:* products-backend-supply-status-worker:* products-backend-ue-enrichment-worker:* products-backend-ue-enrichment-worker-extra:*
'
```

Expected: All 12 `products-backend-*` workers reach `STOPPED`; `financial-dashboard-*` and `organizer-backend-*` remain `RUNNING`.

- [ ] **Step 2: Deploy Laravel 13 via `deploy-to-server.sh`**

```bash
bash "/Volumes/SP PX10/актуальное/products-backend/deploy-to-server.sh"
```

Expected:
- `composer install --no-dev --optimize-autoloader` runs via `/usr/bin/php8.4` and succeeds.
- `migrate --force`, `config:cache`, `route:cache`, `view:cache` succeed.
- `php8.4-fpm` reloads cleanly.
- `deploy-runtime.sh` automatically starts all `STOPPED` workers and prints `Confirmed 12 running products workers`.

- [ ] **Step 3: Purge legacy serialized `OzonWarehouseCluster` cache keys & run post-cutover verification**

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan --version
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan route:list --columns=method,uri | head -n 15
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan schedule:list | head -n 15
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan tinker --execute="
\$deleted = DB::table(\"cache\")
    ->where(function (\$q) {
        \$q->where(\"key\", \"like\", \"%ozon_warehouse_cluster:%\")
          ->orWhere(\"key\", \"like\", \"%ozon_cluster_warehouses:%\");
    })
    ->where(\"key\", \"not like\", \"%:v2:%\")
    ->delete();
echo \"purged_legacy_cluster_cache=\$deleted\n\";
"
curl -fsS -i https://products.sellico.ru/up | head -n 10
runuser -u www-data -- /usr/bin/php8.4 /var/www/products-backend/artisan ue:sync-health
supervisorctl status
'
```

Expected:
- `Laravel Framework 13.x`
- `route:list` and `schedule:list` exit `0`
- `https://products.sellico.ru/up` returns `200 OK`
- `ue:sync-health` exits `0`
- All 20 Supervisor processes (12 `products-backend`, 6 `financial-dashboard`, 2 `organizer-backend`) show `RUNNING`.

- [ ] **Step 4: Monitor logs for 15 minutes post-cutover**

```bash
ssh -o BatchMode=yes root@194.87.104.42 '
tail -n 60 /var/www/products-backend/storage/logs/laravel.log
tail -n 30 /var/log/nginx/error.log
tail -n 20 /var/www/products-backend/storage/logs/worker*.log
'
```

Expected: Zero `__PHP_Incomplete_Class`, zero `TypeError`/`ParseError`, zero 500/502 responses in Nginx.

---

### Task 8: Rollback Runbook & Post-Upgrade Follow-Ups

**Rollback A — Revert Phase 4 (Laravel 13 -> Laravel 12 on PHP 8.4, ~90 seconds):**
```bash
# Locally (returns to hardened Laravel 12 with PHP_BIN=/usr/bin/php8.4 intact):
git -C "/Volumes/SP PX10/актуальное/products-backend" checkout pre-laravel-13-code-cutover-20261123
bash "/Volumes/SP PX10/актуальное/products-backend/deploy-to-server.sh"
```
(No DB migration rollback needed — Laravel 13 upgrade introduces zero destructive schema migrations.)

**Rollback B — Revert Phase 2 (PHP 8.4 -> PHP 8.2 for `products-backend`, ~60 seconds):**
```bash
ssh -o BatchMode=yes root@194.87.104.42 '
set -euo pipefail
BDIR=/var/backups/products-backend/pre-laravel13-20261123
tar -xzf "$BDIR/configs.tar.gz" -C / \
  etc/nginx/sites-enabled/products-backend \
  etc/supervisor/conf.d \
  etc/cron.d \
  var/spool/cron/crontabs
nginx -t && systemctl reload nginx
supervisorctl reread && supervisorctl update
'
```

**Tracked Follow-Ups (Separate Tasks, Out of Scope for This Cutover):**
1. **Migrate sibling apps off PHP 8.2 before `2026-12-31` EOL:** `financial-dashboard` (Laravel 12), `organizer-backend` (Laravel 12), and `seo-service` still run on PHP 8.2; once `products-backend` proves stable on PHP 8.4, migrate each sibling app using the same side-by-side pattern, then remove `php8.2-*`.
2. **Reclaim disk space on `/` (`94%` full):** Review and prune stale `/root/deploy-backups/20260414_070354_compensation_fix` (`2.0G`), vacuum `/var/log/journal` (`491M`), and prune `10,280` historical rows in `products-backend` `failed_jobs` (`php8.4 artisan queue:prune-failed --hours=168`).
3. **Move `routes/console.php` `env()` flags into `config/`:** `WB_TARIFF_SCHEDULE`, `OZON_STORAGE_SCHEDULE`, `MP_CONSTRAINTS_SCHEDULE`, `INTEGRATIONS_DEDUP_SCHEDULE`, and `INVENTORY_SYNC_SCHEDULE` currently call `env()` directly in `routes/console.php`, which ignores `.env` overrides while `config:cache` is active.
