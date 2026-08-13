<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();

            if (! $this->isVideoApiRequest($request)) {
                return;
            }

            Log::error('video_api_exception', [
                'correlation_id' => $request->attributes->get('video_correlation_id'),
                'method' => $request->method(),
                'path' => $request->path(),
                'shot_id' => $request->route('shotId'),
                'final_id' => $request->route('finalId'),
                'session_code' => $request->input('session_code') ?? $request->query('session_code'),
                'exception' => $e,
            ]);

            return false;
        });
    }

    private function isVideoApiRequest($request): bool
    {
        return $request->is(
            'api/render-plans',
            'api/video-sessions/*',
            'api/video-shots/*',
            'api/video-finals/*',
        );
    }
}
