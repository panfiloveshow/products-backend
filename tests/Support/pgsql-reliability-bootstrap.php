<?php

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || ! str_starts_with((string) getenv('DB_DATABASE'), 'codex_ue_')) {
    throw new RuntimeException('Refusing non-isolated PostgreSQL reliability database');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
// The schema fixture and additive migration are installed explicitly by CI.
// Historical migrate:fresh has an unrelated bigint/uuid product FK mismatch.
Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
