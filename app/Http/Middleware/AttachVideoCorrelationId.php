<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttachVideoCorrelationId
{
    private const VALID_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    public function handle(Request $request, Closure $next)
    {
        $provided = $request->header('X-Correlation-Id');
        $correlationId = ($provided && preg_match(self::VALID_PATTERN, $provided))
            ? $provided
            : (string) Str::uuid();
        $request->attributes->set('video_correlation_id', $correlationId);
        Log::shareContext(['correlation_id' => $correlationId]);

        return $next($request)->header('X-Correlation-Id', $correlationId);
    }
}
