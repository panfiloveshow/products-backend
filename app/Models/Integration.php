<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasFactory;

    // Отключаем auto-increment чтобы использовать ID из Sellico
    public $incrementing = false;

    protected $fillable = [
        'id', // Важно! ID из Sellico
        'work_space_id',
        'name',
        'marketplace',
        'credentials',
        'is_active',
        'auto_sync_enabled',
        'sync_interval_hours',
        'last_sync_at',
        'last_sync_status',
        'last_sync_error',
        'settings',
        'is_premium',
        'non_premium_detection_count',
        'premium_checked_at',
        'manual_redemption_rate',
        'localization_index',
        'localization_checked_at',
        'redemption_checked_at',
        'last_validation_at',
        'last_validation_status',
        'last_validation_error',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'auto_sync_enabled' => 'boolean',
        'last_sync_at' => 'datetime',
        'is_premium' => 'boolean',
        'non_premium_detection_count' => 'integer',
        'premium_checked_at' => 'datetime',
        'manual_redemption_rate' => 'decimal:2',
        'localization_index' => 'decimal:2',
        'localization_checked_at' => 'datetime',
        'redemption_checked_at' => 'datetime',
        'last_validation_at' => 'datetime',
    ];

    const CACHE_TTL_HOURS = 24;

    protected $hidden = [
        'credentials', // Не показываем credentials в API ответах
    ];

    /**
     * Товары этой интеграции
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'integration_id');
    }

    /**
     * Логи синхронизаций этой интеграции
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'integration_id');
    }

    /**
     * Scope: по workspace
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('work_space_id', $workspaceId);
    }

    /**
     * Scope: только активные интеграции
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: с включённой автосинхронизацией
     */
    public function scopeAutoSyncEnabled($query)
    {
        return $query->where('auto_sync_enabled', true);
    }

    /**
     * Scope: по маркетплейсу
     */
    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    /**
     * Проверяет, нужна ли синхронизация (прошло больше sync_interval_hours)
     */
    public function needsSync(): bool
    {
        if (!$this->is_active || !$this->auto_sync_enabled) {
            return false;
        }

        if (!$this->last_sync_at) {
            return true;
        }

        return $this->last_sync_at->addHours($this->sync_interval_hours)->isPast();
    }

    /**
     * Обновляет статус последней синхронизации
     */
    public function updateSyncStatus(string $status, ?string $error = null): void
    {
        $this->update([
            'last_sync_at' => now(),
            'last_sync_status' => $status,
            'last_sync_error' => $error,
        ]);
    }

    /**
     * Получить credentials для API (расшифрованные)
     */
    public function getDecryptedCredentials(): array
    {
        return $this->credentials ?? [];
    }

    /** @var array<string,mixed>|null Мемоизация резолва креды (в т.ч. Sellico-фолбэк) */
    private ?array $resolvedCredentialsMemo = null;

    /**
     * Резолв credentials с фолбэком в Sellico.
     *
     * У части интеграций api_key хранится не локально, а в Sellico (ID совпадают —
     * incrementing=false, "ID из Sellico"). Локальные пути синка (товары/остатки)
     * уже умеют фолбэк, а вот синк тариф-снапшотов (КС) и расчёт ИЛ брали только
     * локальный ключ → для Sellico-интеграций получали пусто (КС=100%, ИЛ=1).
     * Этот метод даёт единый резолв: локальный ключ → если пусто, тянем из Sellico.
     * Результат мемоизируется на инстансе (один HTTP-запрос за время жизни модели).
     *
     * @return array<string,mixed>
     */
    public function resolveCredentials(): array
    {
        if ($this->resolvedCredentialsMemo !== null) {
            return $this->resolvedCredentialsMemo;
        }

        $local = $this->credentials ?? [];
        if (! empty($local['api_key'])) {
            return $this->resolvedCredentialsMemo = $local;
        }

        try {
            $sellico = app(\App\Services\SellicoApiService::class)->getIntegrationById((int) $this->id);
            if (($sellico['success'] ?? false) && ! empty($sellico['credentials']['api_key'])) {
                return $this->resolvedCredentialsMemo = array_merge($local, $sellico['credentials']);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Integration::resolveCredentials sellico fallback failed', [
                'integration_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->resolvedCredentialsMemo = $local;
    }

    /**
     * Количество товаров
     */
    public function getProductsCountAttribute(): int
    {
        return $this->products()->count();
    }

    /**
     * Последняя успешная синхронизация
     */
    public function getLastSuccessfulSyncAttribute(): ?SyncLog
    {
        return $this->syncLogs()
            ->where('status', 'completed')
            ->latest()
            ->first();
    }

    /**
     * Проверяет, нужно ли обновить данные о премиум-статусе (TTL 24ч)
     */
    public function needsPremiumCheck(): bool
    {
        if (!$this->premium_checked_at) {
            return true;
        }
        return $this->premium_checked_at->addHours(self::CACHE_TTL_HOURS)->isPast();
    }

    /**
     * Проверяет, нужно ли обновить индекс локализации (TTL 24ч)
     */
    public function needsLocalizationCheck(): bool
    {
        if (!$this->localization_checked_at) {
            return true;
        }
        return $this->localization_checked_at->addHours(self::CACHE_TTL_HOURS)->isPast();
    }

    /**
     * Проверяет, нужно ли обновить данные о выкупе (TTL 24ч)
     */
    public function needsRedemptionCheck(): bool
    {
        if (!$this->redemption_checked_at) {
            return true;
        }
        return $this->redemption_checked_at->addHours(self::CACHE_TTL_HOURS)->isPast();
    }

    /**
     * Обновляет timestamp проверки премиум-статуса
     */
    public function markPremiumChecked(): void
    {
        $this->update(['premium_checked_at' => now()]);
    }

    /**
     * Обновляет timestamp проверки индекса локализации
     */
    public function markLocalizationChecked(): void
    {
        $this->update(['localization_checked_at' => now()]);
    }

    /**
     * Обновляет timestamp проверки выкупа
     */
    public function markRedemptionChecked(): void
    {
        $this->update(['redemption_checked_at' => now()]);
    }
}
