<?php

namespace App\Services\AutoSupplyPlanning;

use App\Jobs\ExecuteOzonSupplyDraftJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanExecution;
use App\Models\Supply;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutoSupplyPlanExecutionService
{
    public const CONFIRMATION_PHRASE = 'СОЗДАТЬ ПОСТАВКИ OZON';

    public function __construct(
        private OzonPlanMaterializationService $materialization
    ) {}

    /**
     * @param array<string, mixed> $options
     * @return array{execution: AutoSupplyPlanExecution, created: bool}
     */
    public function start(
        AutoSupplyPlan $plan,
        string $idempotencyKey,
        string $confirmation,
        ?int $userId,
        array $options = []
    ): array {
        if (! hash_equals(self::CONFIRMATION_PHRASE, trim($confirmation))) {
            throw ValidationException::withMessages([
                'confirmation_text' => ['Введите точную фразу подтверждения.'],
            ]);
        }

        $existing = AutoSupplyPlanExecution::query()
            ->where('auto_supply_plan_id', $plan->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->plan_fingerprint, (string) $plan->approval_fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Этот ключ уже использован для другой версии плана.'],
                ]);
            }

            return ['execution' => $existing->load('supplies.items'), 'created' => false];
        }

        $check = $this->materialization->approvalCheck($plan);
        if (! $check['allowed']) {
            throw ValidationException::withMessages([
                'plan' => collect($check['errors'])->pluck('message')->all(),
            ]);
        }
        if (
            $plan->business_status !== AutoSupplyPlan::BUSINESS_STATUS_APPROVED
            || ! $plan->approval_fingerprint
            || ! hash_equals((string) $plan->approval_fingerprint, (string) $check['fingerprint'])
        ) {
            throw ValidationException::withMessages([
                'plan' => ['План должен быть проверен и утверждён в текущей версии.'],
            ]);
        }

        $created = false;
        try {
            $execution = DB::transaction(function () use (
                $plan,
                $idempotencyKey,
                $userId,
                $options,
                &$created
            ): AutoSupplyPlanExecution {
                $existing = AutoSupplyPlanExecution::query()
                    ->where('auto_supply_plan_id', $plan->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->plan_fingerprint, (string) $plan->approval_fingerprint)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Этот ключ уже использован для другой версии плана.'],
                        ]);
                    }

                    return $existing;
                }

                $created = true;

                return AutoSupplyPlanExecution::query()->create([
                    'auto_supply_plan_id' => $plan->id,
                    'integration_id' => $plan->integration_id,
                    'idempotency_key' => $idempotencyKey,
                    'status' => AutoSupplyPlanExecution::STATUS_PENDING,
                    'plan_fingerprint' => $plan->approval_fingerprint,
                    'request_json' => [
                        'mode' => ($options['auto_book_timeslot'] ?? false)
                            ? 'draft_and_best_timeslot'
                            : 'draft_only',
                        'auto_book_timeslot' => (bool) ($options['auto_book_timeslot'] ?? false),
                        'timeslot_preferences' => $options['timeslot_preferences'] ?? [],
                    ],
                    'initiated_by' => $userId,
                    'confirmed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Два одновременных HTTP-запроса могут оба не увидеть ещё не
            // закоммиченную строку. Уникальный индекс решает гонку, а второй
            // запрос получает уже существующее исполнение.
            $created = false;
            $execution = AutoSupplyPlanExecution::query()
                ->where('auto_supply_plan_id', $plan->id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            if (! hash_equals((string) $execution->plan_fingerprint, (string) $plan->approval_fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Этот ключ уже использован для другой версии плана.'],
                ]);
            }
        }

        if (! $created) {
            return ['execution' => $execution->load('supplies.items'), 'created' => false];
        }

        try {
            $materialized = $this->materialization->materialize($plan, $userId);
            $supplyIds = $materialized['supplies']->pluck('id')->all();

            Supply::query()
                ->whereIn('id', $supplyIds)
                ->update([
                    'auto_supply_plan_execution_id' => $execution->id,
                    'execution_step' => 'queued',
                    'execution_error' => null,
                ]);

            $execution->update([
                'status' => AutoSupplyPlanExecution::STATUS_RUNNING,
                'started_at' => now(),
                'result_json' => [
                    'supplies_total' => count($supplyIds),
                    'supplies_queued' => count($supplyIds),
                ],
            ]);
            $plan->update([
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_EXECUTING,
                'execution_started_at' => now(),
                'execution_completed_at' => null,
                'last_execution_error' => null,
            ]);

            foreach ($supplyIds as $supplyId) {
                ExecuteOzonSupplyDraftJob::dispatch((int) $supplyId)
                    ->onQueue((string) config('autoplanning.execution.queue'))
                    ->afterCommit();
            }
        } catch (\Throwable $e) {
            $execution->update([
                'status' => AutoSupplyPlanExecution::STATUS_FAILED,
                'error_json' => ['message' => $e->getMessage(), 'stage' => 'materialization'],
                'finished_at' => now(),
            ]);
            $plan->update([
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_EXECUTION_FAILED,
                'last_execution_error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['execution' => $execution->fresh()->load('supplies.items'), 'created' => true];
    }

    public function retry(
        AutoSupplyPlanExecution $execution,
        bool $confirmedNoExternalDraft = false
    ): AutoSupplyPlanExecution {
        $supplies = $execution->supplies()
            ->whereIn('execution_step', ['failed_before_call', 'remote_rejected', 'manual_reconciliation_required'])
            ->get();

        foreach ($supplies as $supply) {
            if (
                $supply->execution_step === 'manual_reconciliation_required'
                && ! $supply->ozon_draft_id
                && ! $confirmedNoExternalDraft
            ) {
                throw ValidationException::withMessages([
                    'confirmed_no_external_draft' => [
                        'Сначала проверьте кабинет Ozon и подтвердите, что черновик не был создан.',
                    ],
                ]);
            }

            $supply->update([
                'execution_step' => 'queued',
                'execution_error' => null,
            ]);
            ExecuteOzonSupplyDraftJob::dispatch($supply->id)
                ->onQueue((string) config('autoplanning.execution.queue'))
                ->afterCommit();
        }

        if ($supplies->isNotEmpty()) {
            $execution->update([
                'status' => AutoSupplyPlanExecution::STATUS_RUNNING,
                'finished_at' => null,
                'error_json' => null,
            ]);
        }

        return $execution->fresh()->load('supplies.items');
    }

    public function refreshStatus(AutoSupplyPlanExecution $execution): AutoSupplyPlanExecution
    {
        $supplies = $execution->supplies()->get();
        $total = $supplies->count();
        $completed = $supplies->whereIn('execution_step', ['draft_created', 'timeslot_booked'])->count();
        $failed = $supplies->whereIn('execution_step', [
            'failed_before_call',
            'remote_rejected',
            'manual_reconciliation_required',
        ])->count();
        $running = max(0, $total - $completed - $failed);

        $status = match (true) {
            $total > 0 && $completed === $total => AutoSupplyPlanExecution::STATUS_COMPLETED,
            $total > 0 && $failed === $total => AutoSupplyPlanExecution::STATUS_FAILED,
            $failed > 0 && $running === 0 => AutoSupplyPlanExecution::STATUS_PARTIAL,
            default => AutoSupplyPlanExecution::STATUS_RUNNING,
        };

        $execution->update([
            'status' => $status,
            'result_json' => [
                'supplies_total' => $total,
                'supplies_completed' => $completed,
                'supplies_failed' => $failed,
                'supplies_running' => $running,
            ],
            'finished_at' => in_array($status, [
                AutoSupplyPlanExecution::STATUS_COMPLETED,
                AutoSupplyPlanExecution::STATUS_FAILED,
                AutoSupplyPlanExecution::STATUS_PARTIAL,
            ], true) ? now() : null,
        ]);

        $plan = $execution->plan;
        if ($status === AutoSupplyPlanExecution::STATUS_COMPLETED) {
            $plan?->update([
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_EXECUTING,
                'execution_completed_at' => now(),
                'last_execution_error' => null,
            ]);
        } elseif ($failed > 0) {
            $plan?->update([
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_EXECUTION_FAILED,
                'last_execution_error' => 'Не все группы поставки созданы в Ozon.',
            ]);
        }

        return $execution->fresh()->load('supplies.items');
    }
}
