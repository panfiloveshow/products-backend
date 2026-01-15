<?php

namespace App\Models\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Трейт для автоматического логирования изменений модели
 * 
 * Использование:
 * 1. Добавить `use HasAuditLog;` в модель
 * 2. Опционально переопределить $auditableFields для ограничения полей
 * 
 * @example
 * class Shipment extends Model
 * {
 *     use HasAuditLog;
 *     
 *     protected array $auditableFields = ['status', 'total_cost'];
 * }
 */
trait HasAuditLog
{
    /**
     * Boot трейта
     */
    public static function bootHasAuditLog(): void
    {
        // Логирование создания
        static::created(function (Model $model) {
            $model->logAuditEvent('created', [], $model->getAttributes());
        });

        // Логирование обновления
        static::updating(function (Model $model) {
            $changes = $model->getDirty();
            
            if (empty($changes)) {
                return;
            }

            // Фильтруем поля если указаны auditableFields
            if (property_exists($model, 'auditableFields') && !empty($model->auditableFields)) {
                $changes = array_intersect_key($changes, array_flip($model->auditableFields));
            }

            if (empty($changes)) {
                return;
            }

            $oldValues = array_intersect_key($model->getOriginal(), $changes);

            $model->logAuditEvent('updated', $oldValues, $changes);
        });

        // Логирование удаления
        static::deleted(function (Model $model) {
            $model->logAuditEvent('deleted', $model->getOriginal(), []);
        });
    }

    /**
     * Записать событие аудита
     */
    protected function logAuditEvent(string $event, array $oldValues, array $newValues): void
    {
        try {
            AuditLog::create([
                'auditable_type' => get_class($this),
                'auditable_id' => $this->getKey(),
                'event' => $event,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Не прерываем основную операцию при ошибке логирования
            \Illuminate\Support\Facades\Log::warning('Failed to create audit log', [
                'model' => get_class($this),
                'id' => $this->getKey(),
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Получить историю изменений модели
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Получить последние N записей аудита
     */
    public function getRecentAuditLogs(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->auditLogs()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
