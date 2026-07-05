<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend-to-backend guard for the repricer unit-economics export.
 * The ads-intelligence (repricer) service authenticates with a shared secret
 * (REPRICER_SERVICE_TOKEN), not a per-user Sellico permission — this is a
 * trusted service pull, not a user request.
 */
class CheckRepricerServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.repricer.token', env('REPRICER_SERVICE_TOKEN', ''));
        $got = (string) ($request->bearerToken() ?? $request->header('X-Repricer-Token') ?? '');

        if ($expected === '' || $got === '' || !hash_equals($expected, $got)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
