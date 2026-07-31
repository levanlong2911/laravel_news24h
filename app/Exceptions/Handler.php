<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            //
        });

        /**
         * CSRF token hỏng/hết hạn → 419.
         *
         * Mặc định Laravel trả trang "Page Expired" trần: không thông báo, không
         * đường quay lại. Trên trang login đó là ngõ cụt — người dùng chỉ thấy
         * lỗi mà không biết làm gì tiếp.
         *
         * Đưa họ về đúng form kèm lời giải thích và dữ liệu đã nhập (trừ mật khẩu,
         * $dontFlash ở trên đã chặn). Token mới sinh theo trang nên lần gửi sau
         * đi qua được.
         *
         * Bắt theo HttpExceptionInterface + mã 419 chứ không theo
         * TokenMismatchException: Handler::render() chạy prepareException() TRƯỚC
         * renderViaCallbacks(), và bước đó đã đổi TokenMismatchException thành
         * HttpException(419) — callback khai theo class gốc sẽ không bao giờ khớp.
         *
         * Trả null cho mọi mã khác để Laravel xử lý như bình thường.
         */
        $this->renderable(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            // 429 từ lớp chặn thô theo IP cũng là trang trần không lối thoát.
            // Dùng luôn Retry-After mà ThrottleRequests đã đặt sẵn.
            $message = match ($status) {
                419 => __('messages.session_expired'),
                429 => __('messages.too_many_attempts', [
                    'seconds' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
                ]),
                default => null,
            };

            if ($message === null) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return redirect()
                ->back()
                ->withInput($request->except('password', '_token'))
                ->with('error', $message);
        });
    }
}
