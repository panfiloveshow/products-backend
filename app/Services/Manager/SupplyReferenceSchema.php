<?php

namespace App\Services\Manager;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Metadata only: never converts identifiers or reads business rows. */
final class SupplyReferenceSchema
{
    private const REFERENCES = [
        ['supply_recommendations', 'product_id', 'products', 'cascade'],
        ['supply_items', 'product_id', 'products', 'set null'],
        ['supply_recommendations', 'supply_plan_id', 'supply_plans', 'set null'],
        ['supplies', 'supply_plan_id', 'supply_plans', 'set null'],
    ];

    public function inspect(?Connection $connection = null): array
    {
        try {
            $connection ??= DB::connection();
            $driver = $connection->getDriverName();
            if (! in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
                return $this->report('unsupported_driver', [], ['unsupported_driver']);
            }

            $schema = $connection->getSchemaBuilder();
            $tables = [];
            foreach (self::REFERENCES as [$table, , $parent]) {
                foreach ([$table, $parent] as $name) {
                    if (! array_key_exists($name, $tables)) {
                        $exists = $schema->hasTable($name);
                        $tables[$name] = [
                            'columns' => $exists ? array_column($schema->getColumns($name), null, 'name') : [],
                            'foreign_keys' => $exists ? $schema->getForeignKeys($name) : [],
                            'indexes' => $exists ? $schema->getIndexes($name) : [],
                        ];
                    }
                }
            }

            $issues = [];
            if ($driver === 'sqlite' && (int) ($connection->selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0) !== 1) {
                $issues[] = 'foreign_key_enforcement_disabled';
            }
            $checks = [];
            foreach (self::REFERENCES as [$table, $column, $parent, $delete]) {
                $child = $tables[$table]['columns'][$column] ?? null;
                $target = $tables[$parent]['columns']['id'] ?? null;
                $failures = [];
                if ($child === null) {
                    $failures[] = 'reference_column_missing';
                }
                if ($target === null) {
                    $failures[] = 'parent_column_missing';
                }
                if ($target !== null && ! $this->supportsUuid($driver, $target['type'])) {
                    $failures[] = 'parent_uuid_storage_incompatible';
                }
                if ($child !== null && $target !== null && strtolower($child['type']) !== strtolower($target['type'])) {
                    $failures[] = 'reference_type_mismatch';
                }
                if ($child !== null && ! $child['nullable']) {
                    $failures[] = 'reference_must_be_nullable';
                }
                if (! collect($tables[$parent]['indexes'])->contains(fn (array $index): bool => $index['primary'] && $index['columns'] === ['id'])) {
                    $failures[] = 'parent_primary_key_mismatch';
                }

                $foreignKeys = array_values(array_filter($tables[$table]['foreign_keys'], fn (array $key): bool => in_array($column, $key['columns'], true)));
                if (count($foreignKeys) !== 1) {
                    $failures[] = 'reference_foreign_key_missing_or_ambiguous';
                } else {
                    $key = $foreignKeys[0];
                    if ($key['columns'] !== [$column] || $key['foreign_table'] !== $connection->getTablePrefix().$parent
                        || $key['foreign_columns'] !== ['id'] || ! $this->sameNamespace($connection, $driver, $parent, $key['foreign_schema'])) {
                        $failures[] = 'reference_foreign_key_target_mismatch';
                    }
                    if ($key['on_delete'] !== $delete) {
                        $failures[] = 'reference_delete_policy_mismatch';
                    }
                }
                $checks[] = ['table' => $table, 'column' => $column, 'parent' => $parent.'.id', 'issues' => $failures];
            }

            $compatible = $issues === [] && ! collect($checks)->contains(fn (array $check): bool => $check['issues'] !== []);

            return $this->report($compatible ? 'schema_compatible' : 'schema_incompatible', $checks, $issues);
        } catch (Throwable) {
            // Connection/SQL errors can contain credentials, paths and private database names.
            return $this->report('unavailable', [], ['schema_metadata_unavailable']);
        }
    }

    private function supportsUuid(string $driver, string $type): bool
    {
        $type = strtolower($type);

        return match ($driver) {
            'pgsql' => $type === 'uuid',
            'sqlite' => in_array($type, ['varchar', 'text', 'char'], true),
            default => preg_match('/^(?:var)?char\(36\)$/', $type) === 1,
        };
    }

    private function sameNamespace(Connection $connection, string $driver, string $parent, ?string $foreignSchema): bool
    {
        if ($driver === 'pgsql') {
            $quoted = '"'.str_replace('"', '""', $connection->getTablePrefix().$parent).'"';
            $namespace = $connection->selectOne('SELECT n.nspname AS schema_name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)', [$quoted]);

            return $namespace !== null && $namespace->schema_name === $foreignSchema;
        }

        return $driver === 'sqlite' || $foreignSchema === $connection->getDatabaseName();
    }

    private function report(string $status, array $checks, array $issues): array
    {
        return ['status' => $status, 'read_only' => true, 'data_checked' => false, 'checks' => $checks, 'issues' => $issues];
    }
}
