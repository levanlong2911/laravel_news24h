<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoProject;
use App\Models\VideoSocialChannel;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Social\SocialPackageDocument;
use App\Video\Social\SocialPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The packages of a project and their revisions. A package is one project, one channel, one format and one language;
 * its content never changes in place — an edit, a plan of a model, a cover or a move to another film is each a new
 * revision, a draft, and the revisions before it stay exactly as they were, with their approvals and their postings.
 *
 * A revision keeps what it was written from: the film and its sources, the channel profile and the platform policy,
 * as they were. Nothing here calls a model, and nothing here reads what a text means.
 */
final class SocialPackageService
{
    public function __construct(private readonly SocialSourceService $sources) {}

    /**
     * @return VideoSocialPackage a revision of a package of this project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function owned(string $projectId, string $packageId): VideoSocialPackage
    {
        return VideoSocialPackage::query()->whereKey($packageId)->where('project_id', $projectId)->firstOrFail();
    }

    /**
     * Opens the package of one channel, one format and one language for the film approved now. A package that exists is
     * returned as it is: there is one package for each such target, and its history is its revisions.
     *
     * @return array{0: VideoSocialPackage, 1: string} the newest revision and created|exists
     *
     * @throws RuntimeException when the target is not usable, there is no approved film, or the file of the film is not one the format takes
     */
    public function create(string $projectId, string $channelId, string $format, string $language, ?string $goal, ?string $actorId): array
    {
        $contract = $this->sources->policy($projectId);
        $rules = $this->rulesFor($contract, $format);
        $channel = VideoSocialChannel::query()->whereKey($channelId)->where('status', VideoSocialChannel::ACTIVE)->first() ?? throw new RuntimeException('Không tìm thấy hồ sơ kênh đang dùng.');

        if ($rules['platform'] !== $channel->platform) {
            throw new RuntimeException("{$rules['label']} là định dạng của {$rules['platform']}; kênh {$channel->display_name} thuộc {$channel->platform}.");
        }

        if (! isset(config('video.social.languages')[$language])) {
            throw new RuntimeException('Ngôn ngữ không hợp lệ.');
        }

        $existing = $this->newestOf($projectId, $channelId, $format, $language);

        if ($existing !== null) {
            return [$existing, 'exists'];
        }

        // The film is hashed and measured before any lock is taken.
        $snapshot = $this->sources->read($projectId)['snapshot'];
        self::assertMedia($rules, $snapshot);

        return DB::transaction(function () use ($projectId, $channel, $format, $language, $goal, $actorId, $contract, $rules, $snapshot): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $existing = $this->newestOf($projectId, (string) $channel->id, $format, $language);

            if ($existing !== null) {
                return [$existing, 'exists'];
            }

            // The film was hashed before this transaction; that the project still hands it on is proved under the lock.
            if (($moved = $this->sources->selected($projectId, (array) $snapshot['pins'])) !== null) {
                throw new RuntimeException('Phim đã duyệt của dự án vừa đổi trong lúc mở gói ('.$moved.') — chưa tạo gói; tải lại trang rồi mở lại.');
            }

            $source = self::source($snapshot, $channel, $contract, $rules, self::goal($goal) ?? $channel->default_goal);
            $package = $this->store($projectId, ['package_group_id' => (string) Str::uuid(), 'channel_id' => (string) $channel->id, 'format' => $format, 'language' => $language], null, $source, [
                'fields' => SocialPackageDocument::blank($source['channel'], $rules, $language),
                'plan' => null,
                'artwork' => null,
            ], null, $actorId, $contract['schemas'], 'package_created');

            return [$package, 'created'];
        });
    }

    /**
     * A new revision with what a person changed. Choosing a hook of the plan puts its text at the opening.
     *
     * @param  array<string, mixed>  $input  any of SocialPackageDocument::FIELDS, and `apply_hook`: the id of a hook of the plan to open with
     * @return array{0: VideoSocialPackage, 1: string} the revision and revised|unchanged
     *
     * @throws RuntimeException when the page showed an older revision, or a choice is not one the format has
     */
    public function revise(string $projectId, string $packageId, string $expectedHash, array $input, ?string $actorId): array
    {
        $schemas = $this->sources->policy($projectId)['schemas'];

        return DB::transaction(function () use ($projectId, $packageId, $expectedHash, $input, $actorId, $schemas): array {
            $current = $this->editable($projectId, $packageId, $expectedHash);
            $content = (array) $current->content_json;
            $fields = (array) $content['fields'];

            if (is_string($input['apply_hook'] ?? null) && $input['apply_hook'] !== '') {
                $hook = collect((array) ($content['plan']['hook_options'] ?? []))->firstWhere('id', $input['apply_hook']) ?? throw new RuntimeException('Hook này không có trong đề xuất của gói.');
                $input = ['hook_id' => $hook['id'], 'opening' => $hook['text']] + array_diff_key($input, ['opening' => true]);
            } elseif (is_string($input['hook_id'] ?? null) && $input['hook_id'] !== '') {
                // A hook chosen in the content form, saved with the other fields. It is one of the plan's hooks by its id; its
                // text becomes the opening unless the person also changed the opening in this save — then their words stay.
                $hook = collect((array) ($content['plan']['hook_options'] ?? []))->firstWhere('id', $input['hook_id']) ?? throw new RuntimeException('Hook này không có trong đề xuất của gói.');
                $typed = array_key_exists('opening', $input) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $input['opening'])) : null;

                if ($hook['id'] !== ($fields['hook_id'] ?? null) && ($typed === null || $typed === trim((string) ($fields['opening'] ?? '')))) {
                    $input['opening'] = $hook['text'];
                }
            }

            $fields = SocialPackageDocument::edited($input, $fields);
            self::assertChoices((array) $current->source_json['policy']['rules'], $fields);

            return $this->next($current, (array) $current->source_json, ['fields' => $fields] + $content, $actorId, $schemas, 'package_revised');
        });
    }

    /**
     * A new revision that carries a cover, or none. Called once the file of the cover exists.
     *
     * @param  ?array<string, mixed>  $artwork  the cover as SocialArtworkService made it, null to take the cover off
     * @return array{0: VideoSocialPackage, 1: string}
     */
    public function withArtwork(string $projectId, string $packageId, string $expectedHash, ?array $artwork, ?string $actorId): array
    {
        $schemas = $this->sources->policy($projectId)['schemas'];

        return DB::transaction(function () use ($projectId, $packageId, $expectedHash, $artwork, $actorId, $schemas): array {
            $current = $this->editable($projectId, $packageId, $expectedHash);

            // A cover is cut outside every transaction: that the project still hands on the film the revision is about is proved here, under a lock on what selects it.
            if ($artwork !== null && ($moved = $this->sources->selected($projectId, (array) $current->source_json['pins'])) !== null) {
                throw new RuntimeException('Phim đã duyệt của dự án vừa đổi ('.$moved.') nên cover không được gắn vào gói này — bấm "Cập nhật theo nguồn hiện hành" rồi làm cover từ phim hiện hành.');
            }

            return $this->next($current, (array) $current->source_json, ['artwork' => $artwork] + (array) $current->content_json, $actorId, $schemas, $artwork === null ? 'cover_removed' : 'cover_selected');
        });
    }

    /**
     * A new revision whose text starts from the plan a model wrote for the revision before it. The caller holds the
     * transaction and has proved the plan still applies; nothing is checked again here but the revision itself.
     *
     * @param  VideoSocialPackage  $current  the newest revision of its package, locked
     * @param  array<string, mixed>  $plan  a SocialPublishingPlan that passed its check
     * @param  list<array<string, mixed>>  $schemas  the schema of a package document
     */
    public function fromPlan(VideoSocialPackage $current, array $plan, string $stageId, ?string $actorId, array $schemas): VideoSocialPackage
    {
        $content = (array) $current->content_json;
        $next = [
            'fields' => SocialPackageDocument::fromPlan($plan, (array) $content['fields']),
            'plan' => array_intersect_key($plan, array_flip(['strategy', 'hook_options', 'selected_hook_id', 'claims', 'artwork_options', 'review_notes'])),
        ] + $content;

        return $this->store((string) $current->project_id, $current->only(['package_group_id', 'channel_id', 'format', 'language']), $current, (array) $current->source_json, $next, ['stage_id' => $stageId, 'content' => $plan], $actorId, $schemas, 'package_written');
    }

    /**
     * A new revision about the film approved now, for the channel profile and the platform policy as they are now. The
     * text a person wrote is kept; a cover cut from the film before is left behind, since it shows another file.
     *
     * @return array{0: VideoSocialPackage, 1: string} the revision and rebased|rebased_without_cover
     *
     * @throws RuntimeException when the page showed an older revision, there is no approved film, or its file is not one the format takes
     */
    public function rebase(string $projectId, string $packageId, string $expectedHash, ?string $actorId): array
    {
        $before = $this->owned($projectId, $packageId);
        $contract = $this->sources->policy($projectId);
        $rules = $this->rulesFor($contract, (string) $before->format);
        $channel = VideoSocialChannel::query()->findOrFail($before->channel_id);
        $snapshot = $this->sources->read($projectId)['snapshot'];
        self::assertMedia($rules, $snapshot);

        return DB::transaction(function () use ($projectId, $packageId, $expectedHash, $actorId, $contract, $rules, $channel, $snapshot): array {
            $current = $this->editable($projectId, $packageId, $expectedHash);

            if (($moved = $this->sources->selected($projectId, (array) $snapshot['pins'])) !== null) {
                throw new RuntimeException('Phim đã duyệt của dự án vừa đổi trong lúc cập nhật gói ('.$moved.') — chưa tạo revision; tải lại trang rồi thử lại.');
            }

            $content = (array) $current->content_json;
            $fields = (array) $content['fields'];
            $allowed = (array) $rules['metadata']['privacy'];
            $fields['privacy'] = $allowed === [] ? null : (in_array($fields['privacy'] ?? null, $allowed, true) ? $fields['privacy'] : $rules['metadata']['default_privacy']);
            $fields['made_for_kids'] = $rules['metadata']['made_for_kids'] ? (bool) ($fields['made_for_kids'] ?? false) : null;
            $cover = is_array($content['artwork'] ?? null) ? $content['artwork'] : null;
            $kept = $cover !== null && (($cover['recipe']['source']['kind'] ?? null) !== 'film_frame' || hash_equals((string) $snapshot['film']['sha256'], (string) ($cover['recipe']['source']['sha256'] ?? '')));
            $source = self::source($snapshot, $channel, $contract, $rules, $current->source_json['goal'] ?? null);
            [$package] = $this->next($current, $source, ['fields' => $fields, 'artwork' => $kept ? $cover : null] + $content, $actorId, $contract['schemas'], 'package_rebased', true);

            return [$package, $cover !== null && ! $kept ? 'rebased_without_cover' : 'rebased'];
        });
    }

    /**
     * @param  array<string, mixed>  $now  what holds now: `source_hash` of the film approved now (null when there is none), `channels` (the hash of every profile by id) and `policy_hash`
     * @return array{film_changed: bool, channel_changed: bool, policy_changed: bool, problems: list<string>} how a revision stands against what holds now, and what the platform would refuse in its text. Reading it changes nothing
     */
    public static function standing(VideoSocialPackage $package, array $now): array
    {
        $source = (array) $package->source_json;
        $document = (array) $package->document_json;

        return [
            'film_changed' => ! is_string($now['source_hash'] ?? null) || ! hash_equals((string) $package->source_hash, $now['source_hash']),
            'channel_changed' => ! hash_equals((string) ($source['channel_hash'] ?? ''), (string) ($now['channels'][(string) $package->channel_id] ?? '')),
            'policy_changed' => ! hash_equals((string) ($source['policy']['policy_hash'] ?? ''), (string) ($now['policy_hash'] ?? '')),
            'problems' => SocialPolicy::textProblems((array) $source['policy']['rules'], (array) $document['rendered'], (array) $document['content']['hashtags']),
        ];
    }

    /**
     * @return VideoSocialPackage the newest revision of the package, with every revision of it locked until the transaction ends
     *
     * @throws RuntimeException when the revision named is not the newest, or is not the one the page showed
     */
    public function editable(string $projectId, string $packageId, string $expectedHash): VideoSocialPackage
    {
        $named = $this->owned($projectId, $packageId);
        $newest = VideoSocialPackage::query()->where('package_group_id', $named->package_group_id)->lockForUpdate()->orderByDesc('revision')->get()->first();

        if ($newest === null || (string) $newest->id !== (string) $named->id || ! hash_equals((string) $newest->package_hash, $expectedHash)) {
            throw new RuntimeException('Gói này đã có revision mới hơn bản đang mở — tải lại trang rồi sửa trên bản mới.');
        }

        return $newest;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $schemas
     * @param  bool  $always  store a revision also when the document reads the same, as a move to another film does
     * @return array{0: VideoSocialPackage, 1: string} the next revision, or the current one with `unchanged` when nothing a package says has changed
     */
    private function next(VideoSocialPackage $current, array $source, array $content, ?string $actorId, array $schemas, string $event, bool $always = false): array
    {
        $ai = $current->ai_stage_id === null ? null : ['stage_id' => (string) $current->ai_stage_id, 'content' => (array) $current->ai_content_json];
        $identity = $current->only(['package_group_id', 'channel_id', 'format', 'language']);

        if (! $always && hash_equals((string) $current->package_hash, SocialPackageDocument::hash(self::document((string) $current->project_id, $identity, $source, $content)))) {
            return [$current, 'unchanged'];
        }

        return [$this->store((string) $current->project_id, $identity, $current, $source, $content, $ai, $actorId, $schemas, $event), 'revised'];
    }

    /**
     * @param  array{package_group_id: string, channel_id: string, format: string, language: string}  $identity
     * @param  array<string, mixed>  $source
     * @param  array{fields: array<string, mixed>, plan: ?array<string, mixed>, artwork: ?array<string, mixed>}  $content
     * @param  ?array{stage_id: string, content: array<string, mixed>}  $ai
     * @param  list<array<string, mixed>>  $schemas
     *
     * @throws RuntimeException when the document is not a valid SocialPackage
     */
    private function store(string $projectId, array $identity, ?VideoSocialPackage $previous, array $source, array $content, ?array $ai, ?string $actorId, array $schemas, string $event): VideoSocialPackage
    {
        $content = array_intersect_key($content, array_flip(['fields', 'plan', 'artwork']));
        $document = self::document($projectId, $identity, $source, $content);
        $errors = PhaseOneEvidence::schemaErrors(json_decode((string) json_encode($document, JSON_THROW_ON_ERROR)), $schemas);

        if ($errors !== []) {
            throw new RuntimeException('Gói không đạt hợp đồng SocialPackage: '.implode('; ', array_slice($errors, 0, 3)).'.');
        }

        $package = VideoSocialPackage::create($identity + [
            'revision' => 1 + (int) VideoSocialPackage::query()->where('package_group_id', $identity['package_group_id'])->lockForUpdate()->max('revision'),
            'project_id' => $projectId,
            'previous_revision_id' => $previous?->id,
            'final_id' => (string) $source['pins']['final_id'],
            'source_json' => $source,
            'source_hash' => (string) $source['hash'],
            'ai_stage_id' => $ai['stage_id'] ?? null,
            'ai_content_json' => $ai['content'] ?? null,
            'content_json' => $content,
            'document_json' => $document,
            'package_hash' => SocialPackageDocument::hash($document),
            'artwork_artifact_id' => $content['artwork']['artifact_id'] ?? null,
            'status' => VideoSocialPackage::DRAFT,
            'created_by' => $actorId,
        ]);
        VideoSocialEvent::record($projectId, $event, (string) $package->id, null, $actorId, ['revision' => (int) $package->revision, 'package_hash' => (string) $package->package_hash, 'previous_revision_id' => $previous?->id]);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $content
     * @return array<string, mixed> the package document of a revision
     */
    private static function document(string $projectId, array $identity, array $source, array $content): array
    {
        $cover = is_array($content['artwork'] ?? null) ? $content['artwork'] : null;

        return SocialPackageDocument::assemble(
            $projectId,
            [
                'channel_id' => (string) $identity['channel_id'],
                'platform' => (string) $source['channel']['platform'],
                'format' => (string) $identity['format'],
                'language' => (string) $identity['language'],
                'external_id' => $source['channel']['external_id'] ?? null,
                'channel_hash' => (string) $source['channel_hash'],
            ],
            ['hash' => (string) $source['hash'], 'final_id' => (string) $source['pins']['final_id'], 'artifact_sha256' => (string) $source['pins']['artifact_sha256'], 'decision_id' => (string) $source['pins']['decision_id']],
            (array) $source['policy'],
            (array) $content['fields'],
            $cover,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot  SocialSourceService::read()
     * @param  array<string, mixed>  $contract  SocialSourceService::policy()
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed> everything a revision was written from: the film and its sources, the channel profile, the platform policy and the goal
     */
    private static function source(array $snapshot, VideoSocialChannel $channel, array $contract, array $rules, ?string $goal): array
    {
        $profile = SocialChannelService::snapshot($channel);

        return $snapshot + [
            'channel' => $profile,
            'channel_hash' => SocialChannelService::hash($profile),
            'policy' => $contract['contract'] + ['checked_on' => $contract['policy']->checkedOn(), 'rules' => $rules],
            'goal' => $goal,
        ];
    }

    /**
     * @param  array<string, mixed>  $contract  SocialSourceService::policy()
     * @return array<string, mixed> the rules of a format a package may be made for
     *
     * @throws RuntimeException for a format the configuration or the policy does not know
     */
    private function rulesFor(array $contract, string $format): array
    {
        $rules = isset(config('video.social.formats')[$format]) ? $contract['policy']->rules($format) : null;

        return $rules ?? throw new RuntimeException('Định dạng '.json_encode($format).' không có trong chính sách nền tảng.');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $snapshot
     *
     * @throws RuntimeException when the file of the film is not one the format takes. The film is not cut again here
     */
    private static function assertMedia(array $rules, array $snapshot): void
    {
        $problems = SocialPolicy::mediaProblems($rules, (array) $snapshot['film']['media']);

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems).' Phase 12 không dựng lại phim — tạo bản xuất phù hợp ở Final Composition (Phase 11), duyệt bản đó rồi mở gói.');
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $fields
     *
     * @throws RuntimeException when a choice is not one the format has
     */
    private static function assertChoices(array $rules, array $fields): void
    {
        $allowed = (array) $rules['metadata']['privacy'];

        if (($allowed === [] && ($fields['privacy'] ?? null) !== null) || ($allowed !== [] && ! in_array($fields['privacy'] ?? null, $allowed, true))) {
            throw new RuntimeException("{$rules['label']} nhận chế độ hiển thị: ".($allowed === [] ? 'không có lựa chọn' : implode(', ', $allowed)).'.');
        }

        if (is_string($fields['link'] ?? null) && preg_match('/^https?:\/\/\S+$/i', $fields['link']) !== 1) {
            throw new RuntimeException('Liên kết phải là một địa chỉ http(s) đầy đủ, không có khoảng trắng.');
        }
    }

    private function newestOf(string $projectId, string $channelId, string $format, string $language): ?VideoSocialPackage
    {
        return VideoSocialPackage::query()
            ->where('project_id', $projectId)->where('channel_id', $channelId)->where('format', $format)->where('language', $language)
            ->orderByDesc('revision')->first();
    }

    private static function goal(?string $goal): ?string
    {
        $goal = trim((string) $goal);

        return $goal === '' ? null : Str::limit($goal, 500, '');
    }
}
