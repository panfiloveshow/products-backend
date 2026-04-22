<?php

namespace App\Domains\Ozon\UnitEconomics;

/**
 * Коды причин применения / неприменения наценки за нелокальную продажу
 * в `ozon_order_unit_economics.markup_reason_code`.
 *
 * Единое место определения семантики: «исключать ли из агрегата», «это освобождение
 * или исключение», «это финальный статус». Раньше списки кодов дублировались в 6+
 * файлах (OverpaymentCalculator, LostMarginCalculator, LocalityShareCalculator,
 * LocalityAggregator, LocalityExplanationService × 3, UnitEconomicsController × 2,
 * RecommendationRanker) — добавление нового кода требовало вспомнить все 6 мест.
 */
enum MarkupReasonCode: string
{
    // Заказ физически не состоялся — исключается из знаменателя агрегатов:
    case CancelledOrder = 'cancelled_order';
    case NotRedeemed = 'not_redeemed';

    // Локальная продажа (кластер отправки = кластер назначения) — наценка не применяется:
    case LocalCluster = 'local_cluster';

    // Non-local, наценка применена:
    case NonLocalMarkupApplied = 'non_local_markup_applied';

    // Non-local, но наценка не применяется по правилам Ozon (освобождение):
    case FboLt50Orders7d = 'fbo_lt_50_orders_7d';
    case ZeroMarkupCluster = 'zero_markup_cluster';

    // Исключения из правил (override в UnitEconomicsSettings):
    case SelectOnly = 'select_only';
    case SizeRestricted = 'size_restricted';
    case CrossDocking = 'cross_docking';

    // Внутренние причины Ozon, по которым наценка не применяется (API exceptions):
    case UnavailableOzonReroute = 'unavailable_ozon_reroute';
    case UnavailableClusterBlocked = 'unavailable_cluster_blocked';
    case UnavailableSelectOnly = 'unavailable_select_only';

    /**
     * Исключать ли из агрегатов локальности и переплаты.
     * Используется в знаменателях: local/non_local share, avg_markup, overpayment total.
     */
    public function isExcludedFromAggregation(): bool
    {
        return match ($this) {
            self::CancelledOrder, self::NotRedeemed => true,
            default => false,
        };
    }

    /**
     * Освобождение от наценки по правилам Ozon (не баг, не override — штатное правило).
     */
    public function isWaived(): bool
    {
        return match ($this) {
            self::FboLt50Orders7d, self::ZeroMarkupCluster => true,
            default => false,
        };
    }

    /**
     * Ручные исключения продавца/админа через UnitEconomicsSettings.
     */
    public function isManualException(): bool
    {
        return match ($this) {
            self::SelectOnly, self::SizeRestricted, self::CrossDocking => true,
            default => false,
        };
    }

    /**
     * Исключения на стороне Ozon (API exceptions).
     */
    public function isApiException(): bool
    {
        return match ($this) {
            self::UnavailableOzonReroute,
            self::UnavailableClusterBlocked,
            self::UnavailableSelectOnly => true,
            default => false,
        };
    }

    /**
     * Значения исключаемых кодов как массив строк — для удобства `whereNotIn`
     * в Query Builder / SQL. Используй это вместо inline array литералов.
     */
    public static function excludedValues(): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(
                self::cases(),
                fn (self $c) => $c->isExcludedFromAggregation()
            )
        ));
    }

    /**
     * Безопасный tryFrom: если передан неизвестный код, возвращает null
     * (не throw). Используй для checks на external data.
     */
    public static function tryCheck(?string $code, callable $predicate): bool
    {
        if ($code === null) {
            return false;
        }
        $case = self::tryFrom($code);
        return $case !== null && $predicate($case);
    }
}
