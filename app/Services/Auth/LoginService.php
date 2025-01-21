<?php

namespace App\Services\Auth;

use App\Repositories\Interfaces\AdminRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginService
{
    public function __construct
    (
        AdminRepositoryInterface $adminRepository
    )
    {
        $this->adminRepository = $adminRepository;
    }

    public function loginAccount(Request $request)
    {
        // Kiểm tra email tồn tại và email đã được xác minh
        // dd($this->adminRepository);
        $admin = $this->adminRepository
        ->clearQuery()
        ->where("email", $request->email)
        ->whereNotNull("email_verified_at")
        ->first();

        if (!$admin) {
            return __("messages.login_fail");
        }

        // Xử lý đăng nhập
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Kiểm tra giới hạn đăng nhập
        $key = Str::lower($request->email) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

        return response()->json([
                'message' => __('messages.too_many_attempts', ['seconds' => $seconds]),
            ], 429);
        }

        if (!auth()->attempt($credentials, $remember)) {
            // Thêm một lần thử không thành công vào RateLimiter
            RateLimiter::hit($key, 60); // Giới hạn tồn tại trong 60 giây
            return __("messages.login_fail");
        }
        // Xóa số lần thử khi đăng nhập thành công
        RateLimiter::clear($key);

        return true;
    }
}
