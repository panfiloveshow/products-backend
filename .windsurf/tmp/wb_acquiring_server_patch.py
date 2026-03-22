from pathlib import Path
from datetime import datetime

base = Path('/var/www/products-backend')
files = {
    'service': base / 'app/Services/UnitEconomicsService.php',
    'wb_calc': base / 'app/Domains/Wildberries/UnitEconomics/WildberriesUnitEconomicsCalculator.php',
    'cache_service': base / 'app/Services/UnitEconomicsCacheService.php',
    'cache_controller': base / 'app/Http/Controllers/Api/UnitEconomicsCacheController.php',
    'sync_cmd': base / 'app/Console/Commands/SyncUnitEconomicsCommand.php',
}

contents = {name: path.read_text() for name, path in files.items()}
updated = dict(contents)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 match, got {count}')
    return text.replace(old, new, 1)


updated['service'] = replace_once(
    updated['service'],
    """        $expectedReturnCost = $returnLogisticsCost * ((100 - $redemptionRate) / 100);\n\n        $sppRub = ($price * $sppPercent / 100) * $salesCount;""",
    """        $expectedReturnCost = $returnLogisticsCost * ((100 - $redemptionRate) / 100);\n\n        $acquiringPercent = (float) ($data['acquiring_percent'] ?? 1.5);\n        $acquiringAmount = ($price * $acquiringPercent / 100) * $salesCount;\n\n        $sppRub = ($price * $sppPercent / 100) * $salesCount;""",
    'service acquiring block',
)
updated['service'] = replace_once(
    updated['service'],
    """        $totalFees = $commissionAmount + $storageCost + $logisticsCost + \n                     $acceptanceCost + $penaltyCost + $expectedReturnCost + $ksRub;""",
    """        $totalFees = $commissionAmount + $storageCost + $logisticsCost + \n                     $acceptanceCost + $penaltyCost + $expectedReturnCost + $acquiringAmount + $ksRub;""",
    'service total fees',
)
updated['service'] = replace_once(
    updated['service'],
    """                'effective_logistics' => round($effectiveLogistics, 2),\n                'spp_percent' => $sppPercent,""",
    """                'effective_logistics' => round($effectiveLogistics, 2),\n                'acquiring_percent' => round($acquiringPercent, 2),\n                'acquiring_amount' => round($acquiringAmount, 2),\n                'spp_percent' => $sppPercent,""",
    'service details',
)

updated['wb_calc'] = replace_once(
    updated['wb_calc'],
    """        $acquiringRate = 0;\n        $acquiring = 0;""",
    """        $acquiringRate = $options['acquiring_percent'] ?? $input->acquiringPercent ?? 1.5;\n        $acquiring = $price * ($acquiringRate / 100);""",
    'wb calc acquiring',
)
updated['wb_calc'] = replace_once(
    updated['wb_calc'],
    """        $marketplaceCosts = $commission + $logistics + $expectedReturnCost + $storageCost + $acceptanceCost + $penaltyCost;""",
    """        $marketplaceCosts = $commission + $logistics + $expectedReturnCost + $storageCost + $acceptanceCost + $penaltyCost + $acquiring;""",
    'wb calc marketplace costs',
)
updated['wb_calc'] = replace_once(
    updated['wb_calc'],
    """            'redemption_rate' => $redemptionRate,\n            'volume_liters' => round($volumeInLiters, 4),""",
    """            'redemption_rate' => $redemptionRate,\n            'acquiring_percent' => round($acquiringRate, 2),\n            'acquiring_amount' => round($acquiring, 2),\n            'volume_liters' => round($volumeInLiters, 4),""",
    'wb calc result fields',
)

updated['cache_service'] = replace_once(
    updated['cache_service'],
    """        $acquiringPercent = (float) ($marketplace === 'wildberries' ? 0 : ($existingUE?->acquiring_percent ?? 1.5));""",
    """        $acquiringPercent = (float) ($existingUE?->acquiring_percent ?? 1.5);""",
    'cache service acquiring percent',
)

