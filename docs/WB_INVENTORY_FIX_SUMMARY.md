# Wildberries Inventory Sync Fix - Summary

## Problem
При синхронизации Wildberries интеграций товары загружались, но **остатки магазина не выгружались** (все товары показывали 0 шт.).

## Root Cause
API Wildberries для получения складов продавца (`/api/v3/warehouses`) требует **Bearer авторизацию**:
```
Authorization: Bearer {api_key}
```

Но код отправлял запрос без префикса "Bearer":
```
Authorization: {api_key}
```

Это приводило к ошибке авторизации и пустому списку складов, что в результате давало нулевые остатки.

### Logs Before Fix
```
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: Analytics API returned no data, trying legacy Statistics API  
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: Statistics API returned null (check API key permissions)  
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: No stocks from WB warehouses APIs, trying FBS warehouses  
[2026-03-26 08:05:48] production.WARNING: WB InventoryApi: No warehouses found
```

## Solution

### Files Changed

#### 1. `app/Domains/Wildberries/Api/WildberriesClient.php`
**Added:**
- New method `getWithBearer()` for endpoints requiring Bearer token authorization
- New private method `getBearerHeaders()` to generate Bearer auth headers

```php
public function getWithBearer(string $endpoint, array $params = [], string $baseUrl = self::BASE_URL): ?array
{
    try {
        $response = Http::withHeaders($this->getBearerHeaders())
            ->timeout($this->timeout)
            ->get($baseUrl . $endpoint, $params);
        // ...
    }
}

private function getBearerHeaders(): array
{
    return [
        'Authorization' => 'Bearer ' . $this->apiKey,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}
```

**Why:** Some Wildberries API endpoints (especially new Marketplace API v3) require Bearer token format, while older endpoints work with simple API key. This maintains backward compatibility.

#### 2. `app/Domains/Wildberries/Api/InventoryApi.php`
**Changed:**
- Updated `getWarehouses()` to use `getWithBearer()` instead of `get()`

```php
public function getWarehouses(?Integration $integration = null): array
{
    // /api/v3/warehouses requires Bearer authorization
    $response = $this->client->getWithBearer('/api/v3/warehouses');
    return $response ?? [];
}
```

**Why:** The `/api/v3/warehouses` endpoint is part of Marketplace API v3 and requires Bearer authentication.

## Deployment

### Deploy Script
Created `fix-wb-inventory.sh` for hotfix deployment:
```bash
bash fix-wb-inventory.sh
```

### Deployment Steps
1. ✅ Files copied to temporary directory on server
2. ✅ Files moved to production with sudo
3. ✅ Ownership set to www-data
4. ✅ Laravel caches cleared (config, route, view, cache)
5. ✅ Deployment verified

### Git Commit
```
Fix WB inventory sync: Use Bearer auth for warehouses API

- Added getWithBearer() method to WildberriesClient for endpoints requiring Bearer token
- Updated getWarehouses() to use Bearer authorization (Marketplace API v3 requirement)
- This fixes the "No warehouses found" error causing zero stock levels
```

## Expected Results

After deployment:
1. ✅ `getWarehouses()` returns non-empty array of FBS seller warehouses
2. ✅ `getStocks()` combines FBO (Wildberries warehouses) + FBS (seller warehouses)
3. ✅ Products show correct stock quantities in UI
4. ✅ Sync logs show successful inventory sync

### Verification Commands
```bash
# Check sync status
ssh danya_user@194.87.104.42 'cd /var/www/products-backend && php artisan sync:status'

# Monitor logs for WB inventory sync
ssh danya_user@194.87.104.42 'tail -f /var/www/products-backend/storage/logs/laravel.log | grep "WB.*Inventory\|WB.*stocks"'

# Expected log output after fix:
# WB InventoryApi: Got FBO stocks from WB warehouses report
# WB InventoryApi: Combined FBO+FBS stocks
```

## References
- Wildberries API Documentation: https://dev.wildberries.ru/openapi/api-information
- Marketplace API (Warehouses): https://dev.wildberries.ru/openapi/work-with-products#/warehouses/getWarehouses

## Related Files
- `/Users/panfiloveshow/Documents/Товары бекенд/products-backend/docs/WB_INVENTORY_FIX.md` - Detailed technical analysis
- `/Users/panfiloveshow/Documents/Товары бекенд/products-backend/fix-wb-inventory.sh` - Deployment script
