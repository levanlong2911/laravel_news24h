<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoSocialChannel;
use App\Models\VideoSocialConnection;
use App\Models\VideoSocialPackage;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The profiles of the pages and channels packages are written for. A profile a person entered is usable at once — to
 * write a package, export it and post it by hand; it counts as verified only once a connection has listed the very
 * id the profile names. A profile is never matched to an account by its name.
 *
 * A package keeps the profile it was written for as a snapshot, so a later change of the profile reaches new packages only.
 */
final class SocialChannelService
{
    /** @var list<string> the fields of a profile a package is written from; what a connection adds is no part of it */
    private const PROFILE = ['platform', 'display_name', 'external_id', 'url', 'language', 'timezone', 'audience', 'brand_voice', 'default_goal', 'default_cta', 'default_link', 'disclosure'];

    /** @return Collection<int, VideoSocialChannel> the active profiles, by platform and name */
    public function active(): Collection
    {
        return VideoSocialChannel::query()->where('status', VideoSocialChannel::ACTIVE)->orderBy('platform')->orderBy('display_name')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws RuntimeException when a field is not usable, or another profile already names that page or channel
     */
    public function create(array $input, ?string $actorId): VideoSocialChannel
    {
        $fields = $this->fields($input, null);

        try {
            $channel = VideoSocialChannel::create($fields + ['status' => VideoSocialChannel::ACTIVE, 'created_by' => $actorId]);
        } catch (QueryException $e) {
            throw $this->duplicate($e, $fields);
        }

        return $this->verifyByConnections($channel);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws RuntimeException when a field is not usable, or another profile already names that page or channel
     */
    public function update(string $channelId, array $input): VideoSocialChannel
    {
        $channel = VideoSocialChannel::query()->findOrFail($channelId);
        $fields = $this->fields($input, $channel);

        // A verification is of one id: a profile that names another page or channel is a profile nobody has verified.
        if ($fields['external_id'] !== $channel->external_id || $fields['platform'] !== $channel->platform) {
            $fields += ['connection_id' => null, 'verified_at' => null];
        }

        try {
            $channel->update($fields);
        } catch (QueryException $e) {
            throw $this->duplicate($e, $fields);
        }

        return $this->verifyByConnections($channel);
    }

    /**
     * Verifies a profile at once when a live connection of its platform already lists its very id — as a connection
     * verifies the profiles it lists when it is made. Never by a name, and never a profile taken out of use.
     */
    private function verifyByConnections(VideoSocialChannel $channel): VideoSocialChannel
    {
        $id = (string) ($channel->external_id ?? '');

        if ($channel->status !== VideoSocialChannel::ACTIVE || $id === '' || $channel->verified()) {
            return $channel;
        }

        $connection = VideoSocialConnection::query()->where('platform', $channel->platform)->where('status', VideoSocialConnection::ACTIVE)->orderByDesc('updated_at')->get()
            ->first(static fn (VideoSocialConnection $connection): bool => in_array($id, array_map('strval', array_column((array) $connection->accounts, 'id')), true));

        if ($connection !== null) {
            $channel->forceFill(['connection_id' => $connection->id, 'verified_at' => now()])->save();
        }

        return $channel;
    }

    /**
     * Takes a profile out of the list new packages choose from. Its packages and their postings keep pointing at it.
     */
    public function archive(string $channelId): void
    {
        VideoSocialChannel::query()->whereKey($channelId)->update(['status' => VideoSocialChannel::ARCHIVED]);
    }

    /** @return array<string, mixed> the profile as a package freezes it */
    public static function snapshot(VideoSocialChannel $channel): array
    {
        return ['id' => (string) $channel->id] + array_map(
            static fn (mixed $value): mixed => $value,
            array_intersect_key($channel->only(self::PROFILE), array_flip(self::PROFILE)),
        );
    }

    /** @param array<string, mixed> $snapshot snapshot() */
    public static function hash(array $snapshot): string
    {
        return CanonicalJson::hash($snapshot);
    }

    /**
     * @param  array<string, mixed>  $approved  the profile as a revision froze it, snapshot()
     * @return ?string why a posting of that revision may not go to the page or channel the profile names now: another
     *   platform or another id. A connection that can reach the page named now does not make it the page approved.
     */
    public static function targetProblem(array $approved, VideoSocialChannel $channel): ?string
    {
        $then = [(string) ($approved['platform'] ?? ''), (string) ($approved['external_id'] ?? '')];
        $now = [(string) $channel->platform, (string) ($channel->external_id ?? '')];

        if ((string) ($approved['id'] ?? '') === (string) $channel->id && $then === $now) {
            return null;
        }

        return 'Đích đăng của hồ sơ kênh đã đổi so với bản này (bản này viết cho '.$then[0].' ID '.json_encode($then[1] === '' ? null : $then[1]).', hồ sơ hiện là '.$now[0].' ID '.json_encode($now[1] === '' ? null : $now[1]).')';
    }

    /** What a person does about a target that moved: a revision for the target named now, approved on its own. */
    public const RETARGET = 'Bấm "Cập nhật theo nguồn hiện hành" để tạo revision cho đích mới, duyệt rồi đăng bản đó.';

    /** @return bool whether any package was written for the profile */
    public function used(string $channelId): bool
    {
        return VideoSocialPackage::query()->where('channel_id', $channelId)->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the fields of a profile, each checked for what the platform or the code needs of it — never for what the words say
     *
     * @throws RuntimeException
     */
    private function fields(array $input, ?VideoSocialChannel $channel): array
    {
        $text = static function (string $key, int $max) use ($input): ?string {
            $value = trim((string) ($input[$key] ?? ''));

            if (mb_strlen($value) > $max) {
                throw new RuntimeException("Trường {$key} dài quá {$max} ký tự.");
            }

            return $value === '' ? null : $value;
        };
        $link = static function (string $key) use ($text): ?string {
            $value = $text($key, 500);

            if ($value !== null && preg_match('/^https?:\/\/\S+$/i', $value) !== 1) {
                throw new RuntimeException("Trường {$key} phải là một địa chỉ http(s) đầy đủ.");
            }

            return $value;
        };
        $platform = (string) ($input['platform'] ?? $channel?->platform ?? '');
        $language = (string) ($input['language'] ?? '');
        $timezone = (string) ($input['timezone'] ?? '');

        if (! isset(config('video.social.platforms')[$platform])) {
            throw new RuntimeException('Nền tảng không hợp lệ.');
        }

        if ($channel !== null && $platform !== $channel->platform && $this->used((string) $channel->id)) {
            throw new RuntimeException('Hồ sơ này đã có gói nội dung — không đổi nền tảng; tạo hồ sơ mới cho nền tảng khác.');
        }

        if (! isset(config('video.social.languages')[$language])) {
            throw new RuntimeException('Ngôn ngữ không hợp lệ.');
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('Múi giờ không hợp lệ — dùng tên dạng Asia/Ho_Chi_Minh.');
        }

        $texts = [];

        foreach ((array) config('video.social.languages') as $code => $_) {
            $sentence = trim((string) ($input['disclosure_texts'][$code] ?? ''));

            if (mb_strlen($sentence) > 500) {
                throw new RuntimeException('Câu công bố dài quá 500 ký tự.');
            }

            if ($sentence !== '') {
                $texts[$code] = $sentence;
            }
        }

        return [
            'platform' => $platform,
            'display_name' => $text('display_name', 191) ?? throw new RuntimeException('Cần tên hiển thị của kênh.'),
            'external_id' => $text('external_id', 191),
            'url' => $link('url'),
            'language' => $language,
            'timezone' => $timezone,
            'audience' => $text('audience', 2000),
            'brand_voice' => $text('brand_voice', 2000),
            'default_goal' => $text('default_goal', 255),
            'default_cta' => $text('default_cta', 1000),
            'default_link' => $link('default_link'),
            'disclosure' => ['synthetic_media' => filter_var($input['synthetic_media'] ?? false, FILTER_VALIDATE_BOOLEAN), 'texts' => $texts],
        ];
    }

    /** @param array<string, mixed> $fields */
    private function duplicate(QueryException $e, array $fields): RuntimeException
    {
        return str_contains($e->getMessage(), 'video_social_channels_platform_external_id_unique')
            ? new RuntimeException('Đã có hồ sơ khác của '.$fields['platform'].' dùng ID '.$fields['external_id'].' — mỗi trang hoặc kênh chỉ có một hồ sơ.')
            : new RuntimeException('Không lưu được hồ sơ kênh: '.$e->getMessage());
    }
}
