<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(2000)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

        $this->configureRateLimiting();
    }

    /**
     * Chặn thô theo IP cho trang đăng nhập.
     *
     * Chỉ để chống flood. Việc khoá theo tài khoản do LoginService lo, và nó chỉ
     * đếm lần SAI rồi xoá sạch khi đăng nhập được — đúng khuôn Laravel Fortify.
     *
     * Trước đây chỗ này để perMinute(5) khoá theo email, trùng vai trò với bộ đếm
     * trong LoginService (cũng 5/phút, cũng theo email). Hai bộ đếm chồng nhau và
     * bộ ở đây tính CẢ lần đăng nhập đúng, nên đăng xuất rồi vào lại vài lần trong
     * một phút là bị khoá oan.
     *
     * Ngưỡng để rộng: nó là lưới chống flood, không phải cơ chế khoá tài khoản.
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
