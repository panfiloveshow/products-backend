# Ozon Unit Economics vNext

Актуально с `2026-04-07` в кодовой базе.

## Что изменилось

Ozon-расчёт больше не использует старую FBO-модель через:

- `avg_delivery_time_hours`
- `logistics_coefficient`
- `additional_commission_percent`

Новая модель строится на:

- тарифной матрице `scheme + volume bucket + route/locality`
- комиссии продажи `scheme + category + price segment`
- явных explainable-полях тарифа в результате расчёта

Основной fallback-источник в репозитории: [`config/ozon_unit_economics.php`](/Users/panfiloveshow/Documents/Товары бекенд/products-backend/config/ozon_unit_economics.php)

## Единый источник истины

Backend Ozon теперь должен считать только через доменный калькулятор:

- [`app/Domains/Ozon/UnitEconomics/OzonUnitEconomicsCalculator.php`](/Users/panfiloveshow/Documents/Товары бекенд/products-backend/app/Domains/Ozon/UnitEconomics/OzonUnitEconomicsCalculator.php)
- [`app/Domains/Ozon/Tariffs/OzonPricingMatrix.php`](/Users/panfiloveshow/Documents/Товары бекенд/products-backend/app/Domains/Ozon/Tariffs/OzonPricingMatrix.php)

`UnitEconomicsService` делегирует Ozon-расчёт в доменный слой и не должен содержать отдельную legacy-формулу.

## Новые поля Ozon

В API и persisted-данных Ozon используются:

- `tariff_version`
- `tariff_effective_from`
- `tariff_source`
- `route_key`
- `route_label`
- `is_local_sale`
- `non_local_markup_percent`
- `price_segment`
- `sales_fee_percent`

Эти поля должны присутствовать в:

- расчёте preview
- `unit_economics`
- `unit_economics_cache`
- frontend unit economics UI

## Legacy-поля

Старые Ozon-поля оставлены только для исторической совместимости БД и не должны использоваться как бизнес-истина:

- `avg_delivery_time_hours`
- `logistics_coefficient`
- `additional_commission_percent`
- `tariff_status`

Если они попадают в ответ Ozon API, это баг адаптера/контроллера.

## Приоритет источников

1. Ozon/API или актуальные marketplace-данные
2. repo fallback matrix

Для explainability в результате обязательно сохраняются:

- `tariff_source`
- `tariff_effective_from`
- `tariff_version`

## Frontend

UI unit economics для Ozon должен показывать:

- маршрут/кластер
- локальная или нелокальная продажа
- effective sales fee
- нелокальную наценку
- источник тарифа

UI не должен показывать тексты или настройки, связанные со старой delivery-time model.
