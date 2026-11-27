<?php

declare(strict_types=1);

namespace App\Video\Social;

use RuntimeException;

/**
 * What a platform answered when it did not do what was asked, by kind — so an expired token, a missing permission, a
 * used-up allowance, a refused request and a video the platform could not process are never one "failed".
 *
 * `uncertain` says the request may have reached the platform and what became of it is not known: a posting it happens
 * to is unknown, never failed, and is never sent again without first being looked up.
 */
final class SocialPlatformError extends RuntimeException
{
    public const TOKEN = 'token_expired';

    public const PERMISSION = 'permission';

    public const QUOTA = 'quota';

    public const REJECTED = 'rejected';

    public const PROCESSING = 'processing_failed';

    public const NOT_FOUND = 'not_found';

    public const TIMEOUT = 'timeout';

    public const SERVER = 'platform_error';

    public const CONFIG = 'not_configured';

    public const LABELS = [
        self::TOKEN => 'Token hết hạn hoặc bị thu hồi — kết nối lại tài khoản',
        self::PERMISSION => 'Tài khoản thiếu quyền cho thao tác này',
        self::QUOTA => 'Hết hạn mức của nền tảng hoặc của ứng dụng',
        self::REJECTED => 'Nền tảng từ chối yêu cầu',
        self::PROCESSING => 'Nền tảng xử lý video lỗi',
        self::NOT_FOUND => 'Nền tảng không tìm thấy đối tượng',
        self::TIMEOUT => 'Không nhận được trả lời của nền tảng',
        self::SERVER => 'Nền tảng báo lỗi phía họ',
        self::CONFIG => 'Chưa cấu hình kết nối',
    ];

    public function __construct(public readonly string $kind, string $message, public readonly bool $uncertain = false)
    {
        // What a platform or a transport says can hold the URL of the request, and a URL can hold a token.
        parent::__construct((self::LABELS[$kind] ?? $kind).': '.SocialRedaction::text($message));
    }

    /** @return bool whether asking again later may get an answer without a person doing anything: no answer, an error on the platform's side, an allowance used up for now */
    public function transient(): bool
    {
        return in_array($this->kind, [self::TIMEOUT, self::SERVER, self::QUOTA], true);
    }
}
