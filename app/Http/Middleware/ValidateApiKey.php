<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simple API-key gate for the MarTech dashboard backend.
 *
 * The dashboard frontend (Next.js) calls the API from its server side only
 * and forwards this header; the key is never exposed to the browser.
 */
class ValidateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->header('X-API-Key', '');
        $expected = (string) config('services.dashboard.api_key', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
