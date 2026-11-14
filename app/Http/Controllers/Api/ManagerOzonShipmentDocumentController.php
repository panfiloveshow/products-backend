<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerOzonShipmentDocumentService;
use App\Services\Manager\ManagerSessionVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManagerOzonShipmentDocumentController extends Controller
{
    public function __construct(private ManagerOzonShipmentDocumentService $documents) {}

    public function index(Request $request): Response
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'date_from', 'date_to'])) abort(422);
        $input = $request->validate(['integration_id' => 'required|integer|min:1', 'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d']);
        return $this->guard(fn () => response()->json($this->documents->listing($request->attributes->get(ManagerIdentity::ATTRIBUTE), (int) $input['integration_id'], $input['date_from'], $input['date_to'])));
    }

    public function prepare(Request $request): Response
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'carriage_id'])) abort(422);
        $input = $request->validate(['integration_id' => 'required|integer|min:1', 'carriage_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']]);
        return $this->guard(fn () => response()->json($this->documents->prepare($request->attributes->get(ManagerIdentity::ATTRIBUTE), (int) $input['integration_id'], $input['carriage_id'])));
    }

    public function download(Request $request): Response
    {
        if (array_diff(array_keys($request->all()), ['download_ticket'])) abort(422);
        $input = $request->validate(['download_ticket' => 'required|string|max:8000']);
        return $this->guard(function () use ($request, $input) {
            $file = $this->documents->download($request->attributes->get(ManagerIdentity::ATTRIBUTE), $input['download_ticket'],
                fn () => app(ManagerSessionVerifier::class)->verify($request, ['auto_supply.view', 'auto_supply.export', 'integrations.view']));
            if (isset($file['document_status'])) {
                $status = $file['http_status']; unset($file['http_status']);
                return response()->json($file, $status, ['Cache-Control' => 'private, no-store', ...($status === 202 ? ['Retry-After' => (string) $file['retry_after_seconds']] : [])]);
            }
            return response($file['bytes'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
        });
    }

    private function guard(callable $callback): Response
    {
        try {
            $response = $callback();
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }
        catch (ManagerDataException $error) { return response()->json(['message' => $error->getMessage()], $error->httpStatus, ['Cache-Control' => 'private, no-store']); }
        catch (\Throwable) { return response()->json(['message' => 'Документы отгрузки временно недоступны.'], 503, ['Cache-Control' => 'private, no-store']); }
    }
}
