# Unit-economics PostgreSQL fixture

`unit-economics-pg16-schema.sql` contains schema only, without rows, owners, privileges or credentials. It was captured from the existing PostgreSQL 16 schema before the reliability migration. CI restores it into a disposable `codex_ue_*` database, applies `2026_11_02_130000_create_unit_economics_sync_runs.php`, then runs actual PostgreSQL queue, version, transaction and calculation tests.

This bounded fixture is necessary because the historical fresh migrations contain an unrelated `supply_recommendations.product_id` bigint foreign key targeting `products.id` uuid. It does not certify historical migrations or replace them for normal deployments. To refresh the fixture, generate a schema-only dump in a private test environment, remove pg_dump restrict/unrestrict session markers for client compatibility, and verify it contains no COPY/INSERT data sections. Preserve the guard in `tests/Support/pgsql-reliability-bootstrap.php`.
