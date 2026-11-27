<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Models\Admin;

/**
 * Who may do what in Social Publishing, by the role of the signed-in admin and config('video.social.abilities').
 * Every action asks here on the server; a button that is not shown is not what keeps an action from running.
 */
final class SocialAbilities
{
    public const CHANNELS = 'channels';

    public const PACKAGES = 'packages';

    public const APPROVE = 'approve';

    public const EXPORT = 'export';

    public const CONNECT = 'connect';

    public const PUBLISH = 'publish';

    public const ALL = [self::CHANNELS, self::PACKAGES, self::APPROVE, self::EXPORT, self::CONNECT, self::PUBLISH];

    /** @var array<string, string> what each ability is, as a refusal names it */
    public const LABELS = [
        self::CHANNELS => 'chỉnh hồ sơ kênh',
        self::PACKAGES => 'tạo và chỉnh gói nội dung',
        self::APPROVE => 'duyệt hoặc thu hồi duyệt gói',
        self::EXPORT => 'xuất gói và ghi nhận đăng tay',
        self::CONNECT => 'kết nối tài khoản nền tảng',
        self::PUBLISH => 'đăng, lên lịch hoặc hủy lịch qua API',
    ];

    public static function allows(mixed $user, string $ability): bool
    {
        if (! $user instanceof Admin || ! in_array($ability, self::ALL, true)) {
            return false;
        }

        $granted = (array) (config('video.social.abilities')[(string) ($user->role?->name ?? '')] ?? []);

        return in_array('*', $granted, true) || in_array($ability, $granted, true);
    }

    /** @return array<string, bool> every ability of the user, for the screen to show or leave out a control */
    public static function of(mixed $user): array
    {
        $abilities = [];

        foreach (self::ALL as $ability) {
            $abilities[$ability] = self::allows($user, $ability);
        }

        return $abilities;
    }

    public static function refusal(string $ability): string
    {
        return 'Tài khoản của bạn không có quyền '.(self::LABELS[$ability] ?? $ability).'.';
    }
}
