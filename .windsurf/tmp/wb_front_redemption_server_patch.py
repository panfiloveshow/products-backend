from pathlib import Path
from datetime import datetime

base = Path('/var/www/products-backend')
files = {
    'controller': base / 'app/Http/Controllers/Api/UnitEconomicsCacheController.php',
    'sales_api': base / 'app/Domains/Wildberries/Api/SalesApi.php',
}
contents = {name: path.read_text() for name, path in files.items()}
updated = dict(contents)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 match, got {count}')
    return text.replace(old, new, 1)

updated['controller'] = replace_once(
    updated['controller'],
    """            'tax_percent' => 'nullable|numeric|min:0|max:100',\n            'vat_percent' => 'nullable|numeric|min:0|max:100',\n            'redemption_rate_override' => 'nullable|numeric|min:0|max:100',\n            // WB-специфичные""",
    """            'tax_percent' => 'nullable|numeric|min:0|max:100',\n            'vat_percent' => 'nullable|numeric|min:0|max:100',\n            'redemption_rate' => 'nullable|numeric|min:0|max:100',\n            'redemption_rate_override' => 'nullable|numeric|min:0|max:100',\n            // WB-специфичные""",
    'controller update validate',
)
updated['controller'] = replace_once(
    updated['controller'],
    """        $integrationId = $validated['integration_id'];\n        $localizationIndex = $validated['localization_index'] ?? null;\n        unset($validated['integration_id'], $validated['localization_index']);\n\n        // Если передан localization_index — обновляем интеграцию (это настройка магазина, не товара)""",
    """        $integrationId = $validated['integration_id'];\n        $localizationIndex = $validated['localization_index'] ?? null;\n        unset($validated['integration_id'], $validated['localization_index']);\n\n        if (array_key_exists('redemption_rate', $validated) && !array_key_exists('redemption_rate_override', $validated)) {\n            $validated['redemption_rate_override'] = $validated['redemption_rate'];\n        }\n        unset($validated['redemption_rate']);\n\n        // Если передан localization_index — обновляем интеграцию (это настройка магазина, не товара)""",
    'controller update alias',
)
updated['controller'] = replace_once(
    updated['controller'],
    """            'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',\n            'items.*.vat_percent' => 'nullable|numeric|min:0|max:100',\n            'items.*.redemption_rate_override' => 'nullable|numeric|min:0|max:100',\n            // Габариты (мм и г)""",
    """            'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',\n            'items.*.vat_percent' => 'nullable|numeric|min:0|max:100',\n            'items.*.redemption_rate' => 'nullable|numeric|min:0|max:100',\n            'items.*.redemption_rate_override' => 'nullable|numeric|min:0|max:100',\n            // Габариты (мм и г)""",
    'controller bulk validate',
)
updated['controller'] = replace_once(
    updated['controller'],
    """        foreach ($validated['items'] as $item) {\n            $sku = $item['sku'];\n            unset($item['sku']);\n            \n            UnitEconomicsSettings::updateOrCreate(""",
    """        foreach ($validated['items'] as $item) {\n            $sku = $item['sku'];\n            unset($item['sku']);\n\n            if (array_key_exists('redemption_rate', $item) && !array_key_exists('redemption_rate_override', $item)) {\n                $item['redemption_rate_override'] = $item['redemption_rate'];\n            }\n            unset($item['redemption_rate']);\n            \n            UnitEconomicsSettings::updateOrCreate(""",
    'controller bulk alias',
)

