<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Models\SupplyPackage;
use App\Models\SupplyDocument;
use App\Models\SupplyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class SupplyDocumentController extends Controller
{
    /**
     * Получить список документов поставки
     * GET /api/supplies/{supplyId}/documents
     */
    public function index(int $supplyId): JsonResponse
    {
        $supply = Supply::with(['documents'])->findOrFail($supplyId);

        return response()->json([
            'success' => true,
            'data' => $supply->documents->map(fn($doc) => $this->formatDocument($doc)),
            'summary' => [
                'total' => $supply->documents->count(),
                'by_type' => $supply->documents->groupBy('document_type')->map->count(),
                'by_status' => $supply->documents->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * Получить документ
     * GET /api/supplies/{supplyId}/documents/{documentId}
     */
    public function show(int $supplyId, int $documentId): JsonResponse
    {
        $document = SupplyDocument::where('supply_id', $supplyId)->findOrFail($documentId);

        return response()->json([
            'success' => true,
            'data' => $this->formatDocument($document),
        ]);
    }

    /**
     * Скачать документ
     * GET /api/supplies/{supplyId}/documents/{documentId}/download
     */
    public function download(int $supplyId, int $documentId)
    {
        $document = SupplyDocument::where('supply_id', $supplyId)->findOrFail($documentId);

        if (!$document->isReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Документ не готов для скачивания',
            ], 422);
        }

        if ($document->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Срок действия документа истёк',
            ], 422);
        }

        $document->incrementDownloadCount();

        if ($document->file_url) {
            return redirect($document->file_url);
        }

        if ($document->file_path && Storage::exists($document->file_path)) {
            return Storage::download($document->file_path, $document->document_name . '.' . $document->format);
        }

        return response()->json([
            'success' => false,
            'message' => 'Файл не найден',
        ], 404);
    }

    /**
     * Сгенерировать этикетку для грузоместа
     * POST /api/supplies/{supplyId}/packages/{packageId}/label
     */
    public function generatePackageLabel(Request $request, int $supplyId, int $packageId): JsonResponse
    {
        $package = SupplyPackage::with(['supply', 'items'])->where('supply_id', $supplyId)->findOrFail($packageId);
        $supply = $package->supply;

        $request->validate([
            'format' => 'nullable|in:pdf,png,zpl',
        ]);

        $format = $request->input('format', 'pdf');

        // Проверяем, есть ли уже этикетка
        $existingLabel = SupplyDocument::where('supply_id', $supplyId)
            ->where('package_id', $packageId)
            ->where('document_type', SupplyDocument::TYPE_PACKAGE_LABEL)
            ->where('format', $format)
            ->where('status', SupplyDocument::STATUS_READY)
            ->first();

        if ($existingLabel && !$request->boolean('regenerate')) {
            return response()->json([
                'success' => true,
                'data' => $this->formatDocument($existingLabel),
                'message' => 'Этикетка уже существует',
            ]);
        }

        // Создаём запись документа
        $document = SupplyDocument::create([
            'supply_id' => $supplyId,
            'package_id' => $packageId,
            'document_type' => SupplyDocument::TYPE_PACKAGE_LABEL,
            'document_name' => "Этикетка_{$package->barcode}",
            'format' => $format,
            'source' => SupplyDocument::SOURCE_SYSTEM,
            'barcode_data' => $package->barcode,
            'barcode_type' => 'Code128',
            'status' => SupplyDocument::STATUS_GENERATING,
            'generated_by' => auth()->id(),
        ]);

        try {
            $filePath = $this->generateLabelFile($package, $supply, $format);
            
            $document->markAsReady(
                $filePath,
                Storage::exists($filePath) ? Storage::size($filePath) : null
            );

            // Обновляем статус грузоместа
            if ($package->status === SupplyPackage::STATUS_PACKED) {
                $package->markAsLabeled();
            }

            $this->logEvent($supply, SupplyEvent::TYPE_DOCUMENT_GENERATED, 'Сгенерирована этикетка грузоместа', [
                'package_id' => $package->id,
                'barcode' => $package->barcode,
                'format' => $format,
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatDocument($document->fresh()),
                'message' => 'Этикетка сгенерирована',
            ]);

        } catch (\Exception $e) {
            $document->markAsError($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации этикетки: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сгенерировать все этикетки для поставки
     * POST /api/supplies/{supplyId}/labels/generate-all
     */
    public function generateAllLabels(Request $request, int $supplyId): JsonResponse
    {
        $supply = Supply::with(['packages'])->findOrFail($supplyId);

        $request->validate([
            'format' => 'nullable|in:pdf,png,zpl',
            'statuses' => 'nullable|array',
            'statuses.*' => 'in:packed,labeled,ready',
        ]);

        $format = $request->input('format', 'pdf');
        $statuses = $request->input('statuses', [SupplyPackage::STATUS_PACKED]);

        $packages = $supply->packages()->whereIn('status', $statuses)->get();

        if ($packages->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Нет грузомест для генерации этикеток',
            ], 422);
        }

        $generated = [];
        $errors = [];

        foreach ($packages as $package) {
            try {
                // Проверяем существующую этикетку
                $existingLabel = SupplyDocument::where('supply_id', $supplyId)
                    ->where('package_id', $package->id)
                    ->where('document_type', SupplyDocument::TYPE_PACKAGE_LABEL)
                    ->where('format', $format)
                    ->where('status', SupplyDocument::STATUS_READY)
                    ->first();

                if ($existingLabel) {
                    $generated[] = $this->formatDocument($existingLabel);
                    continue;
                }

                $document = SupplyDocument::create([
                    'supply_id' => $supplyId,
                    'package_id' => $package->id,
                    'document_type' => SupplyDocument::TYPE_PACKAGE_LABEL,
                    'document_name' => "Этикетка_{$package->barcode}",
                    'format' => $format,
                    'source' => SupplyDocument::SOURCE_SYSTEM,
                    'barcode_data' => $package->barcode,
                    'barcode_type' => 'Code128',
                    'status' => SupplyDocument::STATUS_GENERATING,
                    'generated_by' => auth()->id(),
                ]);

                $filePath = $this->generateLabelFile($package, $supply, $format);
                $document->markAsReady($filePath, Storage::exists($filePath) ? Storage::size($filePath) : null);

                if ($package->status === SupplyPackage::STATUS_PACKED) {
                    $package->markAsLabeled();
                }

                $generated[] = $this->formatDocument($document->fresh());

            } catch (\Exception $e) {
                $errors[] = [
                    'package_id' => $package->id,
                    'barcode' => $package->barcode,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->logEvent($supply, SupplyEvent::TYPE_DOCUMENT_GENERATED, 'Массовая генерация этикеток', [
            'generated_count' => count($generated),
            'errors_count' => count($errors),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'generated' => $generated,
                'errors' => $errors,
            ],
            'message' => 'Сгенерировано ' . count($generated) . ' этикеток',
        ]);
    }

    /**
     * Сгенерировать упаковочный лист
     * POST /api/supplies/{supplyId}/documents/packing-list
     */
    public function generatePackingList(int $supplyId): JsonResponse
    {
        $supply = Supply::with(['items', 'packages.items', 'integration'])->findOrFail($supplyId);

        $document = SupplyDocument::create([
            'supply_id' => $supplyId,
            'document_type' => SupplyDocument::TYPE_PACKING_LIST,
            'document_name' => "Упаковочный_лист_{$supply->crm_number}",
            'format' => 'pdf',
            'source' => SupplyDocument::SOURCE_SYSTEM,
            'status' => SupplyDocument::STATUS_GENERATING,
            'generated_by' => auth()->id(),
        ]);

        try {
            $filePath = $this->generatePackingListFile($supply);
            $document->markAsReady($filePath, Storage::exists($filePath) ? Storage::size($filePath) : null);

            $this->logEvent($supply, SupplyEvent::TYPE_DOCUMENT_GENERATED, 'Сгенерирован упаковочный лист');

            return response()->json([
                'success' => true,
                'data' => $this->formatDocument($document->fresh()),
                'message' => 'Упаковочный лист сгенерирован',
            ]);

        } catch (\Exception $e) {
            $document->markAsError($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Генерация файла этикетки
     */
    private function generateLabelFile(SupplyPackage $package, Supply $supply, string $format): string
    {
        $data = [
            'package' => $package,
            'supply' => $supply,
            'barcode' => $package->barcode,
            'items' => $package->items,
            'generated_at' => now()->format('d.m.Y H:i'),
        ];

        $directory = "supply_documents/{$supply->id}/labels";
        Storage::makeDirectory($directory);

        // Защита от path-traversal: barcode приходит от маркетплейса/пользователя
        // и теоретически может содержать «../», backslash, null-byte. Используем
        // slug как имя файла, оригинал barcode остаётся в БД (package->barcode).
        $safeBarcode = $this->safeFilenameFragment($package->barcode ?? (string) $package->id);
        $filename = "{$directory}/label_{$safeBarcode}.{$format}";

        if ($format === 'pdf') {
            $html = $this->renderLabelHtml($data);
            $pdf = Pdf::loadHTML($html)->setPaper([0, 0, 283.46, 425.20], 'portrait'); // 100x150mm
            Storage::put($filename, $pdf->output());
        } elseif ($format === 'zpl') {
            $zpl = $this->generateZplLabel($data);
            Storage::put($filename, $zpl);
        } else {
            // PNG - используем простой HTML to image или placeholder
            $html = $this->renderLabelHtml($data);
            Storage::put($filename, $html); // Временно сохраняем HTML
        }

        return $filename;
    }

    /**
     * Генерация HTML для этикетки
     */
    private function renderLabelHtml(array $data): string
    {
        $package = $data['package'];
        $supply = $data['supply'];
        $barcode = $data['barcode'];

        $itemsList = '';
        foreach ($data['items'] as $item) {
            $itemsList .= "<tr><td>{$item->sku}</td><td>{$item->quantity}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; font-size: 12px; }
        .header { text-align: center; font-weight: bold; font-size: 14px; margin-bottom: 10px; }
        .barcode { text-align: center; margin: 15px 0; }
        .barcode-text { font-family: monospace; font-size: 16px; letter-spacing: 2px; }
        .info { margin: 10px 0; }
        .info-row { display: flex; justify-content: space-between; margin: 3px 0; }
        .label { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: left; font-size: 10px; }
        th { background: #f5f5f5; }
        .footer { margin-top: 15px; font-size: 10px; color: #666; text-align: center; }
    </style>
</head>
<body>
    <div class="header">ГРУЗОМЕСТО #{$package->sequence_number}</div>
    
    <div class="barcode">
        <div class="barcode-text">{$barcode}</div>
        <div style="margin-top: 5px; font-size: 10px;">*{$barcode}*</div>
    </div>
    
    <div class="info">
        <div class="info-row"><span class="label">Поставка:</span> <span>{$supply->crm_number}</span></div>
        <div class="info-row"><span class="label">Склад:</span> <span>{$supply->warehouse_name}</span></div>
        <div class="info-row"><span class="label">Тип:</span> <span>{$package->package_type}</span></div>
        <div class="info-row"><span class="label">Позиций:</span> <span>{$package->items_count}</span></div>
        <div class="info-row"><span class="label">Кол-во:</span> <span>{$package->total_quantity} шт.</span></div>
        <div class="info-row"><span class="label">Вес:</span> <span>{$package->weight} кг</span></div>
    </div>
    
    <table>
        <thead><tr><th>SKU</th><th>Кол-во</th></tr></thead>
        <tbody>{$itemsList}</tbody>
    </table>
    
    <div class="footer">Сгенерировано: {$data['generated_at']}</div>
</body>
</html>
HTML;
    }

    /**
     * Генерация ZPL для принтера этикеток
     */
    private function generateZplLabel(array $data): string
    {
        $package = $data['package'];
        $barcode = $data['barcode'];

        return <<<ZPL
^XA
^FO50,30^A0N,30,30^FDГРУЗОМЕСТО #{$package->sequence_number}^FS
^FO50,80^BY3^BCN,100,Y,N,N^FD{$barcode}^FS
^FO50,220^A0N,20,20^FDПозиций: {$package->items_count}^FS
^FO50,250^A0N,20,20^FDКол-во: {$package->total_quantity} шт.^FS
^FO50,280^A0N,20,20^FDВес: {$package->weight} кг^FS
^XZ
ZPL;
    }

    /**
     * Генерация упаковочного листа
     */
    private function generatePackingListFile(Supply $supply): string
    {
        $directory = "supply_documents/{$supply->id}";
        Storage::makeDirectory($directory);
        // crm_number теоретически приходит от маркетплейса/Sellico — slug защищает от path-traversal.
        $safeCrmNumber = $this->safeFilenameFragment($supply->crm_number ?? (string) $supply->id);
        $filename = "{$directory}/packing_list_{$safeCrmNumber}.pdf";

        $packagesHtml = '';
        foreach ($supply->packages as $package) {
            $itemsHtml = '';
            foreach ($package->items as $item) {
                $itemsHtml .= "<tr><td>{$item->sku}</td><td>{$item->product_name}</td><td>{$item->quantity}</td></tr>";
            }
            
            $packagesHtml .= <<<HTML
            <div class="package">
                <h3>Грузоместо #{$package->sequence_number} ({$package->barcode})</h3>
                <p>Тип: {$package->package_type} | Вес: {$package->weight} кг | Позиций: {$package->items_count}</p>
                <table>
                    <thead><tr><th>SKU</th><th>Наименование</th><th>Кол-во</th></tr></thead>
                    <tbody>{$itemsHtml}</tbody>
                </table>
            </div>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 20px; }
        h2 { font-size: 14px; color: #333; }
        h3 { font-size: 12px; margin: 15px 0 5px; background: #f5f5f5; padding: 5px; }
        .info { margin-bottom: 20px; }
        .info-row { margin: 5px 0; }
        .label { color: #666; display: inline-block; width: 150px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
        th { background: #f0f0f0; }
        .package { margin-bottom: 20px; page-break-inside: avoid; }
        .summary { margin-top: 30px; padding-top: 20px; border-top: 2px solid #333; }
    </style>
</head>
<body>
    <h1>УПАКОВОЧНЫЙ ЛИСТ</h1>
    
    <div class="info">
        <div class="info-row"><span class="label">Номер поставки:</span> {$supply->crm_number}</div>
        <div class="info-row"><span class="label">Ozon ID:</span> {$supply->ozon_supply_id}</div>
        <div class="info-row"><span class="label">Склад:</span> {$supply->warehouse_name}</div>
        <div class="info-row"><span class="label">Дата поставки:</span> {$supply->timeslot_from?->format('d.m.Y H:i')}</div>
        <div class="info-row"><span class="label">Всего грузомест:</span> {$supply->packages->count()}</div>
        <div class="info-row"><span class="label">Всего товаров:</span> {$supply->total_quantity} шт.</div>
    </div>
    
    <h2>СОСТАВ ГРУЗОМЕСТ</h2>
    {$packagesHtml}
    
    <div class="summary">
        <h2>ИТОГО</h2>
        <p>Грузомест: {$supply->packages->count()}</p>
        <p>Позиций: {$supply->items->count()}</p>
        <p>Единиц товара: {$supply->total_quantity}</p>
        <p>Общий вес: {$supply->packages->sum('weight')} кг</p>
    </div>
    
    <div style="margin-top: 50px;">
        <p>Дата формирования: {$supply->created_at->format('d.m.Y H:i')}</p>
        <p style="margin-top: 30px;">Подпись ответственного: _______________________</p>
    </div>
</body>
</html>
HTML;

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        Storage::put($filename, $pdf->output());

        return $filename;
    }

    /**
     * Форматирование документа для ответа
     */
    private function formatDocument(SupplyDocument $document): array
    {
        return [
            'id' => $document->id,
            'supply_id' => $document->supply_id,
            'package_id' => $document->package_id,
            'document_type' => $document->document_type,
            'document_type_label' => SupplyDocument::getDocumentTypes()[$document->document_type] ?? $document->document_type,
            'document_name' => $document->document_name,
            'format' => $document->format,
            'source' => $document->source,
            'file_size' => $document->file_size,
            'file_size_human' => $document->file_size_human,
            'barcode_data' => $document->barcode_data,
            'status' => $document->status,
            'error_message' => $document->error_message,
            'generated_at' => $document->generated_at?->toIso8601String(),
            'downloaded_count' => $document->downloaded_count,
            'expires_at' => $document->expires_at?->toIso8601String(),
            'download_url' => $document->isReady() 
                ? route('api.supplies.documents.download', ['supplyId' => $document->supply_id, 'documentId' => $document->id])
                : null,
            'created_at' => $document->created_at->toIso8601String(),
        ];
    }

    /**
     * Логирование события
     */
    private function logEvent(Supply $supply, string $type, string $title, array $changes = []): void
    {
        SupplyEvent::create([
            'supply_id' => $supply->id,
            'event_type' => $type,
            'title' => $title,
            'changes' => $changes,
            'initiated_by' => SupplyEvent::INITIATED_BY_USER,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Безопасный фрагмент имени файла — только a-z, 0-9, "-" и "_".
     * Гарантирует, что barcode / crm_number из внешнего источника не сможет
     * попасть в path через «../», backslash, null-byte или unicode-сепараторы.
     */
    private function safeFilenameFragment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');
        return $value !== '' ? substr($value, 0, 64) : 'unknown';
    }
}
