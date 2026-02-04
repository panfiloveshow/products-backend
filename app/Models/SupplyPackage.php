<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель грузоместа (короб/палета)
 * 
 * Грузоместо — это физическая единица упаковки для поставки на склад Ozon.
 * Каждое грузоместо имеет уникальный штрихкод и содержит определённые товары.
 */
class SupplyPackage extends Model
{
    use HasFactory;

    // Типы грузомест
    public const TYPE_BOX = 'box';           // Короб
    public const TYPE_PALLET = 'pallet';     // Палета
    public const TYPE_MONO_PALLET = 'mono_pallet'; // Монопалета (один SKU)

    // Статусы грузоместа
    public const STATUS_DRAFT = 'draft';           // Создано, не заполнено
    public const STATUS_PACKING = 'packing';       // В процессе упаковки
    public const STATUS_PACKED = 'packed';         // Упаковано
    public const STATUS_LABELED = 'labeled';       // Этикетка напечатана
    public const STATUS_READY = 'ready';           // Готово к отгрузке
    public const STATUS_SHIPPED = 'shipped';       // Отгружено
    public const STATUS_ACCEPTED = 'accepted';     // Принято на складе
    public const STATUS_REJECTED = 'rejected';     // Отклонено
    public const STATUS_PARTIAL = 'partial';       // Частично принято

    protected $fillable = [
        'supply_id',
        'package_type',
        'sequence_number',      // Порядковый номер в поставке
        'barcode',              // Штрихкод грузоместа (генерируется Ozon или нами)
        'ozon_package_id',      // ID грузоместа в Ozon (если есть)
        'weight',               // Вес брутто, кг
        'length',               // Длина, см
        'width',                // Ширина, см
        'height',               // Высота, см
        'items_count',          // Количество позиций
        'total_quantity',       // Общее количество единиц товара
        'status',
        'packed_at',
        'packed_by',
        'label_printed_at',
        'label_print_count',
        'shipped_at',
        'accepted_at',
        'accepted_quantity',
        'rejected_quantity',
        'rejection_reason',
        'meta',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'items_count' => 'integer',
        'total_quantity' => 'integer',
        'packed_at' => 'datetime',
        'label_printed_at' => 'datetime',
        'label_print_count' => 'integer',
        'shipped_at' => 'datetime',
        'accepted_at' => 'datetime',
        'accepted_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'meta' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($package) {
            if (empty($package->barcode)) {
                $package->barcode = self::generateBarcode();
            }
            if (empty($package->status)) {
                $package->status = self::STATUS_DRAFT;
            }
        });
    }

    /**
     * Генерация уникального штрихкода для грузоместа
     */
    public static function generateBarcode(): string
    {
        $prefix = 'PKG';
        $timestamp = now()->format('ymdHis');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}{$timestamp}{$random}";
    }

    // === Relationships ===

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyPackageItem::class, 'package_id');
    }

    public function packedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    // === Scopes ===

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePacked($query)
    {
        return $query->where('status', self::STATUS_PACKED);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    // === Helpers ===

    public function getVolumeAttribute(): float
    {
        return ($this->length ?? 0) * ($this->width ?? 0) * ($this->height ?? 0) / 1000000; // м³
    }

    public function getVolumeLitersAttribute(): float
    {
        return $this->volume * 1000;
    }

    public function canAddItem(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PACKING]);
    }

    public function markAsPacked(?int $userId = null): void
    {
        $this->update([
            'status' => self::STATUS_PACKED,
            'packed_at' => now(),
            'packed_by' => $userId,
        ]);
    }

    public function markAsLabeled(): void
    {
        $this->update([
            'status' => self::STATUS_LABELED,
            'label_printed_at' => now(),
            'label_print_count' => ($this->label_print_count ?? 0) + 1,
        ]);
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();
        
        $this->update([
            'items_count' => $items->count(),
            'total_quantity' => $items->sum('quantity'),
            'weight' => $items->sum(fn($item) => ($item->weight ?? 0) * $item->quantity),
        ]);
    }
}
