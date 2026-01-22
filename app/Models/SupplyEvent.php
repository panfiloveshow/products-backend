<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель события поставки (audit trail)
 */
class SupplyEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const TYPE_CREATED = 'created';
    public const TYPE_UPDATED = 'updated';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_DRAFT_CREATED = 'draft_created';
    public const TYPE_SLOT_REQUESTED = 'slot_requested';
    public const TYPE_SLOT_BOOKED = 'slot_booked';
    public const TYPE_SLOT_CHANGED = 'slot_changed';
    public const TYPE_SLOT_CANCELLED = 'slot_cancelled';
    public const TYPE_ITEM_ADDED = 'item_added';
    public const TYPE_ITEM_REMOVED = 'item_removed';
    public const TYPE_ITEM_QTY_CHANGED = 'item_qty_changed';
    public const TYPE_SHIPPED = 'shipped';
    public const TYPE_ACCEPTED = 'accepted';
    public const TYPE_REJECTED = 'rejected';
    public const TYPE_ERROR = 'error';
    public const TYPE_API_REQUEST = 'api_request';
    public const TYPE_API_RESPONSE = 'api_response';
    public const TYPE_NOTIFICATION_SENT = 'notification_sent';
    public const TYPE_COMMENT_ADDED = 'comment_added';
    public const TYPE_DOCUMENT_GENERATED = 'document_generated';

    public const INITIATED_BY_USER = 'user';
    public const INITIATED_BY_SYSTEM = 'system';
    public const INITIATED_BY_API = 'api';
    public const INITIATED_BY_SCHEDULER = 'scheduler';

    protected $fillable = [
        'supply_id',
        'event_type',
        'title',
        'description',
        'old_value',
        'new_value',
        'changes',
        'api_method',
        'api_endpoint',
        'api_request_body',
        'api_response_body',
        'api_response_code',
        'api_duration_ms',
        'error_code',
        'error_message',
        'error_context',
        'is_critical',
        'is_resolved',
        'resolved_at',
        'initiated_by',
        'user_id',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'api_request_body' => 'array',
        'api_response_body' => 'array',
        'api_response_code' => 'integer',
        'api_duration_ms' => 'integer',
        'error_context' => 'array',
        'is_critical' => 'boolean',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // === Relationships ===

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // === Scopes ===

    public function scopeErrors($query)
    {
        return $query->where('event_type', self::TYPE_ERROR);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_critical', true)->where('is_resolved', false);
    }

    public function scopeApiCalls($query)
    {
        return $query->whereIn('event_type', [self::TYPE_API_REQUEST, self::TYPE_API_RESPONSE]);
    }

    public function scopeStatusChanges($query)
    {
        return $query->where('event_type', self::TYPE_STATUS_CHANGED);
    }

    // === Helpers ===

    public function resolve(): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }

    public function getEventLabelAttribute(): string
    {
        return match ($this->event_type) {
            self::TYPE_CREATED => 'Создана',
            self::TYPE_UPDATED => 'Обновлена',
            self::TYPE_STATUS_CHANGED => 'Смена статуса',
            self::TYPE_DRAFT_CREATED => 'Черновик создан в Ozon',
            self::TYPE_SLOT_REQUESTED => 'Запрошены слоты',
            self::TYPE_SLOT_BOOKED => 'Слот забронирован',
            self::TYPE_SLOT_CHANGED => 'Слот изменён',
            self::TYPE_SLOT_CANCELLED => 'Слот отменён',
            self::TYPE_ITEM_ADDED => 'Добавлена позиция',
            self::TYPE_ITEM_REMOVED => 'Удалена позиция',
            self::TYPE_ITEM_QTY_CHANGED => 'Изменено количество',
            self::TYPE_SHIPPED => 'Отгружено',
            self::TYPE_ACCEPTED => 'Принято',
            self::TYPE_REJECTED => 'Отклонено',
            self::TYPE_ERROR => 'Ошибка',
            self::TYPE_API_REQUEST => 'API запрос',
            self::TYPE_API_RESPONSE => 'API ответ',
            self::TYPE_NOTIFICATION_SENT => 'Уведомление отправлено',
            self::TYPE_COMMENT_ADDED => 'Добавлен комментарий',
            self::TYPE_DOCUMENT_GENERATED => 'Документ сгенерирован',
            default => $this->event_type,
        };
    }
}
