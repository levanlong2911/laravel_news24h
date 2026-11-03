<?php

namespace App\Services\Auth;

use App\Repositories\Interfaces\AdminRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginService
{
    /** Số lần sai liên tiếp trước khi khoá tài khoản theo IP. */
    private const MAX_ATTEMPTS = 5;

    /** Bộ đếm sống bao lâu kể từ lần sai gần nhất (giây). */
    private const DECAY_SECONDS = 60;

    private AdminRepositoryInterface $adminRepository;

    public function __construct(AdminRepositoryInterface $adminRepository)
    {
        $this->adminRepository = $adminRepository;
    }

    /**
     * Đăng nhập admin.
     *
     * @return true|string  true nếu thành công, ngược lại là câu thông báo lỗi.
     *
     * Luôn trả CHUỖI khi hỏng, không bao giờ trả Response: AuthController đưa giá
     * trị này thẳng vào withErrors(), mà Blade sẽ ép kiểu chuỗi — một JsonResponse
     * ở đây bị __toString() dump nguyên HTTP header vào ô thông báo lỗi.
     */
    public function loginAccount(Request $request): bool|string
    {
        $key = $this->throttleKey($request);

        // Kiểm tra khoá TRƯỚC khi truy vấn DB. Đặt sau như cũ thì tài khoản đã bị
        // khoá vẫn tốn một query mỗi lần thử.
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return __('messages.too_many_attempts', [
                'seconds' => RateLimiter::availableIn($key),
            ]);
        }

        $admin = $this->adminRepository
            ->clearQuery()
            ->where('email', $request->email)
            ->whereNotNull('email_verified_at')
            ->first();

        // Email không tồn tại cũng phải tính là một lần sai. Trước đây nhánh này
        // return sớm mà không đụng bộ đếm, nên dò email là không giới hạn.
        if (!$admin) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return __('messages.login_fail');
        }

        $credentials = $request->only('email', 'password');

        if (!auth()->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return __('messages.login_fail');
        }

        RateLimiter::clear($key);

        return true;
    }

    /**
     * Chuẩn hoá email trong khoá đếm để 'Admin@Gmail.com' và 'admin@gmail.com'
     * dùng chung một bộ đếm — không chuẩn hoá thì chỉ cần đổi hoa thường là có
     * thêm trọn 5 lượt thử.
     */
    private function throttleKey(Request $request): string
    {
        return 'login|' . Str::lower(trim((string) $request->email)) . '|' . $request->ip();
    }
}
