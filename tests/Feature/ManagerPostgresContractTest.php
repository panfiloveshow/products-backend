<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagerPostgresContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_timezone_preserves_utc_instants_bound_without_an_offset(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The PostgreSQL session timezone contract requires PostgreSQL.');
        }
        $instant = CarbonImmutable::parse('2026-11-10T12:34:56+00:00');
        $serialized = $instant->format(DB::connection()->getQueryGrammar()->getDateFormat());
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM CAST(? AS timestamptz)) AS instant', [$serialized]);

        $this->assertSame($instant->getTimestamp(), (int) $row->instant,
            'A UTC timestamp bound by Eloquent must not be interpreted in the server default timezone.');
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
    }
}
