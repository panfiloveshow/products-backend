<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Модель для хранения истории изменений
 * 
 * @property string $id
 * @property string $auditable_type
 * @property string $auditable_id
 * @property string $event
 * @property array $old_values
 * @property array $new_values
 * @property string|null $user_id
 * @property string|null $user_name
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class AuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'audit_logs';
    protected $keyType = 'string';
    public $incrementing = false;

    const UPDATED_AT = null; // Не нужен updated_at

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'user_id',
        'user_name',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Связь с аудируемой моделью
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope для фильтрации по типу модели
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('auditable_type', $modelClass);
    }

    /**
     * Scope для фильтрации по событию
     */
    public function scopeEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    /**
     * Scope для фильтрации по пользователю
     */
    public function scopeByUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Получить описание изменений
     */
    public function getChangesDescription(): string
    {
        if ($this->event === 'created') {
            return 'Создано';
        }

        if ($this->event === 'deleted') {
            return 'Удалено';
        }

        $changes = [];
        foreach ($this->new_values as $field => $newValue) {
            $oldValue = $this->old_values[$field] ?? 'null';
            $changes[] = "{$field}: {$oldValue} → {$newValue}";
        }

        return implode(', ', $changes);
    }
}