updated['sales_api'] = replace_once(
    updated['sales_api'],
    """    private function extractQuantity(array $item): int\n    {\n        return max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));\n    }\n\n    /**""",
    """    private function extractQuantity(array $item): int\n    {\n        return max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));\n    }\n\n    private function extractSrid(array $item): ?string\n    {\n        $srid = $item['srid'] ?? null;\n        return $srid !== null && $srid !== '' ? (string) $srid : null;\n    }\n\n    private function extractLastChangeTimestamp(array $item): int\n    {\n        $value = $item['lastChangeDate'] ?? $item['date'] ?? null;\n        $timestamp = $value ? strtotime((string) $value) : false;\n\n        return $timestamp !== false ? $timestamp : 0;\n    }\n\n    private function isReturnSale(array $item): bool\n    {\n        $saleId = strtoupper((string) ($item['saleID'] ?? ''));\n\n        if ($saleId !== '') {\n            if (str_starts_with($saleId, 'R')) {\n                return true;\n            }\n\n            if (str_starts_with($saleId, 'S')) {\n                return false;\n            }\n        }\n\n        return ((float) ($item['forPay'] ?? 0)) < 0\n            || ((float) ($item['priceWithDisc'] ?? 0)) < 0\n            || ((float) ($item['totalPrice'] ?? 0)) < 0;\n    }\n\n    /**""",
    'sales api helpers',
)
updated['sales_api'] = replace_once(
    updated['sales_api'],
    """            $salesByNmId = [];\n\n            foreach ($response ?? [] as $sale) {\n                $nmId = $this->extractNmId($sale);\n                if (!$nmId) {\n                    continue;\n                }\n\n                $quantity = $this->extractQuantity($sale);\n                $key = $nmId;\n\n                if (!isset($salesByNmId[$key])) {\n                    $salesByNmId[$key] = 0;\n                }\n\n                $salesByNmId[$key] += $quantity;\n            }\n\n            return $salesByNmId;""",
    """            $salesByNmId = [];\n            $salesStateByNmId = [];\n\n            foreach ($response ?? [] as $sale) {\n                $nmId = $this->extractNmId($sale);\n                if (!$nmId) {\n                    continue;\n                }\n\n                $srid = $this->extractSrid($sale);\n                $timestamp = $this->extractLastChangeTimestamp($sale);\n                $stateKey = $srid ?: md5(json_encode([\n                    $nmId,\n                    $sale['saleID'] ?? null,\n                    $sale['date'] ?? null,\n                    $sale['barcode'] ?? null,\n                ]));\n\n                if (!isset($salesStateByNmId[$nmId][$stateKey]) || $timestamp >= $salesStateByNmId[$nmId][$stateKey]['timestamp']) {\n                    $salesStateByNmId[$nmId][$stateKey] = [\n                        'timestamp' => $timestamp,\n                        'is_return' => $this->isReturnSale($sale),\n                    ];\n                }\n            }\n\n            foreach ($salesStateByNmId as $nmId => $states) {\n                $salesByNmId[$nmId] = collect($states)\n                    ->filter(fn (array $state) => !$state['is_return'])\n                    ->count();\n            }\n\n            return $salesByNmId;""",
    'sales api sales count',
)
updated['sales_api'] = replace_once(
    updated['sales_api'],
    """            $ordersByNmId = [];\n\n            foreach ($response ?? [] as $order) {\n                $nmId = $this->extractNmId($order);\n                if (!$nmId) {\n                    continue;\n                }\n\n                $quantity = $this->extractQuantity($order);\n                $key = $nmId;\n\n                if (!isset($ordersByNmId[$key])) {\n                    $ordersByNmId[$key] = 0;\n                }\n\n                $ordersByNmId[$key] += $quantity;\n            }\n\n            return $ordersByNmId;""",
    """            $ordersByNmId = [];\n            $orderStateByNmId = [];\n\n            foreach ($response ?? [] as $order) {\n                $nmId = $this->extractNmId($order);\n                if (!$nmId) {\n                    continue;\n                }\n\n                $srid = $this->extractSrid($order);\n                $timestamp = $this->extractLastChangeTimestamp($order);\n                $stateKey = $srid ?: md5(json_encode([\n                    $nmId,\n                    $order['date'] ?? null,\n                    $order['barcode'] ?? null,\n                    $order['gNumber'] ?? null,\n                ]));\n\n                if (!isset($orderStateByNmId[$nmId][$stateKey]) || $timestamp >= $orderStateByNmId[$nmId][$stateKey]['timestamp']) {\n                    $orderStateByNmId[$nmId][$stateKey] = [\n                        'timestamp' => $timestamp,\n                        'is_cancel' => (bool) ($order['isCancel'] ?? false),\n                    ];\n                }\n            }\n\n            foreach ($orderStateByNmId as $nmId => $states) {\n                $ordersByNmId[$nmId] = collect($states)\n                    ->filter(fn (array $state) => !$state['is_cancel'])\n                    ->count();\n            }\n\n            return $ordersByNmId;""",
    'sales api orders count',
)

stamp = datetime.utcnow().strftime('%Y%m%d%H%M%S')
for key, path in files.items():
    backup = path.with_name(path.name + f'.bak_wb_front_redemption_{stamp}')
    backup.write_text(contents[key])
    path.write_text(updated[key])
    print(f'patched {path} -> {backup.name}')
