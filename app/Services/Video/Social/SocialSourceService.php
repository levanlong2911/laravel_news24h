<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\AiPhase;
use App\Models\VideoProject;
use App\Video\Media\MediaProbe;
use App\Video\Social\SocialPolicy;
use App\Video\Social\SocialSource;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What Social Publishing reads before anything is written: the approved film of a project with everything that film
 * was made from, and the platform policy in force. Both are read from what is stored — the film through the reader of
 * its workflow, the policy through the phase resolver — and neither is ever replaced by a default when it is missing.
 */
final class SocialSourceService
{
    public function __construct(private readonly MediaProbe $probe, private readonly PhaseResolver $resolver) {}

    public static function enabled(): bool
    {
        return (bool) config('video.social.enabled');
    }

    /**
     * @return array{workflow: string, settings: array<string, mixed>, release: ?string, project: VideoProject} the Social Publishing flow of the project's workflow
     *
     * @throws RuntimeException when the workflow of the project has none
     */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? '');
        $entry = $workflow === '' ? null : (config('video.social.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException('Chủ đề của dự án này chưa có Social Publishing.');
        }

        $key = config('video.superyacht.contracts.workflows.'.$workflow.'.release_key');
        $release = is_string($key) ? ($project->metadata_json[$key] ?? null) : null;

        return ['workflow' => $workflow, 'settings' => $entry, 'release' => is_string($release) && $release !== '' ? $release : null, 'project' => $project];
    }

    public function supports(string $projectId): bool
    {
        try {
            $this->workflowFor($projectId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The approved film and its sources, read now: the file is hashed again and measured, and every source is the one
     * the film pins. The path of the file is returned beside the snapshot and is no part of it.
     *
     * @return array{snapshot: array<string, mixed>, path: string} snapshot: format, hash (the source hash), pins, film, facts, refs, assets, missing
     *
     * @throws RuntimeException when there is no approved film, its file is gone or changed or cannot be measured, or a source it pins cannot be read as pinned
     */
    public function read(string $projectId): array
    {
        $source = $this->reader($this->workflowFor($projectId))->read($projectId);
        $path = (string) $source['film']['path'];
        $measured = $this->probe->inspect($path);

        if (! ($measured['ok'] ?? false)) {
            throw new RuntimeException('Không đo được file phim đã duyệt: '.($measured['error'] ?? 'không rõ lý do').'.');
        }

        $film = array_diff_key($source['film'], ['path' => true]) + ['media' => [
            'container' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'width' => $measured['width'],
            'height' => $measured['height'],
            'fps' => self::rate($measured['video']['avg_frame_rate'] ?? $measured['video']['r_frame_rate'] ?? null),
            'duration_ms' => $measured['duration_ms'],
            'bytes' => (int) filesize($path),
            'has_audio' => (int) $measured['audio_count'] > 0,
            'video_codec' => $measured['video']['codec_name'] ?? null,
            'audio_codec' => $measured['audio']['codec_name'] ?? null,
        ]];

        return [
            'snapshot' => [
                'format' => SocialSource::FORMAT,
                'hash' => CanonicalJson::hash($source['pins']),
                'pins' => $source['pins'],
                'film' => $film,
                'facts' => $source['facts'],
                'refs' => $source['refs'],
                'assets' => $source['assets'],
                'missing' => $source['missing'],
            ],
            'path' => $path,
        ];
    }

    /**
     * Inside the transaction of an approval, of a model answer becoming a revision, or of a request to post: proves the
     * film a revision is about is still the one the project hands on, after the file was hashed outside the transaction.
     * What selects the film stays locked until the transaction ends.
     *
     * @param  array<string, mixed>  $pins  the pins the revision was written from
     * @return ?string why the film is no longer the approved one; null when it still is
     *
     * @throws RuntimeException outside a transaction, or when the workflow has no reader
     */
    public function selected(string $projectId, array $pins): ?string
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('selected() proves the approved film only inside a transaction.');
        }

        return $this->reader($this->workflowFor($projectId))->selected($projectId, $pins);
    }

    /**
     * @return array{contract: array{phase_id: string, phase_revision: int, policy_hash: string}, policy: SocialPolicy, schemas: list<array<string, mixed>>, workflow: string, release: ?string, phase_code: string} the platform policy in force for the project, with the schema a package document is written in
     *
     * @throws RuntimeException when the contract is not ready or the stored policy is not a valid SocialPlatformPolicy
     */
    public function policy(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $resolved = $this->resolve($flow, (string) $flow['settings']['publish_action']);
        $manifest = $resolved->manifest();
        $text = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');
        $report = $resolved->validateSlot('input', 'platform_policy', $text);

        if (! $report->passes()) {
            throw new RuntimeException('Chính sách nền tảng trong DB không đạt SocialPlatformPolicy: '.implode('; ', array_slice($report->messages(), 0, 3)).'.');
        }

        $policy = (array) json_decode($text, true, 64, JSON_THROW_ON_ERROR);

        return [
            'contract' => ['phase_id' => (string) $manifest['phase']['id'], 'phase_revision' => (int) $manifest['phase']['revision'], 'policy_hash' => CanonicalJson::hash($policy)],
            'policy' => new SocialPolicy($policy),
            'schemas' => $resolved->outputSchemas(),
            'workflow' => $flow['workflow'],
            'release' => $flow['release'],
            'phase_code' => (string) $manifest['phase']['code'],
        ];
    }

    /**
     * @param  array{workflow: string, settings: array<string, mixed>, release: ?string, project: VideoProject}  $flow  workflowFor()
     * @return ResolvedPhase the contract of an action of Phase 12, ready
     *
     * @throws RuntimeException when the action is not declared, its phase is not seeded, or its schemas do not match what the action declares
     */
    public function resolve(array $flow, string $action): ResolvedPhase
    {
        try {
            $resolved = $this->resolver->forProject($flow['project'], $action);
        } catch (RuntimeException $e) {
            throw new RuntimeException("Hợp đồng {$action} của Phase 12 chưa có trong DB: ".$e->getMessage().' — chạy php artisan db:seed --class=AiPhaseSeeder trước.');
        }

        if (! $resolved->ready()) {
            throw new RuntimeException("Hợp đồng {$action} chưa sẵn sàng: ".implode('; ', $resolved->mismatches()).' — chạy php artisan db:seed --class=AiPhaseSeeder trước.');
        }

        return $resolved;
    }

    /**
     * Holds, until the transaction it runs in ends, the platform policy an approval or a posting is about to rely on, and
     * proves the contract was read from the revision held: a revision published meanwhile waits, and a contract read from
     * an older one is refused.
     *
     * @param  array<string, mixed>  $contract  policy(), read inside this transaction
     *
     * @throws RuntimeException outside a transaction, or when the revision in force is not the one the contract was read from
     */
    public function hold(string $projectId, array $contract): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('hold() holds the platform policy only inside a transaction.');
        }

        $category = DB::table('articles')->where('id', VideoProject::query()->whereKey($projectId)->value('article_id'))->sharedLock()->value('category_id');
        $rows = AiPhase::query()
            ->where('category_id', $category)
            ->where('workflow_code', $contract['workflow'])
            ->where('phase_code', $contract['phase_code'])
            ->sharedLock()
            ->get()
            ->all();
        $held = PhaseResolver::choose($rows, $this->resolver->rule($contract['workflow'], $contract['release'], $contract['phase_code']));

        if ($held === null || (string) $held->id !== (string) $contract['contract']['phase_id']) {
            throw new RuntimeException('Chính sách nền tảng vừa có revision mới chen vào — tải lại trang rồi thao tác lại.');
        }
    }

    /**
     * @param  array{workflow: string, settings: array<string, mixed>}  $flow  workflowFor()
     *
     * @throws RuntimeException when the workflow names no usable reader
     */
    private function reader(array $flow): SocialSource
    {
        $reader = app((string) $flow['settings']['source']);

        return $reader instanceof SocialSource ? $reader : throw new RuntimeException("Bộ đọc nguồn Social Publishing của workflow {$flow['workflow']} không hợp lệ.");
    }

    /** @return ?float frames a second from the fraction ffprobe writes, null when it is not one */
    private static function rate(mixed $fraction): ?float
    {
        if (! is_string($fraction) || preg_match('/^(\d+)(?:\/(\d+))?$/', $fraction, $parts) !== 1) {
            return null;
        }

        $divisor = (int) ($parts[2] ?? 1);

        return $divisor === 0 || (int) $parts[1] === 0 ? null : round((int) $parts[1] / $divisor, 3);
    }
}
