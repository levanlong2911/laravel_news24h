<?php

declare(strict_types=1);

namespace App\Video\Social;

use Carbon\CarbonInterface;

/**
 * The platform policy of Phase 12, read from the DB: what each format of each platform takes. Every limit the check,
 * the screen and the input of a model use comes from here and from nowhere else.
 *
 * What is checked here is what an API would refuse: a file, a length, a character, a time. Nothing here reads what a
 * text means. A value the policy leaves null is not checked.
 */
final class SocialPolicy
{
    public const FORMAT = 'social-platform-policy-v1';

    public const SCHEMA = 'SocialPlatformPolicy@1.0.0';

    /** @param array<string, mixed> $policy the policy document, valid against SocialPlatformPolicy */
    public function __construct(private readonly array $policy) {}

    /** @return array<string, array<string, mixed>> the rules of every format, by its key */
    public function formats(): array
    {
        return (array) ($this->policy['formats'] ?? []);
    }

    /** @return ?array<string, mixed> the rules of one format, null for a format the policy does not know */
    public function rules(string $format): ?array
    {
        $rules = $this->formats()[$format] ?? null;

        return is_array($rules) ? $rules : null;
    }

    public function checkedOn(): string
    {
        return (string) ($this->policy['checked_on'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $rules  the rules of a format
     * @param  array{container: ?string, width: ?int, height: ?int, fps: ?float, duration_ms: ?int, bytes: ?int}  $film  what was measured on the file of the film
     * @return list<string> why the file is not one the format takes; empty when it is. A film is never cut again here: the answer to a problem is another export from Phase 11
     */
    public static function mediaProblems(array $rules, array $film): array
    {
        $media = (array) $rules['media'];
        $label = (string) $rules['label'];
        $problems = [];
        $width = $film['width'] ?? null;
        $height = $film['height'] ?? null;

        if (! in_array(strtolower((string) ($film['container'] ?? '')), (array) $media['containers'], true)) {
            $problems[] = "{$label} nhận file ".implode('/', (array) $media['containers']).', phim là '.json_encode($film['container'] ?? null).'.';
        }

        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1) {
            return [...$problems, 'Không đọc được kích thước khung hình của file phim.'];
        }

        if (! self::fits((string) $media['orientation'], $width, $height)) {
            $problems[] = "{$label} cần khung ".self::orientation((string) $media['orientation']).", phim là {$width}×{$height}.";
        }

        // How the platform itself sorts the upload by its file — a frame and a length — whatever the request says.
        $class = $media['classification'] ?? null;

        if (is_array($class) && is_int($film['duration_ms'] ?? null)) {
            $inside = self::fits((string) $class['orientation'], $width, $height) && (! is_int($class['max_duration_ms']) || $film['duration_ms'] <= $class['max_duration_ms']);
            $kind = 'video khung '.self::orientation((string) $class['orientation']).(is_int($class['max_duration_ms']) ? ' dài tối đa '.self::seconds($class['max_duration_ms']) : '');
            $other = is_string($class['instead']) ? $class['instead'] : null;

            if ($inside !== (bool) $class['member']) {
                $problems[] = $class['member']
                    ? "{$label}: nền tảng chỉ xếp {$kind} vào {$class['name']}; phim {$width}×{$height}, dài ".self::seconds($film['duration_ms']).' nên không thành '.$class['name'].($other === null ? '' : " — dùng định dạng {$other}").'.'
                    : "{$label}: nền tảng xếp {$kind} vào {$class['name']}; phim {$width}×{$height}, dài ".self::seconds($film['duration_ms']).($other === null ? '' : " — đăng phim này bằng định dạng {$other}").', hoặc tạo bản xuất khung ngang ở Phase 11 cho video thường.';
            }
        }

        if (is_array($media['aspect'] ?? null)) {
            $wanted = $media['aspect']['width'] / $media['aspect']['height'];
            $off = abs($width / $height - $wanted) / $wanted * 100;

            if ($off > (int) $media['aspect']['tolerance_percent']) {
                $problems[] = "{$label} cần tỷ lệ {$media['aspect']['width']}:{$media['aspect']['height']}, phim là {$width}×{$height}.";
            }
        }

        foreach ([['min_width', $width, 'rộng', 'px'], ['min_height', $height, 'cao', 'px']] as [$key, $value, $what, $unit]) {
            if (is_int($media[$key] ?? null) && $value < $media[$key]) {
                $problems[] = "{$label} cần khung {$what} ít nhất {$media[$key]} {$unit}, phim {$what} {$value} {$unit}.";
            }
        }

        $fps = $film['fps'] ?? null;

        if ((is_int($media['min_fps'] ?? null) || is_int($media['max_fps'] ?? null)) && ! is_float($fps) && ! is_int($fps)) {
            $problems[] = 'Không đọc được tốc độ khung hình của file phim.';
        } elseif ((is_int($media['min_fps'] ?? null) && $fps < $media['min_fps']) || (is_int($media['max_fps'] ?? null) && $fps > $media['max_fps'])) {
            $problems[] = "{$label} nhận ".json_encode($media['min_fps']).'–'.json_encode($media['max_fps']).' fps, phim là '.round((float) $fps, 3).' fps.';
        }

        $duration = $film['duration_ms'] ?? null;

        if (! is_int($duration)) {
            $problems[] = 'Không đọc được thời lượng của file phim.';
        } elseif ((is_int($media['min_duration_ms'] ?? null) && $duration < $media['min_duration_ms']) || (is_int($media['max_duration_ms'] ?? null) && $duration > $media['max_duration_ms'])) {
            $problems[] = "{$label} nhận phim dài ".self::number($media['min_duration_ms'] ?? null).'–'.self::number($media['max_duration_ms'] ?? null).' giây, phim dài '.self::seconds($duration).'.';
        }

        if (is_int($media['max_bytes'] ?? null) && is_int($film['bytes'] ?? null) && $film['bytes'] > $media['max_bytes']) {
            $problems[] = "{$label} nhận file tối đa {$media['max_bytes']} byte, phim là {$film['bytes']} byte.";
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $rules  the rules of a format
     * @param  array{title: ?string, text: string, tags: list<string>}  $rendered  the text as it will be posted
     * @param  list<string>  $hashtags  the hashtags of the package
     * @return list<string> what the platform would refuse in the text: a field the format does not use, a required one left out, a length or a character. Empty when it takes all of it
     */
    public static function textProblems(array $rules, array $rendered, array $hashtags): array
    {
        $text = (array) $rules['text'];
        $problems = [];

        foreach ([['title', 'Tiêu đề', $rendered['title']], ['body', 'Nội dung đăng', $rendered['text'] === '' ? null : $rendered['text']]] as [$key, $name, $value]) {
            $field = (array) $text[$key];

            if (! $field['used']) {
                if ($value !== null) {
                    $problems[] = "{$name}: {$rules['label']} không dùng trường này — để trống.";
                }

                continue;
            }

            if ($value === null) {
                if ($field['required']) {
                    $problems[] = "{$name}: {$rules['label']} bắt buộc có.";
                }

                continue;
            }

            if (is_int($field['max_chars']) && mb_strlen($value) > $field['max_chars']) {
                $problems[] = "{$name} dài ".mb_strlen($value)." ký tự, {$rules['label']} nhận tối đa {$field['max_chars']}.";
            }

            if (is_int($field['max_bytes']) && strlen($value) > $field['max_bytes']) {
                $problems[] = "{$name} dài ".strlen($value)." byte, {$rules['label']} nhận tối đa {$field['max_bytes']} byte.";
            }

            $found = array_values(array_filter((array) $field['forbidden'], static fn (string $character): bool => str_contains($value, $character)));

            if ($found !== []) {
                $problems[] = "{$name} có ký tự {$rules['label']} không nhận: ".implode(' ', $found).'.';
            }
        }

        if (! $text['hashtags']['used'] && $hashtags !== []) {
            $problems[] = "Hashtag: {$rules['label']} không dùng — để trống.";
        } elseif (is_int($text['hashtags']['max_count']) && count($hashtags) > $text['hashtags']['max_count']) {
            $problems[] = 'Có '.count($hashtags)." hashtag, {$rules['label']} nhận tối đa {$text['hashtags']['max_count']}.";
        }

        $tags = $rendered['tags'];

        if (! $text['keywords']['used'] && $tags !== []) {
            $problems[] = "Từ khóa: {$rules['label']} không dùng — để trống.";
        } elseif (is_int($text['keywords']['max_total_chars']) && self::tagsLength($tags) > $text['keywords']['max_total_chars']) {
            $problems[] = 'Từ khóa dài tổng '.self::tagsLength($tags)." ký tự, {$rules['label']} nhận tối đa {$text['keywords']['max_total_chars']}.";
        }

        return $problems;
    }

    /**
     * @param  list<string>  $tags
     * @return int the length a platform counts a list of tags by: the tags joined by commas, a tag holding a space counted with the quotation marks it is sent in
     */
    public static function tagsLength(array $tags): int
    {
        $length = max(0, count($tags) - 1);

        foreach ($tags as $tag) {
            $length += mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0);
        }

        return $length;
    }

    /**
     * @param  array<string, mixed>  $rules  the rules of a format
     * @param  ?string  $privacy  the visibility of the package
     * @return ?string why a posting of this format cannot be scheduled for that time, null when it can
     */
    public static function scheduleProblem(array $rules, ?string $privacy, CarbonInterface $at, CarbonInterface $now): ?string
    {
        $schedule = (array) $rules['schedule'];

        if (! $schedule['supported'] || $schedule['owner'] !== 'platform') {
            return "{$rules['label']} không lên lịch được qua API.";
        }

        if (is_string($schedule['requires_privacy']) && $privacy !== $schedule['requires_privacy']) {
            return "{$rules['label']} chỉ lên lịch được cho gói có chế độ hiển thị {$schedule['requires_privacy']}; gói này là ".json_encode($privacy).'.';
        }

        $lead = $now->diffInSeconds($at, false);

        if ($lead < (int) ($schedule['min_lead_seconds'] ?? 0)) {
            return "Giờ đăng phải sau hiện tại ít nhất ".self::span((int) ($schedule['min_lead_seconds'] ?? 0)).'.';
        }

        if (is_int($schedule['max_lead_seconds']) && $lead > $schedule['max_lead_seconds']) {
            return "{$rules['label']} chỉ nhận lịch trong vòng ".self::span($schedule['max_lead_seconds']).' tới.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rules  the rules of a format
     * @param  int  $reserved  how many characters code adds to the text a model writes: the link, the disclosure sentence and what joins them
     * @return array<string, mixed> the limits of a format as a model is given them and the screen shows them
     */
    public static function limits(array $rules, int $reserved): array
    {
        return [
            'title' => $rules['text']['title'],
            'body' => $rules['text']['body'],
            'hashtags' => $rules['text']['hashtags'],
            'keywords' => $rules['text']['keywords'],
            'reserved' => ['chars' => $reserved, 'what' => 'The link, the disclosure sentence and the hashtags are added to the body by code and count toward the body limit.'],
        ];
    }

    private static function fits(string $orientation, int $width, int $height): bool
    {
        return match ($orientation) {
            'portrait' => $height > $width,
            'portrait_or_square' => $height >= $width,
            'landscape' => $width > $height,
            default => true,
        };
    }

    private static function orientation(string $orientation): string
    {
        return match ($orientation) {
            'portrait' => 'dọc',
            'portrait_or_square' => 'dọc hoặc vuông',
            'landscape' => 'ngang',
            default => $orientation,
        };
    }

    private static function seconds(?int $ms): string
    {
        return self::number($ms).' giây';
    }

    /** @return string milliseconds as seconds, without trailing zeros; … for no limit */
    private static function number(?int $ms): string
    {
        return $ms === null ? '…' : rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }

    private static function span(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => (string) intdiv($seconds, 86400).' ngày',
            $seconds >= 3600 => (string) intdiv($seconds, 3600).' giờ',
            default => (string) (int) ceil($seconds / 60).' phút',
        };
    }
}
