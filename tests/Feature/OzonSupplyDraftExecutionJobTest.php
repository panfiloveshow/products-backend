<?php

namespace Tests\Feature;

use App\Exceptions\OzonAmbiguousRemoteStateException;
use App\Exceptions\OzonPreconditionException;
use App\Jobs\ExecuteOzonSupplyDraftJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanExecution;
use App\Models\Integration;
use App\Models\Supply;
use App\Services\AutoSupplyPlanning\AutoSupplyPlanExecutionService;
use App\Services\Supply\SupplyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Money-path: единственное место, где автоплан обращается к Ozon Seller API.
 * Проверяем, что джоб не создаёт дубли, уважает лимиты и не повторяет запрос,
 * ответ на который потерян.
 */
class OzonSupplyDraftExecutionJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rate_limit_defers_draft_creation_without_calling_ozon(): void
    {
        $supply = $this->makeQueuedSupply();
        $minuteKey = 'ozon-supply-drafts-minute:' . $supply->integration_id;
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($minuteKey, 60);

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldNotReceive('createOzonDraft');

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('queued', $supply->execution_step);
        $this->assertNull($supply->ozon_draft_id);
    }

    public function test_hourly_rate_limit_also_defers_draft_creation(): void
    {
        $supply = $this->makeQueuedSupply();
        $hourKey = 'ozon-supply-drafts-hour:' . $supply->integration_id;
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit($hourKey, 3600);
        }

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldNotReceive('createOzonDraft');

        $this->runJob($supply, $supplies);

        $this->assertSame('queued', $supply->fresh()->execution_step);
    }

    public function test_successful_draft_completes_execution(): void
    {
        $supply = $this->makeQueuedSupply();

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andReturnUsing(function (Supply $target): array {
                $target->forceFill(['ozon_draft_id' => '555001'])->save();

                return ['draft_id' => '555001', 'status' => 'created'];
            });
        $supplies->shouldNotReceive('bookTimeslot');

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('draft_created', $supply->execution_step);
        $this->assertSame('555001', $supply->ozon_draft_id);
        $this->assertNull($supply->execution_error);

        $execution = $supply->autoSupplyPlanExecution->fresh();
        $this->assertSame(AutoSupplyPlanExecution::STATUS_COMPLETED, $execution->status);
        $this->assertSame(1, $execution->attempts);
        $this->assertSame(1, (int) $execution->result_json['supplies_completed']);
    }

    public function test_existing_draft_is_never_created_twice(): void
    {
        $supply = $this->makeQueuedSupply(['ozon_draft_id' => '777001']);

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldNotReceive('createOzonDraft');

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('draft_created', $supply->execution_step);
        $this->assertSame('777001', $supply->ozon_draft_id);
    }

    public function test_precondition_failure_is_marked_safe_to_retry(): void
    {
        $supply = $this->makeQueuedSupply();

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andThrow(new OzonPreconditionException('Не удалось определить числовой Ozon SKU'));

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('failed_before_call', $supply->execution_step);
        $this->assertStringContainsString('Ozon SKU', (string) $supply->execution_error);
        $this->assertSame(
            AutoSupplyPlanExecution::STATUS_FAILED,
            $supply->autoSupplyPlanExecution->fresh()->status
        );
    }

    public function test_ambiguous_remote_state_requires_manual_reconciliation(): void
    {
        $supply = $this->makeQueuedSupply();

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andThrow(new OzonAmbiguousRemoteStateException('Таймаут после отправки запроса'));

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('manual_reconciliation_required', $supply->execution_step);
        $this->assertNull($supply->ozon_draft_id);
    }

    public function test_unknown_error_after_request_is_also_treated_as_ambiguous(): void
    {
        $supply = $this->makeQueuedSupply();

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andThrow(new \RuntimeException('connection reset by peer'));

        $this->runJob($supply, $supplies);

        $this->assertSame('manual_reconciliation_required', $supply->fresh()->execution_step);
    }

    public function test_rejected_draft_is_not_retried_automatically(): void
    {
        $supply = $this->makeQueuedSupply();

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andReturn(['status' => 'failed', 'errors' => ['Товар недоступен для поставки']]);

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('remote_rejected', $supply->execution_step);
        $this->assertStringContainsString('Товар недоступен', (string) $supply->execution_error);
    }

    public function test_auto_book_timeslot_books_best_slot_after_draft(): void
    {
        $supply = $this->makeQueuedSupply();
        $supply->autoSupplyPlanExecution->update([
            'request_json' => [
                'mode' => 'draft_and_best_timeslot',
                'auto_book_timeslot' => true,
                'timeslot_preferences' => [],
            ],
        ]);

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andReturnUsing(function (Supply $target): array {
                $target->forceFill(['ozon_draft_id' => '555002'])->save();

                return ['draft_id' => '555002'];
            });
        $supplies->shouldReceive('selectBestTimeslot')
            ->once()
            ->andReturn(['slot' => ['timeslot_id' => 'slot-42'], 'score' => 100]);
        $supplies->shouldReceive('bookTimeslot')
            ->once()
            ->withArgs(fn (Supply $target, string $slotId): bool => $slotId === 'slot-42');

        $this->runJob($supply, $supplies);

        $this->assertSame('timeslot_booked', $supply->fresh()->execution_step);
    }

    public function test_auto_book_without_available_slot_keeps_draft_and_explains_why(): void
    {
        $supply = $this->makeQueuedSupply();
        $supply->autoSupplyPlanExecution->update([
            'request_json' => ['auto_book_timeslot' => true, 'timeslot_preferences' => []],
        ]);

        $supplies = \Mockery::mock(SupplyService::class);
        $supplies->shouldReceive('createOzonDraft')
            ->once()
            ->andReturnUsing(function (Supply $target): array {
                $target->forceFill(['ozon_draft_id' => '555003'])->save();

                return ['draft_id' => '555003'];
            });
        $supplies->shouldReceive('selectBestTimeslot')->once()->andReturn(null);
        $supplies->shouldNotReceive('bookTimeslot');

        $this->runJob($supply, $supplies);

        $supply->refresh();
        $this->assertSame('draft_created', $supply->execution_step);
        $this->assertStringContainsString('слот не найден', (string) $supply->execution_error);
    }

    public function test_retry_of_unknown_remote_state_requires_manual_confirmation(): void
    {
        Queue::fake();
        $supply = $this->makeQueuedSupply([
            'execution_step' => 'manual_reconciliation_required',
            'execution_error' => 'Таймаут после отправки запроса',
        ]);
        $execution = $supply->autoSupplyPlanExecution;

        try {
            app(AutoSupplyPlanExecutionService::class)->retry($execution);
            $this->fail('Повтор без ручной сверки кабинета должен быть заблокирован');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmed_no_external_draft', $e->errors());
        }

        $this->assertSame('manual_reconciliation_required', $supply->fresh()->execution_step);
        Queue::assertNothingPushed();

        app(AutoSupplyPlanExecutionService::class)->retry($execution, true);

        $this->assertSame('queued', $supply->fresh()->execution_step);
        Queue::assertPushed(ExecuteOzonSupplyDraftJob::class, 1);
    }

    public function test_retry_of_safe_failure_does_not_require_confirmation(): void
    {
        Queue::fake();
        $supply = $this->makeQueuedSupply(['execution_step' => 'failed_before_call']);

        app(AutoSupplyPlanExecutionService::class)->retry($supply->autoSupplyPlanExecution);

        $supply->refresh();
        $this->assertSame('queued', $supply->execution_step);
        $this->assertNull($supply->execution_error);
        Queue::assertPushed(ExecuteOzonSupplyDraftJob::class, 1);
    }

    private function runJob(Supply $supply, SupplyService $supplies): void
    {
        (new ExecuteOzonSupplyDraftJob($supply->id))->handle(
            $supplies,
            app(AutoSupplyPlanExecutionService::class)
        );
    }

    /**
     * @param  array<string, mixed>  $supplyOverrides
     */
    private function makeQueuedSupply(array $supplyOverrides = []): Supply
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 92,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_EXECUTING,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['supply_method' => 'direct'],
            'approval_fingerprint' => str_repeat('f', 64),
        ]);

        $execution = AutoSupplyPlanExecution::create([
            'auto_supply_plan_id' => $plan->id,
            'integration_id' => $integration->id,
            'idempotency_key' => 'job-test-' . $plan->id,
            'status' => AutoSupplyPlanExecution::STATUS_RUNNING,
            'plan_fingerprint' => $plan->approval_fingerprint,
            'request_json' => ['auto_book_timeslot' => false, 'timeslot_preferences' => []],
            'confirmed_at' => now(),
            'started_at' => now(),
        ]);

        return Supply::create(array_merge([
            'integration_id' => $integration->id,
            'auto_supply_plan_id' => $plan->id,
            'auto_supply_plan_execution_id' => $execution->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'warehouse_id' => '1001',
            'status' => Supply::STATUS_DRAFT,
            'execution_step' => 'queued',
        ], $supplyOverrides));
    }
}
