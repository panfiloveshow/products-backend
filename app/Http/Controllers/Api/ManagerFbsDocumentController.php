<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsDocumentService;
use App\Services\Manager\ManagerIdentity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManagerFbsDocumentController extends Controller
{
    public function __construct(private ManagerFbsDocumentService $documents) {}

    public function prepare(Request $request): Response
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'external_id'])) abort(422);
        $input = $request->validate(['integration_id' => 'required|integer|min:1', 'external_id' => 'required|string|max:100']);

        return $this->guard(fn () => response()->json($this->documents->prepare($request->attributes->get(ManagerIdentity::ATTRIBUTE), (int) $input['integration_id'], $input['external_id'])));
    }

    public function download(Request $request): Response
    {
        if (array_diff(array_keys($request->all()), ['download_ticket'])) {
            abort(422);
        }
        $input = $request->validate(['download_ticket' => 'required|string|max:8000']);

        return $this->guard(function () use ($request, $input) {
            $file = $this->documents->download($request->attributes->get(ManagerIdentity::ATTRIBUTE), $input['download_ticket'],
                fn () => app(\App\Services\Manager\ManagerSessionVerifier::class)->verify($request, ['auto_supply.view', 'auto_supply.export', 'integrations.view']));
            if (isset($file['document_status'])) {
                $status = $file['http_status']; unset($file['http_status']);
                return response()->json($file, $status, $status === 202 ? ['Retry-After' => (string) $file['retry_after_seconds'], 'Cache-Control' => 'private, no-store'] : ['Cache-Control' => 'private, no-store']);
            }

            return response($file['bytes'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
        });
    }

    private function guard(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Документ временно недоступен.'], 503);
        }
    }
}
