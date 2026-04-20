# Wildberries API Key Fix Required

## Problem
Все API запросы к Wildberries возвращают **401 Unauthorized**:

```
[2026-03-26 08:05:48] production.WARNING: WB API error {"endpoint":"/api/v3/warehouses","status":401}
[2026-03-26 08:05:47] production.WARNING: WB Statistics API error {"endpoint":"/api/v1/supplier/stocks","status":401}
[2026-03-26 08:05:47] production.WARNING: WB Analytics API POST error {"endpoint":"/api/analytics/v1/stocks-report/wb-warehouses","status":401}
```

## Root Cause
**API ключ Wildberries недействителен или истёк!**

Это НЕ проблема кода - проблема с учётными данными в базе данных.

## Solution

### Step 1: Generate New API Key

1. Go to Wildberries Seller Portal: https://seller.wildberries.ru
2. Navigate to **Profile** → **API Keys** (Профиль → API ключи)
3. Create new API key with required permissions:
   - ✅ **Products** (Товары) - for product cards
   - ✅ **Analytics** (Аналитика) - for stock reports
   - ✅ **Statistics** (Статистика) - for sales data
   - ✅ **Marketplace** (Маркетплейс) - for FBS warehouses (IMPORTANT!)

### Step 2: Update API Key in Database

Connect to server:
```bash
ssh danya_user@194.87.104.42
cd /var/www/products-backend
```

Update via Tinker:
```bash
echo '8o3QV0iWsZ3IGlP' | sudo -S php artisan tinker
```

In Tinker:
```php
// Find all WB integrations
$integrations = \App\Models\Integration::where('marketplace', 'wildberries')->get();

// For each integration, update the API key
foreach ($integrations as $integration) {
    echo "Updating: {$integration->name}\n";
    
    // Get current credentials
    $creds = $integration->getDecryptedCredentials();
    print_r($creds);
    
    // Update with NEW API key (replace with actual key!)
    $integration->setCredentials([
        'api_key' => 'YOUR_NEW_API_KEY_HERE'
    ]);
    $integration->save();
}

exit
```

### Step 3: Verify API Key Works

Test the new API key:
```bash
echo '8o3QV0iWsZ3IGlP' | sudo -S php artisan tinker
```

```php
$integration = \App\Models\Integration::where('marketplace', 'wildberries')->where('is_active', true)->first();
$client = \App\Domains\Wildberries\WildberriesMarketplace::fromIntegration($integration);

// Test warehouses endpoint
$warehouses = $client->getWarehouses();
echo "Warehouses found: " . count($warehouses) . "\n";

if (count($warehouses) > 0) {
    echo "✅ SUCCESS! API key is working!\n";
    print_r($warehouses[0]);
} else {
    echo "⚠️  No FBS warehouses (this is OK if you only use FBO)\n";
}

exit
```

### Step 4: Trigger Manual Sync

After updating API key:
```bash
echo '8o3QV0iWsZ3IGlP' | sudo -S php artisan queue:work --once
```

Or trigger via API:
```bash
curl -X POST http://localhost/api/products/sync \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"
```

### Step 5: Monitor Logs

Watch for successful sync:
```bash
tail -f /var/www/products-backend/storage/logs/laravel.log | grep -i 'WB.*stocks\|WB.*warehouse'
```

**Expected output after fix:**
```
WB InventoryApi: Got FBO stocks from WB warehouses report
WB InventoryApi: Combined FBO+FBS stocks
```

## Important Notes

### API Key Types
Wildberries has different API key types:
- **Content API** - for product cards (content-api.wildberries.ru)
- **Marketplace API** - for FBS operations (marketplace-api.wildberries.ru) ← **REQUIRED for FBS**
- **Analytics API** - for stock reports (seller-analytics-api.wildberries.ru)
- **Statistics API** - for sales data (statistics-api.wildberries.ru)

Make sure the API key has **Marketplace** permission for FBS warehouse access!

### FBO vs FBS
- **FBO** (Fulfillment by Operator) - Wildberries warehouses, accessed via Analytics/Statistics API
- **FBS** (Fulfillment by Seller) - Seller warehouses, accessed via Marketplace API `/api/v3/warehouses`

If you only use FBO (no FBS), then "No warehouses found" is normal. But you should still get stock data from Analytics API.

## References
- Wildberries API Docs: https://dev.wildberries.ru/openapi/api-information
- API Keys Guide: https://dev.wildberries.ru/openapi/api-information#section/o-api-klyu
- Marketplace API: https://dev.wildberries.ru/openapi/work-with-products

## Contact
If issues persist after updating API key, check:
1. API key has all required permissions
2. Integration is marked as active (`is_active = true`)
3. Queue workers are running
4. No firewall blocking API requests
