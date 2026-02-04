<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель документа/этикетки поставки
 * 
 * Хранит ссылки на сгенерированные документы: этикетки грузомест,
 * сопроводительные документы, акты, накладные и т.д.
 */
class SupplyDocument extends Model
{
    use HasFactory;

    // Типы документов
    public const TYPE_PACKAGE_LABEL = 'package_label';       // Этикетка грузоместа
    public const TYPE_PALLET_LABEL = 'pallet_label';         // Этикетка палеты
    public const TYPE_PRODUCT_LABEL = 'product_label';       // Этикетка товара
    public const TYPE_SUPPLY_ACT = 'supply_act';             // Акт поставки
    public const TYPE_WAYBILL = 'waybill';                   // Транспортная накладная
    public const TYPE_PACKING_LIST = 'packing_list';         // Упаковочный лист
    public const TYPE_INVOICE = 'invoice';                   // Счёт-фактура
    public const TYPE_ACCEPTANCE_ACT = 'acceptance_act';     // Акт приёмки
    public const TYPE_DISCREPANCY_ACT = 'discrepancy_act';   // Акт расхождений
    public const TYPE_OTHER = 'other';

    // Форматы файлов
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_PNG = 'png';
    public const FORMAT_ZPL = 'zpl';      // Zebra Programming Language (для принтеров этикеток)
    public const FORMAT_HTML = 'html';
    public const FORMAT_XLSX = 'xlsx';

    // Статусы
    public const STATUS_PENDING = 'pending';       // Ожидает генерации
    public const STATUS_GENERATING = 'generating'; // Генерируется
    public const STATUS_READY = 'ready';           // Готов
    public const STATUS_ERROR = 'error';           // Ошибка генерации
    public const STATUS_EXPIRED = 'expired';       // Устарел (нужна перегенерация)

    // Источники
    public const SOURCE_OZON = 'ozon';             // Получен из Ozon API
    public const SOURCE_SYSTEM = 'system';         // Сгенерирован нашей системой
    public const SOURCE_UPLOAD = 'upload';         // Загружен пользователем

    protected $fillable = [
        'supply_id',
        'package_id',           // Если документ относится к конкретному грузоместу
        'document_type',
        'document_name',
        'description',
        'format',
        'source',
        'file_path',            // Путь к файлу в storage
        'file_url',             // URL для скачивания (если внешний)
        'file_size',            // Размер в байтах
        'file_hash',            // MD5/SHA256 для проверки целостности
        'barcode_data',         // Данные штрихкода (если это этикетка)
        'barcode_type',         // Тип штрихкода (EAN13, Code128, QR и т.д.)
        'ozon_document_id',     // ID документа в Ozon (если получен оттуда)
        'status',
        'error_message',
        'generated_at',
        'generated_by',
        'downloaded_count',
        'last_downloaded_at',
        'expires_at',           // Срок действия (для временных ссылок)
        'meta',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'generated_at' => 'datetime',
        'downloaded_count' => 'integer',
        'last_downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($document) {
            if (empty($document->status)) {
                $document->status = self::STATUS_PENDING;
            }
        });
    }

    // === Relationships ===

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SupplyPackage::class, 'package_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // === Scopes ===

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeLabels($query)
    {
        return $query->whereIn('document_type', [
            self::TYPE_PACKAGE_LABEL,
            self::TYPE_PALLET_LABEL,
            self::TYPE_PRODUCT_LABEL,
        ]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    // === Helpers ===

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function markAsReady(string $filePath, ?int $fileSize = null): void
    {
        $this->update([
            'status' => self::STATUS_READY,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'generated_at' => now(),
        ]);
    }

    public function markAsError(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $errorMessage,
        ]);
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('downloaded_count');
        $this->update(['last_downloaded_at' => now()]);
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Получить типы документов с названиями
     */
    public static function getDocumentTypes(): array
    {
        return [
            self::TYPE_PACKAGE_LABEL => 'Этикетка грузоместа',
            self::TYPE_PALLET_LABEL => 'Этикетка палеты',
            self::TYPE_PRODUCT_LABEL => 'Этикетка товара',
            self::TYPE_SUPPLY_ACT => 'Акт поставки',
            self::TYPE_WAYBILL => 'Транспортная накладная',
            self::TYPE_PACKING_LIST => 'Упаковочный лист',
            self::TYPE_INVOICE => 'Счёт-фактура',
            self::TYPE_ACCEPTANCE_ACT => 'Акт приёмки',
            self::TYPE_DISCREPANCY_ACT => 'Акт расхождений',
            self::TYPE_OTHER => 'Другое',
        ];
    }
}
