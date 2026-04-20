# Fix: Wildberries Inventory Not Syncing

## Problem
Wildberries inventory synchronization is failing with zero stock levels for all products.

### Logs Analysis
```
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: Analytics API returned no data, trying legacy Statistics API  
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: Statistics API returned null (check API key permissions)  
[2026-03-26 08:05:47] production.WARNING: WB InventoryApi: No stocks from WB warehouses APIs, trying FBS warehouses  
[2026-03-26 08:05:48] production.WARNING: WB InventoryApi: No warehouses found
```

### Root Cause
The `/api/v3/warehouses` endpoint (Marketplace API for FBS seller warehouses) requires **Bearer token** authorization format:
```
Authorization: Bearer {api_key}
```

But the current implementation sends:
```
Authorization: {api_key}
```

This causes the API to reject the request, returning empty warehouses list.

## Solution

### Option 1: Add Bearer prefix to Authorization header (Recommended)

Update `app/Domains/Wildberries/Api/WildberriesClient.php`:

```php
private function getHeaders(): array
{
    return [
        'Authorization' => 'Bearer ' . $this->apiKey,  // Add Bearer prefix
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}
```

**Impact:** This will fix FBS warehouses API calls, but may affect older endpoints that expect the old format.

### Option 2: Use Bearer only for specific endpoints (Safer)

Create a separate method for Bearer-authenticated requests:

```php
public function getWithBearer(string $endpoint, array $params = []): ?array
{
    try {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
        ->timeout($this->timeout)
        ->get(self::BASE_URL . $endpoint, $params);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('WB API error', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    } catch (\Exception $e) {
        Log::error('WB API exception', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}
```

Then use it in `getWarehouses()`:

```php
public function getWarehouses(?Integration $integration = null): array
{
    $response = $this->client->getWithBearer('/api/v3/warehouses');
    return $response ?? [];
}
```

## Testing

After fix, verify:
1. `getWarehouses()` returns non-empty array
2. `getStocks()` returns FBO + FBS stock levels
3. Products show correct stock quantities in UI

## References
- https://dev.wildberries.ru/openapi/api-information
- https://dev.wildberries.ru/openapi/work-with-products#/warehouses/getWarehouses
