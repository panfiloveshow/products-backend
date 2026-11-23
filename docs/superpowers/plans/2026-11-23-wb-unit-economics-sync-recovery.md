# Wildberries Unit Economics Sync Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unblock Wildberries unit economics updates for basic-token (`acc=1`) integrations by decoupling enrichment (`SyncUnitEconomicsJob`) from WB Finance `429` deferrals/failures, eliminating cross-service `X-Client-Secret` gaps in `financial-dashboard`, and rolling out `WILDBERRIES_CLIENT_SECRET` across both services.

**Architecture:** `products-backend` already sends `X-Client-Secret` for JWT `acc` ∈ `{1, 4}` and shares a per-credential Finance cooldown in DB cache, but blocks `SyncUnitEconomicsJob` (`enrichment`) behind `SyncStorageFeesJob` (`finance`), while `financial-dashboard` on the same server calls the same WB Finance endpoint without `X-Client-Secret` and wins every 12-hour basic-token slot. We fix the `products-backend` state machine atomically so `enrichment` runs immediately on Finance `429`/failure and re-runs cleanly when Finance later succeeds, add matching `X-Client-Secret` header support to `financial-dashboard`, and roll out `WILDBERRIES_CLIENT_SECRET` + env/key hygiene on production.

**Tech Stack:** PHP 8.2/8.3, Laravel (database queue in `products-backend`, Redis queue in `financial-dashboard`), PostgreSQL 16, PHPUnit, Supervisor, rsync/SSH deploy.

**Spec / Diagnosis Source:** Live production audit of `194.87.104.42` on `2026-11-23` + `docs/SYNC_RECOVERY_DEPLOY.md`.

## Global Constraints

- Never send `X-Client-Secret` with personal (`acc=3`), test (`acc=2`), or unrecognised tokens (WB rejects personal tokens with `X-Client-Secret` with HTTP `403`).
- Never shorten an active WB `X-Ratelimit-Retry` cooldown or mark waiting/stale Finance data as `fresh` (`UnitEconomicsFreshness::forIntegration()['overall']['is_fresh']` must stay `false` while `finance` is `waiting_external`).
- Preserve cached `unit_economics`, `unit_economics_cache`, `inventory_warehouses.storage_fee_*`, and `wb_acquiring_*` values until an actual successful API response is published.
- Maintain existing lock ordering (`unit_economics_sync_states` -> `unit_economics_sync_runs`) in `UnitEconomicsSyncCoordinator::mutate()`.
- Deploy `products-backend` exclusively via `bash deploy-to-server.sh` (runs `www-data` smoke check, PHP-FPM reload, and Supervisor worker recovery).

## Review Focus

