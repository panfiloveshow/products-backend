from pathlib import Path
from datetime import datetime

path = Path('/var/www/products-backend/app/Services/UnitEconomicsCacheService.php')
original = path.read_text()
updated = original


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 match, got {count}')
    return text.replace(old, new, 1)

updated = replace_once(
    updated,
    """    public function recalculateProduct(Product $product): array\n    {\n        $schemes = $this->getSchemesForProduct($product);""",
    """    public function recalculateProduct(Product $product): array\n    {\n        $this->forgetUnitEconomicsCache($product->integration_id, $product->sku);\n        $schemes = $this->getSchemesForProduct($product);""",
    'recalculateProduct cache reset',
)

updated = replace_once(
    updated,
    """    public function recalculateIntegration(int $integrationId): array\n    {\n        // Удаляем старый кэш\n        UnitEconomicsCache::where('integration_id', $integrationId)->delete();""",
    """    public function recalculateIntegration(int $integrationId): array\n    {\n        $this->unitEconomicsCache = [];\n        $this->settingsCache = [];\n        // Удаляем старый кэш\n        UnitEconomicsCache::where('integration_id', $integrationId)->delete();""",
    'recalculateIntegration reset memory cache',
)

updated = replace_once(
    updated,
    """        $drrPercent = (float) ($settings?->drr_percent ?? $existingUE?->drr_percent ?? 0);\n        $ourSharePercent = (float) ($settings?->our_share_percent ?? $existingUE?->our_share_percent ?? 0);\n        $taxPercent = (float) ($settings?->tax_percent ?? $existingUE?->tax_percent ?? 6);\n        $vatPercent = (float) ($settings?->vat_percent ?? $existingUE?->vat_percent ?? 0);\n        $acquiringPercent = (float) ($existingUE?->acquiring_percent ?? 1.5);\n        $storageCost = (float) ($marketplaceData['storage_cost'] ?? $product->storage_cost ?? $existingUE?->storage_cost ?? 0);""",
    """        $drrPercent = (float) ($settings?->drr_percent ?? $existingUE?->drr_percent ?? 0);\n        $ourSharePercent = (float) ($settings?->our_share_percent ?? $existingUE?->our_share_percent ?? 0);\n        $taxPercent = (float) ($settings?->tax_percent ?? $existingUE?->tax_percent ?? 6);\n        $vatPercent = (float) ($settings?->vat_percent ?? $existingUE?->vat_percent ?? 0);\n        $existingAcquiringPercent = $existingUE?->acquiring_percent;\n        $acquiringPercent = (float) ($marketplace === 'wildberries'\n            ? (($existingAcquiringPercent !== null && (float) $existingAcquiringPercent > 0) ? $existingAcquiringPercent : 1.5)\n            : ($existingAcquiringPercent ?? 0));\n        $storageCost = (float) ($marketplaceData['storage_cost'] ?? $product->storage_cost ?? $existingUE?->storage_cost ?? 0);""",
    'wb acquiring fallback',
)

updated = replace_once(
    updated,
    """    private function forgetSettingsCache(int $integrationId, string $sku): void\n    {\n        $key = $integrationId . '|' . $sku;\n        unset($this->settingsCache[$key]);\n    }""",
    """    private function forgetUnitEconomicsCache(int $integrationId, string $sku, ?string $fulfillmentType = null): void\n    {\n        if ($fulfillmentType !== null) {\n            $key = $integrationId . '|' . $sku . '|' . strtoupper($fulfillmentType);\n            unset($this->unitEconomicsCache[$key]);\n            return;\n        }\n\n        $prefix = $integrationId . '|' . $sku . '|';\n        foreach (array_keys($this->unitEconomicsCache) as $key) {\n            if (str_starts_with($key, $prefix)) {\n                unset($this->unitEconomicsCache[$key]);\n            }\n        }\n    }\n\n    private function forgetSettingsCache(int $integrationId, string $sku): void\n    {\n        $key = $integrationId . '|' . $sku;\n        unset($this->settingsCache[$key]);\n    }""",
    'insert forgetUnitEconomicsCache',
)

backup = path.with_name(path.name + '.bak_wb_cache_acquiring_fix_' + datetime.utcnow().strftime('%Y%m%d%H%M%S'))
backup.write_text(original)
path.write_text(updated)
print(f'patched {path} -> {backup.name}')
