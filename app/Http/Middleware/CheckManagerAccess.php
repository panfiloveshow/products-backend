<?php

namespace App\Http\Middleware;

use App\Services\Manager\ManagerIdentity;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Strict access for manager actions, including in development and command previews. */
final class CheckManagerAccess
{
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        try {
            $identity = app(\App\Services\Manager\ManagerSessionVerifier::class)->verify($request, $required);
        } catch (\App\Services\Manager\ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        }
        $workspace = $identity->workspaceId;

        CurrentWorkspace::bind($request, $workspace);
        $request->attributes->set(ManagerIdentity::ATTRIBUTE, $identity);
        try {
            return $next($request);
        } finally {
            CurrentWorkspace::forget($request);
            $request->attributes->remove(ManagerIdentity::ATTRIBUTE);
        }
    }
}
