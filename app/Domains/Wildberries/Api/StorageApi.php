<?php

namespace App\Domains\Wildberries\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с хранением и тарифами Wildberries
 */
class StorageApi
{
    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Получить платное хранение (асинхронный отчёт)
     * 
     * Paid Storage API работает асинхронно:
     * 1. POST /api/v1/paid_storage - создать задачу
     * 2. GET /api/v1/paid_storage/tasks/{task_id}/status - проверить статус
     * 3. GET /api/v1/paid_storage/tasks/{task_id}/download - скачать отчёт
     * 
     * Максимальный период отчёта: 8 дней
     * 
     * @see https://dev.wildberries.ru/openapi/reports#tag/Paid-Storage
     */
    public function getPaidStorage(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $dateTo = $dateTo ?? now()->format('Y-m-d');
        // Максимум 8 дней для отчёта
        $dateFrom = $dateFrom ?? now()->subDays(7)->format('Y-m-d');

        try {
            // 1. Создаём задачу на генерацию отчёта
            // Используем Analytics API (seller-analytics-api.wildberries.ru)
            $taskResponse = $this->client->analyticsGet("/api/v1/paid_storage", [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);
            
            $taskId = $taskResponse['data']['taskId'] ?? null;
            if (!$taskId) {
                Log::warning('WB Paid Storage: Failed to create task', ['response' => $taskResponse]);
                return [];
            }
            
            Log::info('WB Paid Storage: Task created', ['taskId' => $taskId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);
            
            // 2. Ждём готовности отчёта (макс 30 секунд)
            $maxAttempts = 10;
            $attempt = 0;
            $isReady = false;
            
            while ($attempt < $maxAttempts && !$isReady) {
                sleep(3); // Ждём 3 секунды между проверками
                
                $statusResponse = $this->client->analyticsGet("/api/v1/paid_storage/tasks/{$taskId}/status");
                $status = $statusResponse['data']['status'] ?? null;
                
                if ($status === 'done') {
                    $isReady = true;
                } elseif ($status === 'error') {
                    Log::error('WB Paid Storage: Task failed', ['taskId' => $taskId, 'response' => $statusResponse]);
                    return [];
                }
                
                $attempt++;
            }
            
            if (!$isReady) {
                Log::warning('WB Paid Storage: Task not ready after max attempts', ['taskId' => $taskId]);
                return [];
            }
            
            // 3. Скачиваем отчёт
            $report = $this->client->analyticsGet("/api/v1/paid_storage/tasks/{$taskId}/download");
            
            Log::info('WB Paid Storage: Report downloaded', ['count' => count($report ?? [])]);
            
            return $report ?? [];
        } catch (\Exception $e) {
            Log::error('WB getPaidStorage error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить стоимость хранения по SKU
     * 
     * Возвращает массив [barcode => ['storage_cost' => float, 'storage_cost_per_day' => float, ...]]
     * Индексирует по barcode для совместимости с InventoryApi
     */
    public function getStorageCostBySku(): array
    {
        try {
            $storage = $this->getPaidStorage();

            if (empty($storage)) {
                return [];
            }

            $result = [];
            foreach ($storage as $item) {
                // Используем barcode как основной ключ (совпадает с InventoryApi)
                $barcode = $item['barcode'] ?? null;
                $nmId = $item['nmId'] ?? null;
                $vendorCode = $item['vendorCode'] ?? null;
                
                $key = $barcode ?: (string)$nmId ?: $vendorCode;
                if (!$key) continue;

                $warehousePrice = (float)($item['warehousePrice'] ?? 0);

                if (!isset($result[$key])) {
                    $result[$key] = [
                        'storage_cost' => 0,
                        'storage_cost_per_day' => 0,
                        'warehouse' => $item['warehouse'] ?? null,
                        'warehouse_coef' => (float)($item['warehouseCoef'] ?? 1),
                        'volume' => (float)($item['volume'] ?? 0),
                        'loyalty_discount' => (float)($item['loyaltyDiscount'] ?? 0),
                        'days_count' => 0,
                    ];
                }

                $result[$key]['storage_cost'] += $warehousePrice;
                $result[$key]['days_count']++;
            }

            // Рассчитываем среднюю стоимость за день
            foreach ($result as $key => &$data) {
                if ($data['days_count'] > 0) {
                    $data['storage_cost_per_day'] = round($data['storage_cost'] / $data['days_count'], 2);
                }
                $data['storage_cost'] = round($data['storage_cost'], 2);
            }

            Log::info('WB getStorageCostBySku: processed', [
                'count' => count($result),
                'sample_keys' => array_slice(array_keys($result), 0, 5),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('WB getStorageCostBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить тарифы на поставку
     */
    public function getSupplyTariffs(): array
    {
        try {
            $response = $this->client->get("/api/v1/tariffs/box");

            return $response['response']['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('WB getSupplyTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить тарифы на хранение
     */
    public function getStorageTariffs(): array
    {
        try {
            $response = $this->client->get("/api/v1/tariffs/return");

            return $response['response']['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('WB getStorageTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить комиссии по категориям
     * 
     * ВАЖНО: Эндпоинт /api/v1/tariffs/commission устарел (404).
     * Получает комиссии по категориям товаров через официальный WB API.
     * 
     * Endpoint: GET /api/v1/tariffs/commission
     * URL: https://common-api.wildberries.ru
     * 
     * @return array [subjectId => ['fbo' => float, 'fbs' => float, ...]]
     */
    public function getCommissions(): array
    {
        try {
            $response = $this->client->commonGet('/api/v1/tariffs/commission', ['locale' => 'ru']);
            
            if (!$response || !isset($response['report'])) {
                Log::warning('WB getCommissions: Empty response, using fallback');
                return $this->getFallbackCommissions();
            }
            
            $commissions = [];
            foreach ($response['report'] as $item) {
                $subjectId = $item['subjectID'] ?? null;
                if (!$subjectId) continue;
                
                $commissions[$subjectId] = [
                    'fbo' => (float)($item['kgvpMarketplace'] ?? 15.0),
                    'fbs' => (float)($item['kgvpSupplier'] ?? 15.0),
                    'fbs_express' => (float)($item['kgvpSupplierExpress'] ?? 3.0),
                    'pickup' => (float)($item['kgvpPickup'] ?? 15.0),
                    'booking' => (float)($item['kgvpBooking'] ?? 15.0),
                    'paid_storage' => (float)($item['paidStorageKgvp'] ?? 15.0),
                    'parent_id' => $item['parentID'] ?? null,
                    'parent_name' => $item['parentName'] ?? null,
                    'subject_name' => $item['subjectName'] ?? null,
                ];
            }
            
            Log::info('WB getCommissions: Loaded from API', [
                'total_categories' => count($commissions),
            ]);
            
            // Добавляем default для категорий, которых нет в API
            $commissions['default'] = [
                'fbo' => 15.0,
                'fbs' => 15.0,
                'fbs_express' => 3.0,
                'pickup' => 15.0,
                'booking' => 15.0,
                'paid_storage' => 15.0,
                'parent_id' => null,
                'parent_name' => null,
                'subject_name' => null,
            ];
            
            return $commissions;
            
        } catch (\Exception $e) {
            Log::error('WB getCommissions error', ['error' => $e->getMessage()]);
            return $this->getFallbackCommissions();
        }
    }
    
    /**
     * Fallback комиссии если API недоступен
     */
    private function getFallbackCommissions(): array
    {
        return [
            'default' => [
                'fbo' => 15.0,
                'fbs' => 15.0,
                'fbs_express' => 3.0,
                'pickup' => 15.0,
                'booking' => 15.0,
                'paid_storage' => 15.0,
                'parent_id' => null,
                'parent_name' => null,
                'subject_name' => null,
            ],
        ];
    }

    /**
     * Получить коэффициенты складов (КС) для FBO из Box Tariffs API
     * 
     * Использует /api/v1/tariffs/box для получения актуальных коэффициентов логистики.
     * Индексирует по названию склада (нормализованному) для сопоставления с остатками.
     * 
     * @return array [warehouseName => ['delivery_coef' => float, 'storage_coef' => float, ...]]
     */
    public function getWarehouseCoefficients(): array
    {
        try {
            $boxTariffs = $this->getBoxTariffs();
            
            if (empty($boxTariffs['warehouseList'])) {
                return [];
            }

            $coefficients = [];
            foreach ($boxTariffs['warehouseList'] as $wh) {
                $warehouseName = $wh['warehouse_name'] ?? '';
                if (!$warehouseName) continue;
                
                // Нормализуем имя для использования как ключ
                $normalizedName = $this->normalizeWarehouseName($warehouseName);

                // Коэффициенты уже в процентах (100 = 1.0, 150 = 1.5)
                $deliveryCoefPercent = (float)($wh['delivery_coef_percent'] ?? 100);
                $storageCoefPercent = (float)($wh['storage_coef_percent'] ?? 100);
                
                $coefficients[$normalizedName] = [
                    'warehouse_name' => $warehouseName,
                    'storage_coef' => $storageCoefPercent / 100, // Множитель (1.0, 1.5, etc)
                    'delivery_coef' => $deliveryCoefPercent / 100, // Множитель (1.0, 1.5, etc)
                    'storage_coef_percent' => $storageCoefPercent, // Процент для отображения
                    'delivery_coef_percent' => $deliveryCoefPercent, // Процент для отображения
                    'delivery_base' => (float)($wh['delivery_base'] ?? 0),
                    'delivery_liter' => (float)($wh['delivery_liter'] ?? 0),
                    'storage_base' => (float)($wh['storage_base'] ?? 0),
                    'storage_liter' => (float)($wh['storage_liter'] ?? 0),
                    'geo_name' => $wh['geo_name'] ?? '',
                ];
            }

            return $coefficients;
        } catch (\Exception $e) {
            Log::error('WB getWarehouseCoefficients error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить тарифы на возврат продавцу
     */
    public function getReturnTariffs(?string $date = null): array
    {
        try {
            $date = $date ?? now()->format('Y-m-d');
            $response = $this->client->commonGet("/api/v1/tariffs/return", [
                'date' => $date,
            ]);

            return $response['response']['data'] ?? $response ?? [];
        } catch (\Exception $e) {
            Log::error('WB getReturnTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить тарифы логистики (box tariffs) с детализацией по складам
     * 
     * Возвращает данные аналогичные разделу "Тарифы складов" в ЛК WB:
     * - boxDeliveryBase — базовая логистика (₽/первый литр)
     * - boxDeliveryLiter — логистика за доп. литр
     * - boxDeliveryCoefExpr — коэффициент логистики (%)
     * - boxStorageBase — базовое хранение (₽/день/литр)
     * - boxStorageLiter — хранение за доп. литр
     * - boxStorageCoefExpr — коэффициент хранения (%)
     * - geoName — федеральный округ
     * - warehouseName — название склада
     * 
     * @param string|null $date Дата в формате YYYY-MM-DD
     * @return array Тарифы по складам
     */
    public function getBoxTariffs(?string $date = null): array
    {
        try {
            $date = $date ?? now()->format('Y-m-d');
            $response = $this->client->commonGet("/api/v1/tariffs/box", [
                'date' => $date,
            ]);

            $data = $response['response']['data'] ?? $response ?? [];
            
            // Преобразуем в удобный формат с числовыми значениями
            if (isset($data['warehouseList']) && is_array($data['warehouseList'])) {
                $warehouses = [];
                foreach ($data['warehouseList'] as $wh) {
                    $warehouses[] = [
                        'warehouse_name' => $wh['warehouseName'] ?? '',
                        'geo_name' => $wh['geoName'] ?? '',
                        // Логистика
                        'delivery_base' => $this->parseDecimal($wh['boxDeliveryBase'] ?? '0'),
                        'delivery_liter' => $this->parseDecimal($wh['boxDeliveryLiter'] ?? '0'),
                        'delivery_coef_percent' => (int) ($wh['boxDeliveryCoefExpr'] ?? 100),
                        // Логистика FBS (Marketplace)
                        'delivery_marketplace_base' => $this->parseDecimal($wh['boxDeliveryMarketplaceBase'] ?? '0'),
                        'delivery_marketplace_liter' => $this->parseDecimal($wh['boxDeliveryMarketplaceLiter'] ?? '0'),
                        'delivery_marketplace_coef_percent' => (int) ($wh['boxDeliveryMarketplaceCoefExpr'] ?? 100),
                        // Хранение
                        'storage_base' => $this->parseDecimal($wh['boxStorageBase'] ?? '0'),
                        'storage_liter' => $this->parseDecimal($wh['boxStorageLiter'] ?? '0'),
                        'storage_coef_percent' => (int) ($wh['boxStorageCoefExpr'] ?? 100),
                    ];
                }
                $data['warehouseList'] = $warehouses;
            }
            
            return $data;
        } catch (\Exception $e) {
            Log::error('WB getBoxTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Парсинг десятичного числа из строки WB API (формат "48" или "11,2")
     */
    private function parseDecimal(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
    
    /**
     * Нормализация названия склада для сравнения
     * Убирает дефисы, лишние пробелы, приводит к нижнему регистру
     */
    private function normalizeWarehouseName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // Заменяем дефисы на пробелы
        $name = str_replace(['-', '–', '—'], ' ', $name);
        // Убираем множественные пробелы
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * Получить коэффициенты для FBS складов продавца
     * 
     * Связывает склады продавца (через officeId) с физическими складами WB
     * и возвращает коэффициенты логистики FBS (Marketplace)
     * 
     * @return array [warehouseId => ['name' => string, 'office_name' => string, 'coef' => float, 'base' => float, 'liter' => float]]
     */
    public function getFbsWarehouseCoefficients(): array
    {
        try {
            // Получаем список офисов WB (физические склады/СЦ)
            $offices = $this->client->get('/api/v3/offices') ?? [];
            $officesById = [];
            foreach ($offices as $office) {
                $officesById[$office['id']] = $office;
            }
            
            // Получаем тарифы по складам
            $boxTariffs = $this->getBoxTariffs();
            $tariffsByName = [];
            foreach ($boxTariffs['warehouseList'] ?? [] as $wh) {
                $name = mb_strtolower(trim($wh['warehouse_name'] ?? ''));
                $tariffsByName[$name] = $wh;
            }
            
            // Получаем склады продавца
            $inventoryApi = new InventoryApi($this->client);
            $sellerWarehouses = $inventoryApi->getWarehouses();
            
            $result = [];
            foreach ($sellerWarehouses as $sw) {
                $warehouseId = $sw['id'] ?? null;
                $officeId = $sw['officeId'] ?? null;
                $warehouseName = $sw['name'] ?? 'Unknown';
                
                if (!$warehouseId) continue;
                
                // Находим офис по officeId
                $office = $officesById[$officeId] ?? null;
                $officeName = $office['name'] ?? '';
                
                // Нормализуем имя офиса для поиска (убираем дефисы, лишние пробелы)
                $normalizedOfficeName = $this->normalizeWarehouseName($officeName);
                $tariff = $tariffsByName[$normalizedOfficeName] ?? null;
                
                // Если не нашли по точному имени — ищем по частичному совпадению
                if (!$tariff && $officeName) {
                    foreach ($tariffsByName as $name => $t) {
                        // Нормализуем имя склада из тарифов
                        $normalizedTariffName = $this->normalizeWarehouseName($name);
                        
                        // Проверяем совпадение нормализованных имён
                        if ($normalizedTariffName === $normalizedOfficeName) {
                            $tariff = $t;
                            break;
                        }
                        
                        // Частичное совпадение
                        if (stripos($normalizedTariffName, $normalizedOfficeName) !== false || 
                            stripos($normalizedOfficeName, $normalizedTariffName) !== false) {
                            $tariff = $t;
                            break;
                        }
                    }
                }
                
                // Если всё ещё не нашли — используем средний коэффициент по ФО
                if (!$tariff && $office) {
                    $fo = $office['federalDistrict'] ?? '';
                    $foKey = 'маркетплейс: ' . mb_strtolower($fo);
                    foreach ($tariffsByName as $name => $t) {
                        if (stripos($name, $foKey) !== false && stripos($name, 'сгт') === false) {
                            $tariff = $t;
                            Log::debug('FBS using FO average tariff', ['office' => $officeName, 'fo' => $fo]);
                            break;
                        }
                    }
                }
                
                // Используем FBS тарифы (delivery_marketplace_*)
                $coefPercent = $tariff['delivery_marketplace_coef_percent'] ?? 100;
                $coef = $coefPercent / 100; // Преобразуем проценты в множитель
                
                $result[$warehouseId] = [
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $warehouseName,
                    'office_id' => $officeId,
                    'office_name' => $officeName,
                    'delivery_coef' => $coef,
                    'delivery_base' => $tariff['delivery_marketplace_base'] ?? 0,
                    'delivery_liter' => $tariff['delivery_marketplace_liter'] ?? 0,
                    'coef_percent' => $coefPercent,
                ];
                
                Log::debug('FBS warehouse coefficient', [
                    'warehouse' => $warehouseName,
                    'office' => $officeName,
                    'coef' => $coef,
                ]);
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('WB getFbsWarehouseCoefficients error', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Получить средний КС (коэффициент склада) для расчётов
     * Возвращает средний коэффициент по всем активным складам
     */
    public function getAverageWarehouseCoefficient(): array
    {
        $coefficients = $this->getWarehouseCoefficients();
        
        if (empty($coefficients)) {
            return [
                'storage_coef' => 1.0,
                'delivery_coef' => 1.0,
            ];
        }

        $storageSum = 0;
        $deliverySum = 0;
        $count = 0;

        foreach ($coefficients as $coef) {
            if ($coef['storage_coef'] > 0) {
                $storageSum += $coef['storage_coef'];
                $deliverySum += $coef['delivery_coef'];
                $count++;
            }
        }

        return [
            'storage_coef' => $count > 0 ? round($storageSum / $count, 2) : 1.0,
            'delivery_coef' => $count > 0 ? round($deliverySum / $count, 2) : 1.0,
        ];
    }
}
