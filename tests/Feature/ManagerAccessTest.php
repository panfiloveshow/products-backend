<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckManagerAccess;
use App\Services\Manager\ManagerIdentity;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        Route::get('/manager-access-test', function (Request $request) {
            $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);

            return response()->json(['workspace' => CurrentWorkspace::id($request), 'user' => $identity->userId]);
        })->middleware(CheckManagerAccess::class.':products.view');
    }

    private function remote(array $membership = ['user_id' => 42, 'permissions' => [['slug' => 'products.view']]]): void
    {
        Http::fake(['crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]), 'crm.test/api/workspaces/8/user-permissions' => Http::response($membership)]);
    }

    public function test_it_binds_canonical_identity_and_ignores_spoofed_user_headers(): void
    {
        $this->remote();
        $this->withHeaders(['Authorization' => 'Bearer real-user-session', 'X-Sellico-Workspace' => '8', 'X-User-Id' => '999', 'X-Sellico-User' => '999'])
            ->getJson('/manager-access-test')->assertOk()->assertExactJson(['workspace' => 8, 'user' => 42]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/user') && $r->hasHeader('Authorization', 'Bearer real-user-session') && ! $r->hasHeader('X-User-Id'));
    }

    public function test_no_development_bypass_or_body_identity_is_accepted(): void
    {
        $this->getJson('/manager-access-test?workspace=8&user_id=42&permissions[]=products.view')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_membership_for_another_identity_is_rejected(): void
    {
        $this->remote(['user_id' => 999, 'permissions' => ['products.view']]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertStatus(503);
    }

    public function test_a_valid_session_without_the_operation_permission_cannot_proceed(): void
    {
        $this->remote(['user_id' => 42, 'role' => 'admin', 'permissions' => []]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertForbidden();
    }

    public function test_a_service_account_cannot_replace_a_personal_session(): void
    {
        Http::fake(['crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => true])]);
        $this->withToken('service-session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertForbidden();
        Http::assertSentCount(1);
    }

    public static function incompleteProfiles(): array
    {
        return [
            'missing' => [['id' => 42]],
            'null' => [['id' => 42, 'is_service_account' => null]],
            'number' => [['id' => 42, 'is_service_account' => 0]],
            'string' => [['id' => 42, 'is_service_account' => 'false']],
        ];
    }

    #[DataProvider('incompleteProfiles')]
    public function test_an_incomplete_profile_never_enters_the_action_handler(array $profile): void
    {
        Http::fake(['crm.test/api/user' => Http::response($profile),
            'crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => ['products.view']])]);
        $request = Request::create('/manager-access-test', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer session', 'HTTP_X_SELLICO_WORKSPACE' => '8']);
        $entered = false;
        $response = (new CheckManagerAccess)->handle($request, function () use (&$entered) {
            $entered = true;

            return response()->json(['changed' => true]);
        }, 'products.view');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($entered);
        $this->assertNull(CurrentWorkspace::id($request));
        $this->assertNull($request->attributes->get(ManagerIdentity::ATTRIBUTE));
        Http::assertSentCount(1);
    }

    public static function malformedPermissions(): array
    {
        return [
            'object' => [['grant' => 'products.view']],
            'null item' => [['products.view', null]],
            'missing slug' => [['products.view', ['role' => 'admin']]],
            'numeric slug' => [['products.view', ['slug' => 7]]],
            'empty slug' => [['products.view', '']],
            'padded slug' => [['products.view', ' seo.push.execute ']],
        ];
    }

    #[DataProvider('malformedPermissions')]
    public function test_a_valid_permission_does_not_hide_malformed_membership(array $permissions): void
    {
        $this->remote(['user_id' => 42, 'permissions' => $permissions]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertStatus(503);
    }

    public function test_an_earlier_verified_profile_does_not_cover_an_incomplete_fresh_profile(): void
    {
        Http::fake([
            'crm.test/api/user' => Http::sequence()->push(['id' => 42, 'is_service_account' => false])->push(['id' => 42]),
            'crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => ['products.view']]),
        ]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertOk();
        $this->getJson('/manager-access-test')->assertStatus(503);
        Http::assertSentCount(3);
    }

    public function test_membership_metadata_for_a_different_workspace_is_rejected(): void
    {
        $this->remote(['user_id' => 42, 'workspace_id' => 9, 'permissions' => ['products.view']]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertStatus(503);
    }

    public function test_unavailable_crm_and_revoked_tokens_fail_closed_without_retry(): void
    {
        Http::fake(['*' => Http::response(['private' => 'not-forwarded'], 503)]);
        $this->withToken('session')->withHeader('X-Sellico-Workspace', '8')->getJson('/manager-access-test')->assertStatus(503)->assertJsonMissing(['private' => 'not-forwarded']);
        Http::assertSentCount(1);
    }

    public function test_identity_context_is_removed_when_a_handler_throws(): void
    {
        $this->remote();
        $request = Request::create('/manager-access-test', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer session', 'HTTP_X_SELLICO_WORKSPACE' => '8']);
        try {
            (new CheckManagerAccess)->handle($request, function () {
                throw new \RuntimeException('handler failed');
            }, 'products.view');
            $this->fail('Handler must throw');
        } catch (\RuntimeException $error) {
            $this->assertSame('handler failed', $error->getMessage());
        }
        $this->assertNull(CurrentWorkspace::id($request));
        $this->assertNull($request->attributes->get(ManagerIdentity::ATTRIBUTE));
    }
}
