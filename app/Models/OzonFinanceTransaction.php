<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OzonFinanceTransaction extends Model
{
    use HasFactory, Traits\BelongsToCurrentWorkspaceThroughIntegration;

    public const OP_DIRECT_FLOW_LOGISTIC = 'MarketplaceServiceItemDirectFlowLogistic';
    public const OP_DELIVERY_TO_CUSTOMER = 'MarketplaceServiceItemDeliveryToCustomer';
    public const OP_PICKUP = 'MarketplaceServiceItemPickup';
    public const OP_DROPOFF_FF = 'MarketplaceServiceItemDropoffFF';

    // Новый синк (accrual/by-day, с 2026-08-25) пишет логистику этими типами.
    public const OP_ACCRUAL_LOGISTIC = 'Logistic';
    public const OP_ACCRUAL_B2C_LOGISTICS = 'B2CLogistics';

    public const LOGISTICS_OPERATION_TYPES = [
        self::OP_DIRECT_FLOW_LOGISTIC,
        self::OP_DELIVERY_TO_CUSTOMER,
        self::OP_PICKUP,
        self::OP_DROPOFF_FF,
        self::OP_ACCRUAL_LOGISTIC,
        self::OP_ACCRUAL_B2C_LOGISTICS,
    ];

    protected $table = 'ozon_finance_transactions';

    protected $fillable = [
        'integration_id',
        'operation_id',
        'operation_type',
        'operation_type_name',
        'operation_date',
        'posting_number',
        'sku',
        'offer_id',
        'amount',
        'accruals_for_sale',
        'sale_commission',
        'warehouse_id',
        'warehouse_name',
        'raw',
        'fetched_at',
    ];

    protected $casts = [
        'operation_date' => 'datetime',
        'amount' => 'decimal:2',
        'accruals_for_sale' => 'decimal:2',
        'sale_commission' => 'decimal:2',
        'raw' => 'array',
        'fetched_at' => 'datetime',
    ];
}
