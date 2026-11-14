<?php

namespace Tests\Feature;

use App\Models\Integration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationCredentialStorageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_fresh_schema_roundtrips_encrypted_credentials_in_text_storage(): void
    {
        $this->assertSame('text', Schema::getColumnType('integrations', 'credentials', true));
        $credentials = ['client_id' => '123456', 'api_key' => 'synthetic-only-credential'];
        $integration = Integration::factory()->ozon()->create(['id' => 73903, 'credentials' => $credentials]);
        $ciphertext = DB::table('integrations')->where('id', $integration->id)->value('credentials');

        $this->assertIsString($ciphertext);
        $this->assertNotSame(json_encode($credentials), $ciphertext);
        $this->assertStringNotContainsString($credentials['api_key'], $ciphertext);
        $this->assertSame($credentials, $integration->fresh()->credentials);
    }
}