updated['cache_controller'] = replace_once(
    updated['cache_controller'],
    """            $data['acquiring_percent'] = 0.0;\n            $data['acquiring_amount'] = 0.0;\n            $data['acquiring_per_unit'] = 0.0;""",
    """            $data['acquiring_percent'] = round((float) ($data['acquiring_percent'] ?? $cache->acquiring_percent ?? 1.5), 2);\n            $data['acquiring_amount'] = round((float) ($data['acquiring_amount'] ?? $cache->acquiring_amount ?? ($price * $data['acquiring_percent'] / 100)), 2);\n            $data['acquiring_per_unit'] = round((float) ($data['acquiring_per_unit'] ?? ($data['acquiring_amount'] ?? 0)), 2);""",
    'cache controller wb zero block',
)
updated['cache_controller'] = replace_once(
    updated['cache_controller'],
    """            $totalExpenses = $commissionAmount + (float) $cache->logistics_cost + \n                             $expectedReturnCost + (float) $cache->storage_cost;""",
    """            $totalExpenses = $commissionAmount + (float) $cache->logistics_cost + \n                             $expectedReturnCost + (float) $cache->storage_cost + (float) ($data['acquiring_amount'] ?? 0);""",
    'cache controller total expenses',
)
updated['cache_controller'] = replace_once(
    updated['cache_controller'],
    """            $toSettlement = $customerPrice - $commissionAmount - \n                           (float) $cache->logistics_cost - $expectedReturnCost - (float) $cache->storage_cost;""",
    """            $toSettlement = $customerPrice - $commissionAmount - \n                           (float) $cache->logistics_cost - $expectedReturnCost - (float) $cache->storage_cost - (float) ($data['acquiring_amount'] ?? 0);""",
    'cache controller to settlement',
)

updated['sync_cmd'] = replace_once(
    updated['sync_cmd'],
    """                    $ourShareRecord = (clone $baseQuery)->where('our_share_percent', '>', 0)->first();\n                    \n                    // Используем ручные значения: сначала из текущей схемы, потом из любой другой""",
    """                    $ourShareRecord = (clone $baseQuery)->where('our_share_percent', '>', 0)->first();\n                    $acquiringRecord = (clone $baseQuery)->whereNotNull('acquiring_percent')->where('acquiring_percent', '>', 0)->first();\n                    \n                    // Используем ручные значения: сначала из текущей схемы, потом из любой другой""",
    'sync command acquiring record',
)
updated['sync_cmd'] = replace_once(
    updated['sync_cmd'],
    """                    if ($existing && $existing->our_share_percent > 0) {\n                        $data['our_share_percent'] = (float) $existing->our_share_percent;\n                    } elseif ($ourShareRecord) {\n                        $data['our_share_percent'] = (float) $ourShareRecord->our_share_percent;\n                    }\n                    // Налоги — берём из текущей схемы если есть""",
    """                    if ($existing && $existing->our_share_percent > 0) {\n                        $data['our_share_percent'] = (float) $existing->our_share_percent;\n                    } elseif ($ourShareRecord) {\n                        $data['our_share_percent'] = (float) $ourShareRecord->our_share_percent;\n                    }\n                    \n                    if ($existing && $existing->acquiring_percent !== null && $existing->acquiring_percent > 0) {\n                        $data['acquiring_percent'] = (float) $existing->acquiring_percent;\n                    } elseif ($acquiringRecord) {\n                        $data['acquiring_percent'] = (float) $acquiringRecord->acquiring_percent;\n                    }\n                    // Налоги — берём из текущей схемы если есть""",
    'sync command acquiring carry over',
)
updated['sync_cmd'] = replace_once(
    updated['sync_cmd'],
    """                    if (isset($data['our_share_percent']) && $data['our_share_percent'] > 0) {\n                        $saveData['our_share_percent'] = $data['our_share_percent'];\n                    }\n                    \n                    $saveData = array_merge($saveData, $detailed);""",
    """                    if (isset($data['our_share_percent']) && $data['our_share_percent'] > 0) {\n                        $saveData['our_share_percent'] = $data['our_share_percent'];\n                    }\n                    if (isset($data['acquiring_percent']) && $data['acquiring_percent'] > 0) {\n                        $saveData['acquiring_percent'] = $data['acquiring_percent'];\n                    }\n                    \n                    $saveData = array_merge($saveData, $detailed);""",
    'sync command acquiring save',
)
updated['sync_cmd'] = replace_once(
    updated['sync_cmd'],
    """                // === ЭКВАЙРИНГ (WB: 0%) ===\n                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? 0,\n                'acquiring_amount' => $calculated['acquiring_amount'] ?? null,""",
    """                // === ЭКВАЙРИНГ (WB: 0%) ===\n                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? 1.5,\n                'acquiring_amount' => $calculated['acquiring_amount'] ?? null,""",
    'sync command wb fallback',
)

stamp = datetime.utcnow().strftime('%Y%m%d%H%M%S')
for key, path in files.items():
    backup = path.with_name(path.name + f'.bak_wb_acq_{stamp}')
    backup.write_text(contents[key])
    path.write_text(updated[key])
    print(f'patched {path} -> {backup.name}')
