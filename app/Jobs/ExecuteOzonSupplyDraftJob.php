<?php

namespace App\Jobs;

use App\Exceptions\OzonAmbiguousRemoteStateException;
use App\Exceptions\OzonPreconditionException;
use App\Models\Supply;
use App\Services\AutoSupplyPlanning\AutoSupplyPlanExecutionService;
use App\Services\Supply\SupplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;

class ExecuteOzonSupplyDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;
    public int $timeout = 180;

    public function __construct(public int $supplyId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ozon-supply-draft:' . $this->supplyId))
                ->expireAfter(240),
        ];
    }

    public function handle(
        SupplyService $supplies,
        AutoSupplyPlanExecutionService $executions
    ): void {
        $supply = Supply::withoutGlobalScopes()
            ->with(['integration', 'items.product', 'autoSupplyPlanExecution'])
            ->find($this->supplyId);

        if ($supply === null || $supply->autoSupplyPlanExecution === null) {
            return;
        }

        if ($supply->ozon_draft_id) {
            $supply->update(['execution_step' => 'draft_created', 'execution_error' => null]);
            $executions->refreshStatus($supply->autoSupplyPlanExecution);
            return;
        }

        $minuteRateKey = 'ozon-supply-drafts-minute:' . $supply->integration_id;
        $hourRateKey = 'ozon-supply-drafts-hour:' . $supply->integration_id;
        if (
            RateLimiter::tooManyAttempts($minuteRateKey, 2)
            || RateLimiter::tooManyAttempts($hourRateKey, 50)
        ) {
            $this->release(max(
                30,
                RateLimiter::availableIn($minuteRateKey),
                RateLimiter::availableIn($hourRateKey)
            ));
            return;
        }

        RateLimiter::hit($minuteRateKey, 60);
        RateLimiter::hit($hourRateKey, 3600);
        $supply->update([
            'execution_step' => 'creating_draft',
            'execution_error' => null,
        ]);
        $supply->autoSupplyPlanExecution->increment('attempts');

        try {
            $result = $supplies->createOzonDraft($supply);
            $supply->refresh();
            if (! $supply->ozon_draft_id) {
                if (($result['status'] ?? null) === 'failed') {
                    $supply->update([
                        'execution_step' => 'remote_rejected',
                        'execution_error' => implode('; ', array_filter((array) ($result['errors'] ?? [])))
                            ?: 'Ozon отклонил расчёт черновика.',
                    ]);

                    return;
                }
                $supply->update([
                    'execution_step' => 'waiting_draft',
                    'execution_error' => null,
                ]);
                $this->release(15);
                return;
            }
            $request = $supply->autoSupplyPlanExecution->request_json ?? [];

            if ((bool) ($request['auto_book_timeslot'] ?? false)) {
                $supply->update(['execution_step' => 'selecting_timeslot']);
                $best = $supplies->selectBestTimeslot(
                    $supply,
                    is_array($request['timeslot_preferences'] ?? null)
                        ? $request['timeslot_preferences']
                        : []
                );
                $slotId = $best['slot']['timeslot_id']
                    ?? $best['slot']['id']
                    ?? $best['slot']['external_slot_id']
                    ?? null;
                if ($slotId === null) {
                    $supply->update([
                        'execution_step' => 'draft_created',
                        'execution_error' => 'Черновик создан, но доступный слот не найден.',
                    ]);
                } else {
                    $supplies->bookTimeslot($supply, (string) $slotId);
                    $supply->update([
                        'execution_step' => 'timeslot_booked',
                        'execution_error' => null,
                    ]);
                }
            } else {
                $supply->update([
                    'execution_step' => 'draft_created',
                    'execution_error' => null,
                ]);
            }
        } catch (OzonPreconditionException $e) {
            $supply->update([
                'execution_step' => 'failed_before_call',
                'execution_error' => $e->getMessage(),
            ]);
        } catch (OzonAmbiguousRemoteStateException $e) {
            // После отправки HTTP-запроса нельзя автоматически повторять операцию:
            // ответ мог потеряться, хотя Ozon уже создал черновик.
            $supply->update([
                'execution_step' => 'manual_reconciliation_required',
                'execution_error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // Неизвестное исключение после входа в adapter также считаем
            // неоднозначным: безопасность от дублей важнее автоматического retry.
            $supply->update([
                'execution_step' => 'manual_reconciliation_required',
                'execution_error' => $e->getMessage(),
            ]);
        } finally {
            $executions->refreshStatus($supply->autoSupplyPlanExecution->fresh());
        }
    }
}
