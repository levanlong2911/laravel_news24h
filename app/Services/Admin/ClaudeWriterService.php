<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClaudeWriterService
{
    private const MODELS = [
        'haiku'  => 'claude-haiku-4-5-20251001',
        'sonnet' => 'claude-sonnet-4-6',
    ];

    private const MAX_TOKENS = [
        'haiku'  => 4096,
        'sonnet' => 8000,
    ];

    /**
     * Giá USD trên 1 triệu token. Đối chiếu lần cuối: 2026-08-05.
     *
     * Haiku trước đây ghi 0.80 / 4.00 trong khi bảng giá thật là 1.00 / 5.00,
     * nên mọi con số chi phí Haiku trong dashboard thấp hơn thực tế 20%. Dòng
     * chú thích cũ chỉ nói "update when Anthropic changes rates" và không ai
     * cập nhật — giá là dữ liệu bên ngoài, nó trôi mà không báo.
     *
     * Khi sửa hai bảng này, ghi lại ngày đối chiếu ở trên. Con số cũ không thể
     * truy ngược: claude_usage_logs chỉ lưu total_tokens và total_cost_usd,
     * không lưu model lẫn tách vào/ra, nên bản ghi lịch sử vĩnh viễn thiếu 20%
     * ở phần Haiku.
     */
    public const PRICE_INPUT = [
        'haiku'  => 1.00,
        'sonnet' => 3.00,
    ];

    public const PRICE_OUTPUT = [
        'haiku'  => 5.00,
        'sonnet' => 15.00,
    ];

    /**
     * Hệ số nhân trên giá INPUT cho token cache.
     *
     * Ghi cache 5 phút 1,25× · ghi cache 1 giờ 2× · đọc cache 0,1×. Pipeline
     * chưa gửi cache_control ở đâu nên hai khoản này luôn 0; để sẵn hằng số để
     * ngày bật caching không phải đi tìm lại công thức.
     */
    private const CACHE_WRITE_5M_MULTIPLIER = 1.25;
    private const CACHE_READ_MULTIPLIER     = 0.10;

    private const MAX_RETRIES    = 5;
    private const BASE_DELAY_S   = 3;   // normal errors: 3s, 6s, 12s...
    private const DELAY_529_S    = 30;  // 529 Overloaded: 30s, 60s, 90s...
    private const RPM_LIMIT      = 40;  // max requests/phút gửi tới Anthropic
    private const MAX_CONCURRENT = 8;   // max đồng thời (parallel workers)

    /**
     * Chi phí USD của một request, tính đủ bốn khoản token.
     *
     * Thay cho costUsd(int, int, string) cũ — bản cũ chỉ nhận input/output nên
     * không thể tính token cache, và buộc chỗ gọi phải tự nhớ modelType. Giờ
     * TokenUsage mang sẵn cả hai, không truyền nhầm được.
     */
    public static function costOf(TokenUsage $usage): float
    {
        $priceIn  = self::PRICE_INPUT[$usage->modelType]  ?? self::PRICE_INPUT['sonnet'];
        $priceOut = self::PRICE_OUTPUT[$usage->modelType] ?? self::PRICE_OUTPUT['sonnet'];

        return (
            $usage->inputTokens         * $priceIn
          + $usage->cacheCreationTokens * $priceIn  * self::CACHE_WRITE_5M_MULTIPLIER
          + $usage->cacheReadTokens     * $priceIn  * self::CACHE_READ_MULTIPLIER
          + $usage->outputTokens        * $priceOut
        ) / 1_000_000;
    }

    public function generate(string $prompt, string $modelType = 'haiku', string $system = ''): ClaudeResponse
    {
        $model     = self::MODELS[$modelType]     ?? self::MODELS['haiku'];
        $maxTokens = self::MAX_TOKENS[$modelType] ?? 2048;

        $requestBody = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== '') {
            $requestBody['system'] = $system;
        }
        $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
        if ($encodedBody === false) {
            // Fallback: strip invalid UTF-8 rồi encode lại
            array_walk_recursive($requestBody, function (&$v) {
                if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            });
            $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->waitForRpmSlot();       // block nếu đạt RPM_LIMIT
            $this->acquireConcurrent();    // block nếu đạt MAX_CONCURRENT

            // Anthropic trả header `request-id` trên mọi response. Không bắt ở đây
            // thì mất luôn — dùng để đối chất khi cost_report lệch, và khi mở
            // ticket support. CURLOPT_RETURNTRANSFER chỉ giữ body, không giữ header.
            $requestId = null;

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: '         . config('services.claude.key'),
                    'anthropic-version: ' . config('services.claude.version', '2023-06-01'),
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $encodedBody,
                CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$requestId): int {
                    if (stripos($header, 'request-id:') === 0) {
                        $requestId = trim(substr($header, strlen('request-id:')));
                    }

                    // Bắt buộc trả về số byte đã đọc, nếu không cURL coi là lỗi ghi.
                    return strlen($header);
                },
            ]);

            $body       = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);

            $this->releaseConcurrent();    // giải phóng slot ngay sau HTTP call

            if ($curlError ?? false) {
                $lastError = "cURL error: {$curlError}";
                Log::warning("Claude exception (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            } else {
                $json = json_decode($body, true);

                if ($httpStatus === 200) {
                    $stopReason = $json['stop_reason'] ?? '';
                    $text       = $json['content'][0]['text'] ?? '';

                    $usage = TokenUsage::fromResponse(
                        $json['usage'] ?? [],
                        model:     $model,
                        modelType: $modelType,
                        requestId: $requestId,
                    );

                    if ($stopReason === 'max_tokens') {
                        Log::warning('Claude output truncated at max_tokens — returning partial', [
                            'model'  => $model,
                            'tokens' => $usage->outputTokens,
                        ]);
                    } else {
                        Log::debug('Claude OK', [
                            'model'         => $model,
                            'attempt'       => $attempt,
                            'stop_reason'   => $stopReason,
                            'input_tokens'  => $usage->inputTokens,
                            'output_tokens' => $usage->outputTokens,
                            'cache_write'   => $usage->cacheCreationTokens,
                            'cache_read'    => $usage->cacheReadTokens,
                            'request_id'    => $usage->requestId,
                            'cost_usd'      => $usage->costUsd(),
                            'chars'         => strlen($text),
                        ]);
                    }

                    return new ClaudeResponse($text, $usage);
                }

                if ($httpStatus === 400) {
                    // 400 không bị tính tiền — usage rỗng là đúng, không phải mất dữ liệu.
                    Log::error('Claude 400 Bad Request', ['body' => $body, 'request_id' => $requestId]);
                    return new ClaudeResponse('', TokenUsage::none($model, $modelType));
                }

                $lastError = "HTTP {$httpStatus}: " . ($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            }

            if ($attempt < self::MAX_RETRIES) {
                $is529    = str_contains($lastError, '529');
                $jitter   = random_int(1, 5); // tránh thundering herd khi nhiều worker retry cùng lúc
                $delaySec = $is529
                    ? self::DELAY_529_S * $attempt + $jitter        // ~31-35s, ~61-65s, ...
                    : self::BASE_DELAY_S * (2 ** ($attempt - 1)) + $jitter; // ~4-8s, ~7-11s, ...
                Log::info("Claude retry in {$delaySec}s...");
                sleep($delaySec);
            }
        }

        // CẢNH BÁO KẾ TOÁN: usage rỗng ở đây KHÔNG có nghĩa là không tốn tiền.
        //
        // Nếu một lượt thất bại vì timeout (CURLOPT_TIMEOUT = 60) trong khi
        // Anthropic đã sinh xong token, họ vẫn tính tiền còn phía mình không hề
        // nhận được response để đọc usage. Đây là phần chi phí VÔ HÌNH với ledger,
        // và là lý do phải đối soát với cost_report thay vì tin vào tổng tự cộng.
        Log::error('Claude failed after ' . self::MAX_RETRIES . ' attempts', [
            'model'      => $model,
            'last_error' => $lastError,
            'request_id' => $requestId ?? null,
        ]);

        return new ClaudeResponse('', TokenUsage::none($model, $modelType));
    }

    // RPM throttle: cho phép song song nhưng giới hạn tổng request/phút
    private function waitForRpmSlot(): void
    {
        while (true) {
            $minuteKey = 'claude_rpm_' . (int) (time() / 60);
            Cache::add($minuteKey, 0, 65);
            $count = Cache::increment($minuteKey);

            if ($count <= self::RPM_LIMIT) {
                // Stagger nhẹ khi load cao để tránh burst đồng thời
                if ($count > (int) (self::RPM_LIMIT * 0.6)) {
                    usleep(random_int(100, 600) * 1_000); // 100–600ms
                }
                return;
            }

            // Vượt giới hạn — trả lại slot và chờ sang phút mới
            Cache::decrement($minuteKey);
            $waitSec = max(1, 61 - (time() % 60));
            Log::info("Claude RPM limit ({$count}/" . self::RPM_LIMIT . "), waiting {$waitSec}s");
            sleep($waitSec);
        }
    }

    // Concurrency semaphore: giới hạn số request đang bay cùng lúc
    private function acquireConcurrent(): void
    {
        $key = 'claude_concurrent';
        $waited = 0;

        while (true) {
            Cache::add($key, 0, 120);
            $current = Cache::increment($key);

            if ($current <= self::MAX_CONCURRENT) {
                return;
            }

            Cache::decrement($key);
            usleep(300_000); // chờ 300ms rồi thử lại
            $waited += 300;

            if ($waited >= 60_000) { // tối đa 60s chờ slot
                Log::warning('Claude concurrent slot timeout — proceeding anyway');
                Cache::increment($key); // lấy slot dù vượt ngưỡng
                return;
            }
        }
    }

    private function releaseConcurrent(): void
    {
        $current = Cache::get('claude_concurrent', 0);
        if ($current > 0) {
            Cache::decrement('claude_concurrent');
        }
    }
}
