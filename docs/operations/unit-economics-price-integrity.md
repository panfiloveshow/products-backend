# Контроль действующей цены в юнит-экономике

## Что считается правильной ценой

Единственный алгоритм выбора цены находится в
`App\Services\UnitEconomics\MarketplacePriceResolver`. Его используют и расчёт
кэша, и integrity-check. Для Ozon побеждает источник с более свежим
`price_observed_at`; действующая акционная `marketing_seller_price` применяется,
если она ниже базовой цены. Для остальных маркетплейсов сохраняется приоритет
свежих данных товара.

Изменение приоритета цен без изменения тестов
`MarketplacePriceResolverTest`, `UnitEconomicsCacheServiceTest` и
`UnitEconomicsPriceIntegrityTest` запрещено.

## Автоматическая проверка

Продовый cron запускает проверку каждые 10 минут:

```bash
php artisan ue:price-integrity --marketplace=ozon,wildberries --max-age-minutes=2880 --repair --fail-on-drift --log
```

Проверка ловит:

- отличие цены кэша от выбранной действующей цены;
- отсутствующие и осиротевшие строки кэша;
- нулевой источник цены;
- протухший источник цены;
- разные действующие цены одного SKU по схемам работы.

`--repair` пересчитывает интеграцию только при проблеме, которую можно исправить
пересчётом кэша. Протухший или отсутствующий источник требует синхронизации с
маркетплейсом и не запускает бесконечные пересчёты.

Лог команды: `storage/logs/ue-price-integrity.log`. Подробный контекст также
попадает в Laravel log.

## Ручная диагностика

Без изменений данных:

```bash
php artisan ue:price-integrity --integration=17 --max-age-minutes=0
```

С безопасным восстановлением кэша и ненулевым exit-кодом при остаточном сбое:

```bash
php artisan ue:price-integrity --integration=17 --max-age-minutes=0 --repair --fail-on-drift --log
```

После выкладки проверить `/api/health`: поле `release` должно совпадать с SHA
развёрнутого коммита. Затем запустить integrity-check без отключения проверки
ошибок.

## Порядок разбора инцидента

1. Зафиксировать integration, SKU, схему, фактическую и ожидаемую цену из вывода.
2. Проверить `source` и `observed_at`. Старый источник означает сбой синхронизации,
   а не калькулятора.
3. При `price_drift` или `missing_cache` запустить команду с `--repair`.
4. При `stale_price_source`, `missing_price_source` или
   `scheme_price_divergence` проверить последний sync и ответы price API.
5. Сопоставить поле `release` health-check с коммитом GitHub; откат делать только
   на заранее сохранённую версию файлов/релиз.
