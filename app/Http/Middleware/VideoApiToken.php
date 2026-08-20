<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Cong token cho toan bo API ma Python goi. Truoc day phep kiem nay nam trong
 * `VideoSessionController::checkToken()` va duoc goi lai o MUOI phuong thuc —
 * quen mot dong la mo toang mot endpoint, va khong co test nao bat duoc vi
 * `VideoApiTokenTest` chi cham toi 2 trong 10 route.
 *
 * Dat o tang middleware thi route moi khong con co hoi quen: no thua cong nay
 * ngay khi duoc them vao nhom.
 */
class VideoApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = config('video.api_token');

        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if (! hash_equals($token, (string) $request->header('X-Video-Token'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
