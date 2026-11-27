<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoSocialChannel;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialMetric;
use App\Models\VideoSocialPublication;
use App\Video\Social\SocialPlatformError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The numbers a platform gives for a posting, read one posting at a time and kept with the span they cover, when they
 * were read and where from. A number the platform does not give is stored as not provided and shown as no data —
 * never as zero. Reading the same span again replaces its numbers; it never adds a second copy.
 *
 * A number keeps the name its platform gives it. What Facebook calls a play and what YouTube calls a view are two
 * numbers, and nothing here adds them up.
 */
final class SocialMetricsService
{
    public function __construct(private readonly SocialConnectionService $connections) {}

    public static function enabled(): bool
    {
        return SocialSourceService::enabled() && (bool) config('video.social.analytics');
    }

    /**
     * Reads the numbers of one posting from its platform and stores them for the span from the day it was published to today.
     *
     * @return array{0: int, 1: string} how many numbers the platform gave, and what happened, in words
     *
     * @throws RuntimeException when numbers are switched off, or the posting is not one a platform can be asked about
     */
    public function sync(string $projectId, string $publicationId): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('Đọc số liệu nền tảng đang tắt (VIDEO_SOCIAL_ANALYTICS).');
        }

        $publication = VideoSocialPublication::query()->with(['package', 'channel.connection'])->where('project_id', $projectId)->findOrFail($publicationId);

        if ($publication->method !== VideoSocialPublication::API || $publication->status !== VideoSocialPublication::PUBLISHED || ! is_string($publication->external_id) || $publication->published_at === null) {
            throw new RuntimeException($publication->method === VideoSocialPublication::MANUAL
                ? 'Lượt đăng tay chỉ có URL do người dùng xác nhận, không có ID nền tảng — hệ thống không đọc số liệu cho lượt này.'
                : 'Chỉ đọc số liệu cho lượt đã đăng qua API và đã có ID bài trên nền tảng.');
        }

        $rules = (array) $publication->package->source_json['policy']['rules'];
        $items = array_values((array) $rules['metrics']['items']);
        $from = $publication->published_at->copy()->utc()->startOfDay();
        $to = Carbon::now('UTC')->startOfDay();

        try {
            $values = SocialConnectionService::publisher((string) $rules['platform'])->metrics($this->connections->account($publication->channel), $publication->external_id, array_map(static fn (array $item): array => ['id' => (string) $item['id'], 'shape' => (string) $item['shape']], $items), $from, $to);
        } catch (SocialPlatformError $e) {
            if ($e->kind === SocialPlatformError::TOKEN && $publication->channel instanceof VideoSocialChannel) {
                $this->connections->expire($publication->channel, $e->getMessage());
            }

            // Nothing is stored for a reading that failed: a failed reading is not a zero either.
            throw new RuntimeException('Chưa đọc được số liệu: '.$e->getMessage());
        }

        $key = $from->format('Y-m-d').'..'.$to->format('Y-m-d');
        $given = 0;

        DB::transaction(static function () use ($publication, $items, $values, $from, $to, $key, $rules, &$given): void {
            foreach ($items as $item) {
                // A number given per key is one row a key; when the platform gave no key at all, one row says the whole number is missing.
                $names = $item['shape'] === 'map' ? array_values(array_filter(array_keys($values), static fn (string $name): bool => str_starts_with($name, $item['id'].'.'))) : [];

                foreach ($names === [] ? [(string) $item['id']] : $names as $name) {
                    $value = $values[$name] ?? null;
                    $given += $value === null ? 0 : 1;
                    VideoSocialMetric::query()->updateOrCreate(
                        ['publication_id' => $publication->id, 'metric' => $name, 'period_key' => $key],
                        [
                            'value' => $value,
                            'availability' => $value === null ? VideoSocialMetric::NOT_PROVIDED : VideoSocialMetric::AVAILABLE,
                            'period_start' => $from,
                            'period_end' => $to,
                            'fetched_at' => now(),
                            'source' => (string) $rules['metrics']['source'],
                            'raw_json' => ['value' => $value],
                        ],
                    );
                }
            }
        });
        VideoSocialEvent::record($projectId, 'metrics_read', (string) $publication->package_revision_id, $publicationId, null, ['period' => $key, 'given' => $given]);

        return [$given, $given === 0 ? 'Nền tảng chưa trả số liệu nào cho bài này trong khoảng '.$key.' — ghi là chưa có dữ liệu, không phải 0.' : "Đã đọc {$given} số liệu cho khoảng {$key}."];
    }

    /**
     * @return array{postings: list<array<string, mixed>>, groups: list<array<string, mixed>>, comparisons: list<array<string, mixed>>} what the dashboard shows: every posting that is out with the newest reading of each of its numbers, the same numbers added up for each platform and format, and the postings of one target side by side. Three queries, however many postings there are
     */
    public function dashboard(string $projectId): array
    {
        $publications = VideoSocialPublication::query()
            // Of what a revision was written from, only the numbers its format lists are read here: the rest stays in the DB.
            ->with(['package' => static fn ($query) => $query->select(['id', 'package_group_id', 'revision', 'format', 'language'])
                ->selectRaw("JSON_EXTRACT(source_json, '$.policy.rules.metrics.items') as metric_items, JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.policy.rules.label')) as format_label"), 'channel:id,display_name,platform'])
            ->where('project_id', $projectId)
            ->whereIn('status', [VideoSocialPublication::PUBLISHED, VideoSocialPublication::MANUAL_REPORTED])
            ->orderByDesc('published_at')->limit(100)->get();
        $latest = [];

        foreach (VideoSocialMetric::query()->whereIn('publication_id', $publications->modelKeys())->orderByDesc('period_end')->orderByDesc('fetched_at')->get() as $metric) {
            $latest[(string) $metric->publication_id][(string) $metric->metric] ??= $metric;
        }

        $postings = [];
        $groups = [];
        $targets = [];

        foreach ($publications as $publication) {
            $read = $latest[(string) $publication->id] ?? [];
            $numbers = [];

            foreach ((array) json_decode((string) ($publication->package?->metric_items ?? '[]'), true) as $item) {
                $rows = $item['shape'] === 'map' ? array_filter($read, static fn (VideoSocialMetric $metric): bool => str_starts_with((string) $metric->metric, $item['id'].'.') || $metric->metric === $item['id']) : array_filter([$read[$item['id']] ?? null]);

                foreach ($rows === [] ? [null] : $rows as $metric) {
                    $name = $metric === null ? (string) $item['id'] : (string) $metric->metric;
                    $numbers[] = [
                        'metric' => $name,
                        'label' => $item['label'].($name === $item['id'] ? '' : ' · '.substr($name, strlen((string) $item['id']) + 1)),
                        'group' => (string) $item['group'],
                        'unit' => (string) $item['unit'],
                        // No reading and a reading the platform left empty are both "no data"; neither is a zero.
                        'value' => $metric?->availability === VideoSocialMetric::AVAILABLE ? $metric->value : null,
                        'state' => match (true) {
                            $metric === null => 'not_read',
                            $metric->availability === VideoSocialMetric::AVAILABLE => 'available',
                            default => 'not_provided',
                        },
                        'period' => $metric === null ? null : $metric->period_start?->format('Y-m-d').' → '.$metric->period_end?->format('Y-m-d'),
                        'days' => $metric === null || $metric->period_start === null ? null : (int) $metric->period_start->diffInDays($metric->period_end) + 1,
                        'fetched_at' => $metric?->fetched_at?->toDateTimeString(),
                        'source' => $metric?->source,
                    ];
                }
            }

            $row = [
                'id' => (string) $publication->id,
                'platform' => (string) ($publication->channel?->platform ?? ''),
                'channel' => (string) ($publication->channel?->display_name ?? ''),
                'format' => (string) ($publication->package?->format ?? ''),
                'format_label' => (string) ($publication->package?->format_label ?? ''),
                'language' => (string) ($publication->package?->language ?? ''),
                'revision' => (int) ($publication->package?->revision ?? 0),
                'method' => (string) $publication->method,
                'url' => $publication->external_url,
                'published_at' => $publication->published_at?->toIso8601String(),
                'readable' => $publication->method === VideoSocialPublication::API && is_string($publication->external_id),
                'numbers' => $numbers,
            ];
            $postings[] = $row;
            $groupKey = $row['platform'].'|'.$row['format'];
            $groups[$groupKey] ??= ['platform' => $row['platform'], 'format_label' => $row['format_label'], 'postings' => 0, 'numbers' => []];
            $groups[$groupKey]['postings']++;

            foreach ($numbers as $number) {
                $groups[$groupKey]['numbers'][$number['metric']] ??= ['label' => $number['label'], 'unit' => $number['unit'], 'sum' => null, 'with_data' => 0];

                // Only a number counted the same way adds up: a count. An average, a share or a time watched per view is shown per posting.
                if ($number['value'] !== null && $number['unit'] === 'count') {
                    $groups[$groupKey]['numbers'][$number['metric']]['sum'] = (float) $groups[$groupKey]['numbers'][$number['metric']]['sum'] + (float) $number['value'];
                    $groups[$groupKey]['numbers'][$number['metric']]['with_data']++;
                }
            }

            $targets[(string) ($publication->package?->package_group_id ?? '')][] = $row;
        }

        return [
            'postings' => $postings,
            'groups' => array_values(array_map(static fn (array $group): array => ['numbers' => array_values(array_filter($group['numbers'], static fn (array $number): bool => $number['unit'] === 'count'))] + $group, $groups)),
            // The postings of one package — one channel, one format, one language — beside each other. They compare only as far as their spans are alike, and the span of each is shown.
            'comparisons' => array_values(array_filter($targets, static fn (array $rows): bool => count($rows) > 1)),
        ];
    }
}
