<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClaudeWriterService
{
    private const MODELS = [
        'haiku' => 'claude-haiku-4-5-20251001',
        'sonnet' => 'claude-sonnet-4-6',
    ];

    private const MAX_TOKENS = [
        'haiku' => 4096,
        'sonnet' => 8000,
    ];

    /**
     * Gia THAT theo bang gia Anthropic, USD / 1M token.
     *
     * SUA 2026-07-31: haiku truoc ghi 0.80/4.00 — SAI, gia dung la 1.00/5.00.
     * He qua: MOI con so chi phi ghi lai truoc ngay nay deu THAP hon thuc te
     * dung 20% (nhan 1.25 de ra so that). Luong video chay 100% Haiku nen bi
     * anh huong toan bo; luong CMS chi lech o cac cu Haiku, phan Sonnet dung.
     * Phat hien bang cach doi chieu voi platform.claude.com/usage.
     *
     * sonnet 3.00/15.00 = Sonnet 4.6, DA DUNG, khong doi.
     */
    public const PRICE_INPUT = [
        'haiku' => 1.00,
        'sonnet' => 3.00,
    ];

    public const PRICE_OUTPUT = [
        'haiku' => 5.00,
        'sonnet' => 15.00,
    ];

    /**
     * He so gia cho token cache, nhan voi PRICE_INPUT cua chinh model do.
     *
     * Cache doc re gan 10 lan, cache GHI dat hon input thuong 25% (TTL 5 phut).
     * Hien project CHUA gui `cache_control` nen hai truong nay luon 0 — tinh
     * san vi `usage.input_tokens` chi la PHAN CHUA CACHE: ngay nao bat cache
     * ma khong cong hai truong nay thi chi phi se tut xuong am tham, dung kieu
     * sai vua phai di tim.
     */
    private const CACHE_WRITE_MULTIPLIER = 1.25;

    private const CACHE_READ_MULTIPLIER = 0.10;

    private const MAX_RETRIES = 5;

    private const BASE_DELAY_S = 3;   // normal errors: 3s, 6s, 12s...

    private const DELAY_529_S = 30;  // 529 Overloaded: 30s, 60s, 90s...

    private const RPM_LIMIT = 40;  // max requests/phút gửi tới Anthropic

    private const MAX_CONCURRENT = 8;   // max đồng thời (parallel workers)

    /**
     * $modelType co phai khoa hop le trong bang MODELS khong?
     *
     * Chi mo ra CAU HOI, giu nguyen bang MODELS private — caller khong can biet
     * model id day du, chi can biet khoa cua minh co dung khong.
     *
     * Ton tai vi hai default trong class nay LECH NHAU: generate() roi ve
     * 'haiku' con costUsd() roi ve 'sonnet'. Nen mot khoa sai se goi Haiku
     * nhung ghi hoa don gia Sonnet, va khong co gi bao loi. Caller nen hoi
     * truoc (xem ClaudeWriterAdapter::complete()) thay vi de roi am tham.
     */
    public static function supports(string $modelType): bool
    {
        return isset(self::MODELS[$modelType]);
    }

    /**
     * @param  int  $cacheWriteTokens  `usage.cache_creation_input_tokens`
     * @param  int  $cacheReadTokens  `usage.cache_read_input_tokens`
     *
     * Hai tham so cache co MAC DINH 0 nen moi caller cu (ArticlePipelineService,
     * HookEngine, test) khong phai doi gi. Chung khong phai trang tri: Anthropic
     * tinh `usage.input_tokens` la PHAN CHUA CACHE, nen tong prompt that =
     * input + cache_write + cache_read. Bo qua hai truong do la bo qua tien
     * that ngay khi ai do bat cache.
     */
    public static function costUsd(
        int $inputTokens,
        int $outputTokens,
        string $modelType,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
    ): float {
        $priceIn = self::PRICE_INPUT[$modelType] ?? self::PRICE_INPUT['sonnet'];
        $priceOut = self::PRICE_OUTPUT[$modelType] ?? self::PRICE_OUTPUT['sonnet'];

        return (
            $inputTokens * $priceIn
            + $outputTokens * $priceOut
            + $cacheWriteTokens * $priceIn * self::CACHE_WRITE_MULTIPLIER
            + $cacheReadTokens * $priceIn * self::CACHE_READ_MULTIPLIER
        ) / 1_000_000;
    }

    /**
     * @param  int|null  $maxTokens  Trần output cho RIÊNG cú gọi này. null → dùng bảng
     *                               MAX_TOKENS theo model (hành vi cũ, không đổi cho caller phía CMS).
     *
     * Thêm tham số này 2026-07-30 vì bug thật: `LlmRequest` ĐÃ có `maxTokens`
     * (mặc định 8192) nhưng `ClaudeWriterAdapter` không có đường nào truyền
     * xuống, nên mọi cú gọi của pipeline video đều rơi về 4096 của bảng. Trích
     * xuất một bài 2851 ký tự cần hơn 4096 token output ⇒ JSON bị cắt cụt ⇒
     * `MalformedExtraction`. Đúng LỚP LỖI đã sửa ngày 2026-07-29 với
     * `$request->model`: field có thật, ý định có thật, rơi âm thầm ở adapter.
     *
     * Nâng trần KHÔNG làm tăng chi phí mỗi cú gọi (tính theo token THẬT SỰ sinh
     * ra), và KHÔNG chạm cổng duyệt chi vì `GatedLlmClient` ước lượng theo
     * INPUT token, không theo trần output.
     */
    public function generate(string $prompt, string $modelType = 'haiku', string $system = '', ?int $maxTokens = null): ClaudeResponse
    {
        $model = self::MODELS[$modelType] ?? self::MODELS['haiku'];
        $maxTokens ??= self::MAX_TOKENS[$modelType] ?? 2048;

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== '') {
            $requestBody['system'] = $system;
        }
        $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
        if ($encodedBody === false) {
            // Fallback: strip invalid UTF-8 rồi encode lại
            array_walk_recursive($requestBody, function (&$v) {
                if (is_string($v)) {
                    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
                }
            });
            $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->waitForRpmSlot();       // block nếu đạt RPM_LIMIT
            $this->acquireConcurrent();    // block nếu đạt MAX_CONCURRENT

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: '.config('services.claude.key'),
                    'anthropic-version: '.config('services.claude.version', '2023-06-01'),
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS => $encodedBody,
            ]);

            $body = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $this->releaseConcurrent();    // giải phóng slot ngay sau HTTP call

            if ($curlError ?? false) {
                $lastError = "cURL error: {$curlError}";
                Log::warning("Claude exception (attempt {$attempt}/".self::MAX_RETRIES."): {$lastError}");
            } else {
                $json = json_decode($body, true);

                if ($httpStatus === 200) {
                    $stopReason = $json['stop_reason'] ?? '';
                    $text = $json['content'][0]['text'] ?? '';
                    $inputTokens = $json['usage']['input_tokens'] ?? 0;
                    $outputTokens = $json['usage']['output_tokens'] ?? 0;
                    // Luon 0 khi chua bat cache_control — doc de phong, xem
                    // docblock CACHE_WRITE_MULTIPLIER.
                    $cacheWriteTokens = $json['usage']['cache_creation_input_tokens'] ?? 0;
                    $cacheReadTokens = $json['usage']['cache_read_input_tokens'] ?? 0;

                    if ($stopReason === 'max_tokens') {
                        // Van TRA VE ban cat do (khong nem o day) — caller CMS
                        // co the dung duoc doan van dang do. Nhung tu 2026-07-30
                        // `stop_reason` di THEO ClaudeResponse, nen caller nao
                        // can nguyen ven (vd trich xuat JSON) tu kiem duoc bang
                        // `wasTruncated()` thay vi doan qua log.
                        Log::warning('Claude output truncated at max_tokens — returning partial', [
                            'model' => $model,
                            'tokens' => $outputTokens,
                            'max_tokens' => $maxTokens,
                        ]);
                    } else {
                        Log::debug('Claude OK', [
                            'model' => $model,
                            'attempt' => $attempt,
                            'stop_reason' => $stopReason,
                            'input_tokens' => $inputTokens,
                            'output_tokens' => $outputTokens,
                            'cache_write_tokens' => $cacheWriteTokens,
                            'cache_read_tokens' => $cacheReadTokens,
                            'cost_usd' => round(self::costUsd($inputTokens, $outputTokens, $modelType, $cacheWriteTokens, $cacheReadTokens), 6),
                            'chars' => strlen($text),
                        ]);
                    }

                    return new ClaudeResponse($text, $inputTokens, $outputTokens, $stopReason, $cacheWriteTokens, $cacheReadTokens);
                }

                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);

                    return new ClaudeResponse('', 0, 0);
                }

                $lastError = "HTTP {$httpStatus}: ".($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/".self::MAX_RETRIES."): {$lastError}");
            }

            if ($attempt < self::MAX_RETRIES) {
                $is529 = str_contains($lastError, '529');
                $jitter = random_int(1, 5); // tránh thundering herd khi nhiều worker retry cùng lúc
                $delaySec = $is529
                    ? self::DELAY_529_S * $attempt + $jitter        // ~31-35s, ~61-65s, ...
                    : self::BASE_DELAY_S * (2 ** ($attempt - 1)) + $jitter; // ~4-8s, ~7-11s, ...
                Log::info("Claude retry in {$delaySec}s...");
                sleep($delaySec);
            }
        }

        Log::error('Claude failed after '.self::MAX_RETRIES.' attempts', [
            'model' => $model,
            'last_error' => $lastError,
        ]);

        return new ClaudeResponse('', 0, 0);
    }

    // RPM throttle: cho phép song song nhưng giới hạn tổng request/phút
    private function waitForRpmSlot(): void
    {
        while (true) {
            $minuteKey = 'claude_rpm_'.(int) (time() / 60);
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
            Log::info("Claude RPM limit ({$count}/".self::RPM_LIMIT."), waiting {$waitSec}s");
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
