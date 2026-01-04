<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class DomainContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $plainKey = $request->header('X-Api-Key');

        if (!$plainKey) {
            return $this->reject(401);
        }

        // ⚠️ Rate limit theo API key (chống spam)
        $rateKey = 'api:' . sha1($plainKey);

        if (RateLimiter::tooManyAttempts($rateKey, 300)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests',
            ], 429);
        }

        RateLimiter::hit($rateKey, 60);

        $hash = hash('sha256', $plainKey);

        $domain = Domain::query()
            ->where('api_key', $hash)
            ->where('is_active', true)
            ->first();

        if (!$domain) {
            return $this->reject(403);
        }

        // ✅ Attach domain to request
        $request->attributes->set('domain', $domain);

        return $next($request);
    }

    protected function reject(int $status)
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], $status);
    }

}
