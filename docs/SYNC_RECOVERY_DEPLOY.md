# Deployment and queue recovery

`bash deploy-to-server.sh` builds a temporary source artifact before uploading it.
Files receive mode 0644 and directories 0755; shell scripts and artisan remain
executable. The transfer preserves existing server permissions and excludes
`.env*`, vendor, storage and bootstrap/cache. This works with macOS openrsync and
source disks that expose all files as 0700.

SSH key access is required for the upload account (`DEPLOY_USER`, default
`danya_user`) and administrator (`DEPLOY_ADMIN_USER`, default `root`). The host and
project path can be overridden with `DEPLOY_HOST` and `REMOTE_PATH`. Administrator
access is checked before uploading any files.

Dependencies, migrations and framework caches are prepared as `www-data` with
umask 002. A successful application boot as that user is required. Running queue
workers receive a graceful restart; FATAL/STOPPED workers are explicitly started.
PHP-FPM receives a graceful reload so uploaded HTTP code becomes active even
when OPcache timestamp validation is disabled. The service defaults to
`php8.2-fpm` and can be selected with `PHP_FPM_SERVICE`.
Deployment fails if required worker groups are absent or products workers do not
reach RUNNING. Supervisor should be managed separately from PHP-FPM.

Run exactly one products scheduler as `www-data`, for example the existing
`/etc/cron.d/products-backend-scheduler`. Do not also schedule `schedule:run` in
root's crontab. Shared application log files must be writable by `www-data`.

Regression checks:

```sh
bash -n deploy-to-server.sh scripts/prepare-deploy-source.sh scripts/deploy-runtime.sh
python3 -m unittest discover -s tests/deployment -v
```

Freshness regression tests use the isolated PostgreSQL fixture and bootstrap
documented in `.github/workflows/ci.yml`. They cover queued work, failed stages,
expired requests and continued independent price publication.

WB API service identification and cooldowns (checked 2026-11-18):

- Basic seller tokens (`acc=1`) without a service secret allow prices once per
  15 minutes and detailed Finance reports once per 12 hours. Queue retries must
  honour the full `X-Ratelimit-Retry` response value, even above six hours.
  A shared per-credential Finance cooldown and lock keep other workers/date
  ranges from retrying the same detailed-report endpoint before that time.
  Workflows that include WB Finance have a 24-hour budget from creation
  (`UE_WB_FINANCE_DEADLINE_HOURS`), so a documented 12-hour cooldown can fit.
  Price-only workflows and Ozon retain the general deadline. A coalesced request
  does not advance the original deadline indefinitely.
- Set `WILDBERRIES_CLIENT_SECRET` to the service secret issued in WB's company
  profile, then rebuild the Laravel configuration cache and restart workers.
  This is separate from each seller's `Authorization` API token. `User-Agent`
  alone does not provide the service rate limits. The client sends the secret
  with basic and service tokens; it omits it with personal tokens (`acc=3`),
  which WB rejects when combined with `X-Client-Secret`.
- Do not shorten a current cooldown or mark a waiting report as fresh when
  configuring the secret. Preserve cached calculations until an actual successful
  response is published.

Official references:
[token types](https://dev.wildberries.kg/docs/openapi/api-information),
[price limits](https://dev.wildberries.kg/docs/openapi/item-management),
[Finance limits](https://dev.wildberries.kg/docs/openapi/documents-and-accounting),
[service identification](https://dev.wildberries.kg/cases/144/identifikatsiia-servisa-v-wb-api).