1. **Double-completion race on post-Finance re-enrichment:** When `enrichment` already finished (`completed` or `partial`) during a Finance `429` deferral and `SyncStorageFeesJob` later succeeds, marking `finance=completed` before resetting `enrichment=queued` would prematurely transition the entire `UnitEconomicsSyncRun` to terminal `completed` and lock out the second `enrichment` pass. Must be one atomic coordinator transition.
2. **Coalesced `full` sync stranding `enrichment` in `pending`:** When `inventory:sync-scheduled` coalesces into an open run whose `finance` is `waiting_external`, `UnitEconomicsSyncCoordinator::request()` resets `enrichment` to `pending`, while `SyncInventoryJob` dispatching `SyncStorageFeesJob` is silently dropped by `ShouldBeUnique`. `SyncInventoryJob` must still dispatch `SyncUnitEconomicsJob` when `finance` is already `waiting_external`.
3. **Redundant WB Finance HTTP call during deferred `enrichment`:** Launching `SyncUnitEconomicsJob` right after `SyncStorageFeesJob` hits `429` must NOT send a second HTTP request to `finance-api.wildberries.ru` (verified via `WildberriesClient`'s shared `wb:finance:cooldown:*` cache).
4. **`SyncStorageFeesJob::failed()` after 24h `deadline_at` (`MaxAttemptsExceededException`):** Terminal failure of `SyncStorageFeesJob` must not leave `unit_economics` unrefreshed — it must still dispatch `SyncUnitEconomicsJob` (unlinked from an expired run if the run's deadline already passed).
5. **`financial-dashboard` sending `X-Client-Secret` with personal `acc=3` tokens:** Adding `X-Client-Secret` unconditionally in `WbApiClient::buildHeaders()` would break existing personal-token integrations (`#28`, `#31`, `#32`, `#81`) with `403`. Must gate strictly on decoded JWT `acc` ∈ `{1, 4}`.

---

### Task 1: Atomic Coordinator Transitions for Post-Finance Re-enrichment (`products-backend`)

**Files:**
- Modify: `app/Services/UnitEconomics/UnitEconomicsSyncCoordinator.php:215-226,292-333`
- Test: `tests/Feature/UnitEconomicsSyncCoordinatorTest.php`

**Interfaces:**
- Produces:
  - `UnitEconomicsSyncCoordinator::completeFinanceAndRequeueEnrichment(string $runId, array $financeDetails = []): bool` — atomically (inside `mutate()`) sets `finance` stage to `'completed'` with `$financeDetails`, resets `enrichment` stage to `'queued'` (`available_at = now()`, clearing terminal `finished_at`/`error_code`/`source_status`), calls `deriveStatus($run)`, and saves. Returns `false` if run is missing/superseded/already terminal.
  - `UnitEconomicsSyncCoordinator::queueEnrichmentIfPending(string $runId): bool` — atomically (inside `mutate()`) transitions `enrichment` from `'pending'` to `'queued'` (`available_at = now()`) while leaving `finance` untouched; returns `false` if `enrichment` is not `'pending'` or run is terminal.

- [ ] **Step 1: Write failing coordinator tests**

Add two tests to `tests/Feature/UnitEconomicsSyncCoordinatorTest.php`:
- `test_complete_finance_atomically_requeues_already_completed_or_partial_enrichment_without_closing_run`: creates WB `full` run, completes `catalog`, `inventory`, `prices`, `projection` (with `published_version = 1`), sets `finance = waiting_external`, completes `enrichment` as `'partial'` (`error_code = 'source_data_incomplete'`), asserts run stays `waiting_external`; calls `$coordinator->completeFinanceAndRequeueEnrichment($run->id, ['total' => 5, 'succeeded' => 5])`, asserts return `true`, `finance.status === 'completed'`, `enrichment.status === 'queued'`, `enrichment.finished_at === null`, `run.status === 'queued'`, `run.finished_at === null`; then calls `enrichmentPublished($run->id, ['total' => 5, 'success' => 5, 'errors' => 0])` and asserts `enrichment.status === 'completed'` and `run.status === 'completed'`.
- `test_queue_enrichment_if_pending_only_transitions_from_pending`: verifies `true` from `'pending'` -> `'queued'`, and `false` if already `'queued'`, `'running'`, or `'completed'`.

- [ ] **Step 2: Run new coordinator tests to verify they fail**

Run: `php -d memory_limit=512M artisan test tests/Feature/UnitEconomicsSyncCoordinatorTest.php`
Expected: FAIL (`Call to undefined method ...::completeFinanceAndRequeueEnrichment()`).

- [ ] **Step 3: Implement `completeFinanceAndRequeueEnrichment` and `queueEnrichmentIfPending` in `app/Services/UnitEconomics/UnitEconomicsSyncCoordinator.php`**

Implement both methods inside `$this->mutate($runId, ...)` guarding against `in_array($run->status, self::TERMINAL, true)`. In `completeFinanceAndRequeueEnrichment`, update `$stages['finance']` to `'completed'` (with `finished_at = now()`, `next_retry_at = null`, merged `$financeDetails`) and replace `$stages['enrichment']` with `$this->stage('queued', $run->requested_version)` BEFORE calling `$this->deriveStatus($run)` and `$run->save()`.

- [ ] **Step 4: Run coordinator tests to verify they pass**

Run: `php -d memory_limit=512M artisan test tests/Feature/UnitEconomicsSyncCoordinatorTest.php`
Expected: PASS (all tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/UnitEconomics/UnitEconomicsSyncCoordinator.php tests/Feature/UnitEconomicsSyncCoordinatorTest.php
git commit -m "fix(ue-sync): atomically requeue enrichment when WB finance completes"
```

---

### Task 2: Decouple `SyncStorageFeesJob` & `SyncInventoryJob` from Blocking `enrichment` (`products-backend`)

**Files:**
- Modify: `app/Jobs/SyncStorageFeesJob.php:83-120,210-249`
- Modify: `app/Jobs/SyncInventoryJob.php:439-460`
- Test: `tests/Feature/WildberriesFullSyncCredentialsTest.php`

**Interfaces:**
- Consumes:
  - `UnitEconomicsSyncCoordinator::completeFinanceAndRequeueEnrichment(string $runId, array $financeDetails = []): bool` (from Task 1)
  - `UnitEconomicsSyncCoordinator::queueEnrichmentIfPending(string $runId): bool` (from Task 1)
- Produces:
  - `SyncStorageFeesJob::handle()` dispatches `SyncUnitEconomicsJob` on `rate_limited` when `queueEnrichmentIfPending()` returns `true` (or for legacy runs without `$unitEconomicsRunId`), while still deferring itself by `retry_after + 15` and keeping `finance = waiting_external`.
  - `SyncStorageFeesJob::handle()` uses `completeFinanceAndRequeueEnrichment()` on Finance success before dispatching `SyncUnitEconomicsJob`, so fresh storage/acquiring data always triggers a final enrichment pass.
  - `SyncStorageFeesJob::failed()` marks `finance = failed` (`finance_worker_failed`) and dispatches `SyncUnitEconomicsJob` (passing `$this->unitEconomicsRunId` only if the run exists and is neither terminal nor past `deadline_at`, else `null` so cache still rebuilds).
  - `SyncInventoryJob::handle()` for WB checks if the attached run already has `finance.status === 'waiting_external'` after dispatching `SyncStorageFeesJob`; if so, calls `queueEnrichmentIfPending($runId)` and dispatches `SyncUnitEconomicsJob` so coalesced scheduled inventory syncs never leave `enrichment` orphaned in `pending`.

- [ ] **Step 1: Write/update failing tests in `tests/Feature/WildberriesFullSyncCredentialsTest.php`**

1. Update `test_finance_honours_the_full_twelve_hour_retry_without_marking_data_fresh`:
   - Keep assertions: `release(43215)`, `finance.status === 'waiting_external'`, `finance.error_code === 'rate_limited'`, `finance.next_retry_at`, `retryUntil() > now()+43215`, `Http::assertSentCount(1)`.
   - Replace `Queue::assertNotPushed(SyncUnitEconomicsJob::class)` with assertions that:
     - `Queue::assertPushed(SyncUnitEconomicsJob::class, 1)` (with matching `integrationId` and `unitEconomicsRunId`),
     - `run.stages['enrichment']['status'] === 'queued'`,
     - `app(UnitEconomicsFreshness::class)->forIntegration($integration)['overall']['is_fresh'] === false` and `['status'] === 'waiting_external'`.
   - Also invoke `$job->handle(...)` a second time under the same `429` (after `enrichment` is already `'queued'` / `'completed'`) and assert `SyncUnitEconomicsJob` is NOT dispatched a second time while still `rate_limited`.
2. Add `test_finance_terminal_failure_still_dispatches_unit_economics_sync`:
   - Calls `(new SyncStorageFeesJob($integration->id, [], 4, $run->id))->failed(new MaxAttemptsExceededException(...))` for (a) an active run and (b) an expired run (`deadline_at` in the past); asserts `finance.status === 'failed'` (`finance_worker_failed`) and `SyncUnitEconomicsJob` is dispatched in both cases (with `unitEconomicsRunId = $run->id` when active, `null` when expired).
3. Add `test_coalesced_inventory_sync_dispatches_enrichment_when_finance_is_already_waiting_external`:
   - Sets up a WB `full` run where `finance` is `'waiting_external'`, `enrichment` was reset to `'pending'` by a coalesced `request($integration, 'full')`, and a sleeping `SyncStorageFeesJob` already holds its unique lock; runs `SyncInventoryJob::handle()` and asserts `SyncUnitEconomicsJob` is pushed and `enrichment.status === 'queued'`.

- [ ] **Step 2: Run feature tests to verify they fail**

Run: `php -d memory_limit=512M artisan test tests/Feature/WildberriesFullSyncCredentialsTest.php`
Expected: FAIL on the 3 new/updated assertions.

- [ ] **Step 3: Implement changes in `app/Jobs/SyncStorageFeesJob.php` and `app/Jobs/SyncInventoryJob.php`**

- In `SyncStorageFeesJob::handle()`:
  - On `status === 'rate_limited'`: keep `$this->release($delay)` and `$this->stage('waiting_external', ...)`; then if `$this->unitEconomicsRunId === null || $coordinator->queueEnrichmentIfPending($this->unitEconomicsRunId)`, call `$this->dispatchUnitEconomicsSync()`.
  - On `status !== 'success'` (`unavailable`): call `$this->stage('failed', ['error_code' => 'finance_unavailable'])`, call `$coordinator->queueEnrichmentIfPending($this->unitEconomicsRunId)` (if run ID set), and call `$this->dispatchUnitEconomicsSync()`.
  - On success: if `$this->unitEconomicsRunId` is set, call `$coordinator->completeFinanceAndRequeueEnrichment($this->unitEconomicsRunId, ['total' => $updated, 'succeeded' => $updated, 'observed_at' => $acquiringSnapshot['observed_at'] ?? null])` instead of `$this->stage('completed', ...)`, then call `$this->dispatchUnitEconomicsSync()`.
- In `SyncStorageFeesJob::failed()`:
  - Keep `$this->stage('failed', ['error_code' => 'finance_worker_failed'])` and error log.
  - Inspect `$run = $this->unitEconomicsRunId ? UnitEconomicsSyncRun::find($this->unitEconomicsRunId) : null`; determine `$targetRunId = ($run && ! in_array($run->status, ['completed', 'partial', 'failed', 'superseded'], true) && ! $run->deadline_at->isPast() && $coordinator->queueEnrichmentIfPending($run->id)) ? $run->id : null`; dispatch `SyncUnitEconomicsJob::dispatch($this->integrationId, unitEconomicsRunId: $targetRunId)` (skipping only if `$run` already has `enrichment` in `'completed'`).
- In `SyncInventoryJob::handle()` lines `440-451`:
  - After `SyncStorageFeesJob::dispatch(...)`, if `$runId` is non-empty and `UnitEconomicsSyncRun::find($runId)?->stages['finance']['status'] === 'waiting_external'`, call `if ($coordinator->queueEnrichmentIfPending($runId)) { SyncUnitEconomicsJob::dispatch((int) $this->syncLog->integration_id, unitEconomicsRunId: $runId); }`.

- [ ] **Step 4: Run `products-backend` regression test suite**

Run:
```bash
php -d memory_limit=512M artisan test \
  tests/Feature/WildberriesFullSyncCredentialsTest.php \
  tests/Feature/UnitEconomicsSyncCoordinatorTest.php \
  tests/Feature/UnitEconomicsFreshnessTest.php \
  tests/Unit/WildberriesApiContractTest.php \
  tests/Unit/WildberriesRealizationReportApiTest.php
```
Expected: ALL PASS (0 failures). Also run `find app routes config tests -name '*.php' -print0 | xargs -0 -n1 php -l`.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SyncStorageFeesJob.php app/Jobs/SyncInventoryJob.php tests/Feature/WildberriesFullSyncCredentialsTest.php
git commit -m "fix(wb-sync): run unit economics enrichment without blocking on 12h finance cooldown"
```

---

### Task 3: Add `X-Client-Secret` Support to `financial-dashboard`

**Files (on production repo `/var/www/financial-dashboard` — note local mirror at `/Volumes/SP PX10/актуальное/финансовая сводка/financial-dashboard` is behind server HEAD `a473726`, so align git first or apply cleanly on the canonical repo):**
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `app/Services/Wildberries/WbApiClient.php` (`buildHeaders()`, constructor)
- Test: `tests/Unit/WbApiClientTest.php`

**Interfaces:**
- Produces:
  - `config('services.wildberries.client_secret')` read from `env('WILDBERRIES_CLIENT_SECRET')` and `config('services.wildberries.user_agent', 'wbas_sellico.ru2823')`.
  - `WbApiClient::buildHeaders(): array` adds `'X-Client-Secret' => $this->clientSecret` ONLY when `$this->clientSecret !== ''` AND decoded JWT payload `$claims['acc']` is strictly `1` (basic) or `4` (service). Omits header for `acc=3` (personal), `acc=2` (test), or non-JWT/empty secrets.

- [ ] **Step 1: Write failing unit tests in `tests/Unit/WbApiClientTest.php`**

Add tests mirroring `products-backend`'s `WildberriesApiContractTest`:
- `test_service_secret_sent_for_basic_and_service_tokens`: sets `config(['services.wildberries.client_secret' => 'service-secret'])`, tests JWTs with `acc=1` and `acc=4`, asserts `X-Client-Secret: service-secret` is present alongside `Authorization`.
- `test_service_secret_omitted_for_personal_test_and_unrecognised_tokens`: tests JWTs with `acc=3`, `acc=2`, `acc=null`, and raw non-JWT string; asserts `X-Client-Secret` is NOT sent.
- `test_empty_service_secret_omits_header`: sets `client_secret` to `null` / `''` with `acc=1`; asserts `X-Client-Secret` is NOT sent.

- [ ] **Step 2: Run `WbApiClientTest` to verify it fails**

Run: `php artisan test tests/Unit/WbApiClientTest.php`
Expected: FAIL (`X-Client-Secret` header not sent).

- [ ] **Step 3: Implement `services.wildberries` config and `WbApiClient::buildHeaders()`**

- Add `'wildberries' => ['client_secret' => env('WILDBERRIES_CLIENT_SECRET'), 'user_agent' => env('WILDBERRIES_USER_AGENT', 'wbas_sellico.ru2823')]` to `config/services.php` and `WILDBERRIES_CLIENT_SECRET=` to `.env.example`.
- In `WbApiClient::__construct()`, store `$this->clientSecret = trim((string) config('services.wildberries.client_secret', ''));`.
- In `WbApiClient::buildHeaders()`, decode JWT middle segment via `base64_decode(strtr($payload, '-_', '+/'), true)` + `json_decode(..., true)`; attach `'X-Client-Secret' => $this->clientSecret` only if `$this->clientSecret !== '' && is_array($claims) && in_array($claims['acc'] ?? null, [1, 4], true)`.

- [ ] **Step 4: Run `WbApiClientTest` to verify it passes**

Run: `php artisan test tests/Unit/WbApiClientTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/services.php .env.example app/Services/Wildberries/WbApiClient.php tests/Unit/WbApiClientTest.php
git commit -m "feat(wb): send X-Client-Secret service header for basic and service tokens"
```

---

### Task 4: Production Deployment, `WILDBERRIES_CLIENT_SECRET` Activation & Verification

**Pre-condition:** User has obtained the Sellico WB Service Secret (`WILDBERRIES_CLIENT_SECRET`) from `dev.wildberries.ru` → «Профиль компании» → «Ключи доступа» (see section below).

- [ ] **Step 1: Deploy `products-backend`**

Run from `/Volumes/SP PX10/актуальное/products-backend`:
```bash
bash -n deploy-to-server.sh scripts/prepare-deploy-source.sh scripts/deploy-runtime.sh
python3 -m unittest discover -s tests/deployment -v
bash deploy-to-server.sh
```
Expected: `Готово: приложение проверено от www-data, обработчики очередей работают.`

- [ ] **Step 2: Configure `.env` on `194.87.104.42` for BOTH services**

1. In `/var/www/products-backend/.env`:
   - Add `WILDBERRIES_CLIENT_SECRET=<SERVICE_SECRET>`
   - Add `WB_TARIFF_SCHEDULE=false` (eliminates duplicate 05:30 run alongside `/etc/cron.d/wb-refresh-tariffs`)
   - Run as `www-data`: `php artisan config:cache`
   - Restart products workers: `supervisorctl restart products-backend-worker:* products-backend-ue-worker:* products-backend-ue-enrichment-worker:* products-backend-ue-enrichment-worker-extra:* products-backend-price-refresh-worker:*`
2. In `/var/www/financial-dashboard/.env`:
   - Add `WILDBERRIES_CLIENT_SECRET=<SERVICE_SECRET>`
   - Run as `www-data`: `php artisan config:cache`
   - Restart WB sync worker: `supervisorctl restart financial-dashboard-sync-wb:*`

- [ ] **Step 3: Live verification on basic-token integrations (`#49`, `#58`, `#88`, `#105`)**

Do NOT artificially clear active WB cooldowns or mark waiting reports fresh. Verify once the current window clears (or on `stocks-report` immediately after clearing the 24h `wb:analytics:disabled:*` 403 cache key once `X-Client-Secret` is active):
```bash
# 1. Verify stocks-report no longer returns 403 for basic tokens (#105)
runuser -u www-data -- php artisan wb:refresh-stock-warehouses --integration=105

# 2. Verify queue & sync health
runuser -u www-data -- php artisan ue:sync-health --hours=24

# 3. Verify Finance & enrichment completion in logs (expect HTTP 200/204, no 43200s retry_after)
grep -E "SyncStorageFeesJob|sales-reports/detailed" /var/www/products-backend/storage/logs/laravel-$(date -u +%Y-%m-%d).log | tail -n 30
```
Expected:
- `wb:refresh-stock-warehouses --integration=105` populates warehouse stock breakdown instead of `Отчёт остатков пуст`.
- `SyncStorageFeesJob` completes for `#49`, `#58`, `#88`, `#105`, updating `wb_acquiring_observed_at` and `storage_fee_report_to`.
- `enrichment` («Комиссии и логистика») transitions to `completed` and UI banner turns green («Обязательные данные обновлены»).

- [ ] **Step 4: Seller API key hygiene (manual actions in Sellico / WB Seller Portal)**

- **Urgent (expires 2026-11-24):** Reissue and update WB API token for **#31 (`Integration #31`)**.
- **Invalid/expired tokens (`credential_health=invalid`, sync stopped):** Replace tokens for **#69 (`samyouitdir@gmail.com`)** and **#85 (`WB GM`)**, plus **#27**, **#61**, **#68** if those stores are still active.
- **Missing scopes (`403 scope is not allowed`):** Reissue token for **#103 (`Lifecode`)** with «Контент» and «Цены и скидки» permissions enabled.
- **Rotation calendar:** Set a reminder for **165 days** after issuing `WILDBERRIES_CLIENT_SECRET` (WB service secret TTL is 180 days).
