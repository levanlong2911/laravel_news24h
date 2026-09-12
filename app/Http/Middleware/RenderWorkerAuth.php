<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RenderWorkerAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = config('video.render_worker_token');

        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $header = (string) $request->bearerToken();

        if (! hash_equals($token, $header)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}

