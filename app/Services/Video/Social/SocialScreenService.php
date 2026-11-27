<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\Admin;
use App\Models\VideoProject;
use App\Models\VideoSocialChannel;
use App\Models\VideoSocialConnection;
use App\Models\VideoSocialPackage;
use App\Models\VideoSocialPublication;
use App\Video\Social\SocialAbilities;
use App\Video\Social\SocialPlan;
use App\Video\Social\SocialPolicy;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Everything the Social Publishing screen shows, read in one place. Reading it writes nothing and calls no model.
 * The film is hashed once for the page; the lists read light columns and never the stored documents of every row.
 */
final class SocialScreenService
{
    public function __construct(
        private readonly SocialSourceService $sources,
        private readonly SocialContentService $content,
        private readonly SocialArtworkService $artwork,
        private readonly SocialApprovalService $approvals,
        private readonly SocialPublicationService $publications,
        private readonly SocialMetricsService $metrics,
        private readonly SocialConnectionService $connections,
    ) {}

    /**
     * @param  ?string  $packageId  the revision a person opened; null opens none
     * @return array<string, mixed>
     */
    public function screen(string $projectId, ?string $packageId, int $page, mixed $user): array
    {
        $screen = [
            'flags' => [
                'ai_content' => SocialContentService::enabled(),
                'publish' => ['youtube' => SocialConnectionService::publishing('youtube'), 'facebook' => SocialConnectionService::publishing('facebook')],
                'analytics' => SocialMetricsService::enabled(),
            ],
            'abilities' => SocialAbilities::of($user),
            'project' => ['id' => $projectId, 'title' => (string) (VideoProject::query()->whereKey($projectId)->value('title') ?? '')],
            'operation' => (string) Str::uuid(),
            'problems' => [],
            'film' => null,
            'source' => null,
            'policy' => null,
            'formats' => [],
            'basis' => SocialPlan::BASIS,
        ];
        $now = ['source_hash' => null, 'policy_hash' => null, 'channels' => []];
        $contract = null;

        try {
            $read = $this->sources->read($projectId)['snapshot'];
            $now['source_hash'] = $read['hash'];
            $screen['film'] = $read['film'] + ['approved_by_name' => $read['film']['approved_by'] === null ? null : Admin::query()->whereKey($read['film']['approved_by'])->value('name')];
            $screen['source'] = ['hash' => $read['hash'], 'missing' => $read['missing'], 'assets' => $read['assets'], 'refs' => count($read['refs']), 'pins' => $read['pins']];
        } catch (RuntimeException $e) {
            $screen['problems'][] = 'Phim nguồn: '.$e->getMessage();
        }

        try {
            $contract = $this->sources->policy($projectId);
            $now['policy_hash'] = $contract['contract']['policy_hash'];
            $screen['policy'] = $contract['contract'] + ['checked_on' => $contract['policy']->checkedOn()];

            foreach ((array) config('video.social.formats') as $key => $_) {
                $rules = $contract['policy']->rules($key);

                if ($rules !== null) {
                    $screen['formats'][$key] = $rules + ['media_problems' => $screen['film'] === null ? [] : SocialPolicy::mediaProblems($rules, (array) $screen['film']['media'])];
                }
            }
        } catch (RuntimeException $e) {
            $screen['problems'][] = 'Chính sách nền tảng: '.$e->getMessage();
        }

        $channels = VideoSocialChannel::query()->with('connection:id,platform,label,status,token_expires_at,last_error')->orderBy('platform')->orderBy('display_name')->get();

        foreach ($channels as $channel) {
            $now['channels'][(string) $channel->id] = SocialChannelService::hash(SocialChannelService::snapshot($channel));
        }

        $screen['channels'] = $channels->map(fn (VideoSocialChannel $channel): array => SocialChannelService::snapshot($channel) + [
            'status' => (string) $channel->status,
            'verified' => $channel->verified(),
            'verified_at' => $channel->verified_at?->toDateTimeString(),
            'connection' => $channel->connection === null ? null : ['label' => (string) $channel->connection->label, 'status' => (string) $channel->connection->status],
            'api_problems' => $channel->status === VideoSocialChannel::ACTIVE ? $this->connections->problems($channel) : [],
        ])->all();
        // Never the tokens: what a connection is, when its token ends, and which pages or channels it lists by id and name.
        $screen['connections'] = VideoSocialConnection::query()->orderBy('platform')->get()->map(static fn (VideoSocialConnection $connection): array => [
            'id' => (string) $connection->id,
            'platform' => (string) $connection->platform,
            'label' => (string) $connection->label,
            'status' => (string) $connection->status,
            'token_expires_at' => $connection->token_expires_at?->toDateTimeString(),
            'last_error' => $connection->last_error,
            'accounts' => array_map(static fn (array $account): array => ['id' => (string) $account['id'], 'name' => (string) ($account['name'] ?? '')], (array) $connection->accounts),
        ])->all();

        // The newest revision of every package of the project, by light columns and the two hashes a revision is compared by.
        $rows = VideoSocialPackage::query()
            ->where('project_id', $projectId)
            ->select(['id', 'package_group_id', 'revision', 'channel_id', 'format', 'language', 'status', 'package_hash', 'source_hash', 'created_at'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.channel_hash')) as channel_hash, JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.policy.policy_hash')) as policy_hash")
            ->orderByDesc('revision')->get();
        $names = $channels->keyBy('id');
        $screen['packages'] = $rows->unique('package_group_id')->values()->map(static fn (VideoSocialPackage $row): array => [
            'id' => (string) $row->id,
            'group' => (string) $row->package_group_id,
            'channel_id' => (string) $row->channel_id,
            'channel' => (string) ($names->get($row->channel_id)?->display_name ?? '(kênh đã lưu trữ)'),
            'platform' => (string) ($names->get($row->channel_id)?->platform ?? ''),
            'format' => (string) $row->format,
            'language' => (string) $row->language,
            'revision' => (int) $row->revision,
            'status' => (string) $row->status,
            'approved_revisions' => $rows->where('package_group_id', $row->package_group_id)->where('status', VideoSocialPackage::APPROVED)->pluck('revision')->sort()->values()->all(),
            'film_changed' => $now['source_hash'] === null || ! hash_equals((string) $row->source_hash, $now['source_hash']),
            'policy_changed' => $now['policy_hash'] !== null && ! hash_equals((string) $row->policy_hash, $now['policy_hash']),
            'channel_changed' => ! hash_equals((string) $row->channel_hash, (string) ($now['channels'][(string) $row->channel_id] ?? '')),
        ])->all();

        $screen['selected'] = $packageId === null ? null : $this->selected($projectId, $packageId, $rows, $now, $contract);
        $screen['history'] = $this->publications->history($projectId, $page);
        $screen['dashboard'] = $this->metrics->dashboard($projectId);

        return $screen;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VideoSocialPackage>  $rows  the light rows of every revision of the project
     * @param  array<string, mixed>  $now
     * @param  ?array<string, mixed>  $contract
     * @return ?array<string, mixed> one revision in full, with the history of its package and what may be done with it now
     */
    private function selected(string $projectId, string $packageId, $rows, array $now, ?array $contract): ?array
    {
        $package = VideoSocialPackage::query()->whereKey($packageId)->where('project_id', $projectId)->first();

        if ($package === null) {
            return null;
        }

        $source = (array) $package->source_json;
        $rules = (array) $source['policy']['rules'];
        $document = (array) $package->document_json;
        $content = (array) $package->content_json;
        $group = $rows->where('package_group_id', $package->package_group_id)->values();
        $newest = (int) $group->max('revision') === (int) $package->revision;
        $standing = SocialPackageService::standing($package, $now);
        $decision = SocialApprovalService::decisionOf($package);
        $cover = SocialApprovalService::coverProblem($package);

        return [
            'id' => (string) $package->id,
            'group' => (string) $package->package_group_id,
            'revision' => (int) $package->revision,
            'newest' => $newest,
            'status' => (string) $package->status,
            'hash' => (string) $package->package_hash,
            'format' => (string) $package->format,
            'language' => (string) $package->language,
            'rules' => $rules,
            'channel' => (array) $source['channel'],
            'goal' => $source['goal'] ?? null,
            // The film this revision was written for, with what it pinned — never the film the project selects now.
            'film' => (array) $source['film'] + ['approved_by_name' => ($source['film']['approved_by'] ?? null) === null ? null : Admin::query()->whereKey($source['film']['approved_by'])->value('name')],
            'pins' => (array) $source['pins'],
            'source_hash' => (string) $package->source_hash,
            'refs' => count((array) $source['refs']),
            'missing' => (array) $source['missing'],
            'assets' => (array) $source['assets'],
            'fields' => (array) $content['fields'],
            'plan' => $content['plan'] ?? null,
            'artwork' => $content['artwork'] ?? null,
            'document' => $document,
            'limits' => SocialPolicy::limits($rules, 0),
            'standing' => $standing,
            // What holds an approval back, shown before the button is pressed; the same check runs again when it is.
            'approval_problems' => match (true) {
                $package->status !== VideoSocialPackage::DRAFT => [],
                // The server refuses an approval with no policy to read: the page says so rather than offer the button.
                $contract === null => ['Không đọc được chính sách nền tảng hiện hành nên chưa duyệt được — xem cảnh báo ở đầu trang.'],
                default => $this->approvals->problems($package, (string) $package->package_hash, $now['source_hash'], $contract['contract'], $cover),
            },
            'approved' => SocialApprovalService::approved($package),
            'decision' => $decision === null ? null : ['id' => (string) $decision->id, 'at' => $decision->created_at?->toDateTimeString(), 'by' => $decision->reviewer_id === null ? null : Admin::query()->whereKey($decision->reviewer_id)->value('name')],
            'origin' => $package->ai_stage_id === null ? 'person' : 'model',
            'runs' => $this->content->runs($package),
            // null: nobody asked for a review of this revision. That is "not reviewed", never "passed".
            'review' => $this->content->reviewOf($package),
            'covers' => $this->artwork->covers($package)->map(static fn ($artifact): array => [
                'id' => (string) $artifact->id,
                'sha256' => (string) $artifact->sha256,
                'width' => (int) $artifact->width,
                'height' => (int) $artifact->height,
                'recipe' => (array) $artifact->metadata_json['recipe'],
                'made_at' => $artifact->created_at?->toDateTimeString(),
            ])->all(),
            'cover_problem' => $cover,
            'revisions' => $group->map(static fn (VideoSocialPackage $row): array => ['id' => (string) $row->id, 'revision' => (int) $row->revision, 'status' => (string) $row->status, 'hash' => (string) $row->package_hash, 'at' => $row->created_at?->toDateTimeString()])->all(),
            'publications' => VideoSocialPublication::query()->where('package_revision_id', $package->id)->orderByDesc('created_at')->get()->map(static fn (VideoSocialPublication $publication): array => self::posting($publication))->all(),
            'api' => [
                'enabled' => SocialConnectionService::publishing((string) $rules['platform']),
                'problems' => ($channel = VideoSocialChannel::query()->with('connection')->find($package->channel_id)) === null ? ['Hồ sơ kênh không còn.'] : match (true) {
                    ($target = SocialChannelService::targetProblem((array) $source['channel'], $channel)) !== null => [$target.' — không gửi được bản này. '.SocialChannelService::RETARGET],
                    $channel->status !== VideoSocialChannel::ACTIVE => [SocialPublicationService::ARCHIVED],
                    default => $this->connections->problems($channel),
                },
            ],
        ];
    }

    /** @return array<string, mixed> a posting as the screen shows it; the session a platform gave is never part of it */
    public static function posting(VideoSocialPublication $publication): array
    {
        return [
            'id' => (string) $publication->id,
            'method' => (string) $publication->method,
            'status' => (string) $publication->status,
            'intent' => (string) $publication->intent,
            'external_id' => $publication->external_id,
            'url' => $publication->external_url,
            'scheduled_at' => $publication->scheduled_at,
            'published_at' => $publication->published_at,
            'confirmed_by_person' => $publication->confirmed_by !== null,
            'error_code' => $publication->error_code,
            'error_message' => $publication->error_message,
            'payload_hash' => $publication->payload_hash,
            'steps' => array_values((array) $publication->steps_json),
            'created_at' => $publication->created_at,
        ];
    }
}
