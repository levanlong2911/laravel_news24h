<?php

namespace App\Support;

final class RequestBudget
{
    private const REQUEST_CAP_S   = 50;
    private const SAFETY_MARGIN_S = 5;

    public static function remainingSeconds(): float
    {
        if (app()->runningInConsole()) {
            return INF;
        }

        $elapsed   = defined('LARAVEL_START') ? microtime(true) - LARAVEL_START : 0.0;
        $remaining = self::REQUEST_CAP_S - $elapsed;

        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $remaining = min($remaining, $limit - $elapsed - self::SAFETY_MARGIN_S);
        }

        return max(0.0, $remaining);
    }
}
