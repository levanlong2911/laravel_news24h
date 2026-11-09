<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\FinalComposition\CompositionPlanBuilder;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Moto\Film\MotoFilmFont;
use App\Video\Moto\Film\MotoFilmPlanBuilder;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the phase-11 policy from the DB, the profile and the card style it names, and the sources, and compiles the film plan.
 * A preview writes nothing; a render request saves the plan it freezes. No model is called anywhere here.
 */
final class MotoFilmPlanService
{
    /** @var list<string> every key an output profile must carry; one missing makes the film not ready, never a default */
    private const PROFILE_KEYS = ['width', 'height', 'fps', 'video_codec', 'encoder', 'pixel_format', 'crf', 'audio_codec', 'sample_rate', 'channels', 'fit', 'pad_colour', 'aspect_tolerance'];

    /** @var list<string> every key a card style must carry */
    private const STYLE_KEYS = ['font', 'size_px', 'line_height_px', 'colour', 'border', 'safe_area', 'align', 'anchor', 'max_lines', 'wrap', 'measure', 'language', 'word_counter'];

    /** @var list<string> the phases whose current revision a film plan reads live: the clip template through the Phase 10 manifest, and the film policy */
    private const LIVE_PHASES = ['phase-10', 'phase-11'];

    /** @var array<string, mixed> what the first version cuts; a policy asking for anything else is refused before a plan is built */
    private const IMPLEMENTED = [
        'timeline' => ['interval' => 'half_open', 'quantization' => 'exact'],
        'picture' => ['order' => 'approved_plan', 'trim' => 'approved_used_interval', 'retime' => false, 'crop' => false],
        'audio' => ['policy' => 'clip_only', 'silent_clip' => 'silence', 'boundary_treatment' => 'cut', 'separate_tracks' => false],
        'transitions' => ['cut', 'match_cut', 'time_jump', 'dip'],
        'dip' => ['colour' => 'black', 'overlap' => false],
        'cards' => ['text' => 'verbatim', 'unit' => 'words_per_second'],
    ];

    public function __construct(
        private readonly MotoFilmSourceService $sources,
        private readonly PlanningStageStore $stages,
    ) {}

    /**
     * @return array{contract: array<string, mixed>, policy: array<string, mixed>, profile: array<string, mixed>, style: array<string, mixed>, font: MotoFilmFont, schemas: list<array<string, mixed>>}
     *
     * @throws RuntimeException when the phase-11 contract, its policy, the profile, the card style or its font is not ready
     */
    public function contract(string $projectId): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $resolved = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject($projectId, $flow['action']);

        if (! $resolved->ready()) {
            throw new RuntimeException('Hợp đồng Phase 11 chưa sẵn sàng: '.implode('; ', $resolved->mismatches()).'.');
        }

        $manifest = $resolved->manifest();
        $text = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');
        $report = $resolved->validateSlot('input', 'film_policy', $text);

        if (! $report->passes()) {
            throw new RuntimeException('Chính sách Phase 11 trong DB không đạt MotoFilmPolicy: '.implode('; ', array_slice($report->messages(), 0, 3)).'.');
        }

        $policy = (array) json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        $problem = self::policyProblem($policy);

        if ($problem !== null) {
            throw new RuntimeException('Chính sách Phase 11 đòi điều bản này chưa dựng được: '.$problem.'.');
        }

        $profile = self::profile((string) $policy['output_profile']);
        [$style, $font] = self::style((string) $policy['cards']['style_profile']);

        return [
            'contract' => [
                'phase_id' => (string) $manifest['phase']['id'],
                'phase_revision' => (int) $manifest['phase']['revision'],
                'policy_sha256' => (string) $manifest['phase']['sha256'],
                'policy_hash' => CanonicalJson::hash($policy),
                'bundle_hash' => $resolved->bundleHash(),
            ],
            'policy' => $policy,
            'profile' => $profile,
            'style' => $style,
            'font' => $font,
            'schemas' => $resolved->outputSchemas(),
        ];
    }

    /**
     * Holds still, until the transaction it runs in ends, everything a film plan is read from, so a plan read after it stays true
     * while a request or an approval is written. Every change of a project's sources (script, scene, shot, keyframe and clip
     * approvals, a saved stage) locks the project row first, so the row is locked for update here; a phase revision is published
     * by locking its current row for update, so the current phase-10 and phase-11 rows are locked for share here, and a publish
     * waits until this transaction ends. Read the plan only after this call, inside the same transaction, or use lockedPreview().
     *
     * Every read here is a locking read. Under REPEATABLE READ the first plain read of a transaction fixes what all its later plain
     * reads see; a plain read here, before the phases are locked, would let the plan read a revision older than the one held.
     *
     * @return list<array{id: string, phase_code: string, revision: int}> the current revisions held
     *
     * @throws RuntimeException outside a transaction, or for a project with no film flow
     */
    public function guard(string $projectId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('guard() holds the sources only inside a transaction.');
        }

        $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first(['id', 'article_id']) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = DB::table('articles')->where('id', $project->article_id)->sharedLock()->value('category_id');
        $slug = $category === null ? null : DB::table('categories')->where('id', $category)->sharedLock()->value('slug');
        $workflow = (string) (config('video.superyacht.contracts.categories')[(string) $slug] ?? '');

        if ($workflow === '' || ! is_array(config('video.films.workflows')[$workflow] ?? null)) {
            throw new RuntimeException('Dự án này không dựng final theo chính sách Phase 11.');
        }

        return AiPhase::query()
            ->where('category_id', $category)
            ->where('workflow_code', $workflow)
            ->whereIn('phase_code', self::LIVE_PHASES)
            ->where('is_current', true)
            ->orderBy('phase_code')
            ->sharedLock()
            ->get(['id', 'phase_code', 'revision'])
            ->map(static fn (AiPhase $phase): array => ['id' => (string) $phase->id, 'phase_code' => (string) $phase->phase_code, 'revision' => (int) $phase->revision])
            ->all();
    }

    /**
     * The film plan read inside guard(), and proved to be built from the very revisions it holds: the phase-10 revision the clips
     * were judged against and the phase-11 policy. A plan read from any other revision is refused, never handed on.
     * Call it inside the transaction that writes the request or the approval.
     *
     * @return array<string, mixed> preview()
     *
     * @throws RuntimeException when the revisions differ, or as guard() and preview()
     */
    public function lockedPreview(string $projectId): array
    {
        $held = array_column($this->guard($projectId), 'id', 'phase_code');
        $preview = $this->preview($projectId);
        $used = $preview['revisions'];
        ksort($held);
        ksort($used);

        if ($used !== $held) {
            throw new RuntimeException('Revision phase của kế hoạch phim ('.json_encode($used).') không phải revision đang hiện hành vừa khoá ('.json_encode($held).') — có bản phát hành mới chen vào; tải lại trang rồi thao tác lại.');
        }

        return $preview;
    }

    /**
     * @return array{ready: bool, blockers: list<array{code: string, subject: string, message: string}>, warnings: list<array<string, mixed>>, plan: ?array<string, mixed>, hash: ?string, rows: list<array<string, mixed>>, manifest: array<string, mixed>, revisions: array{phase-10: string, phase-11: string}}
     *
     * @throws RuntimeException when the contract is not ready or the sources cannot be read at all
     */
    public function preview(string $projectId): array
    {
        $contract = $this->contract($projectId);
        $source = $this->sources->source($projectId);
        $built = MotoFilmPlanBuilder::build($projectId, $contract['contract'], $contract['policy'], $contract['profile'], $contract['style'], $contract['font'], $source);

        if ($built['plan'] !== null) {
            $errors = PhaseOneEvidence::schemaErrors(json_decode((string) json_encode($built['plan'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), $contract['schemas']);

            if ($errors !== []) {
                throw new RuntimeException('Kế hoạch phim không đạt schema '.MotoFilmPlanBuilder::SCHEMA.': '.implode('; ', array_slice($errors, 0, 3)).'.');
            }
        }

        return $built + [
            'hash' => $built['plan'] === null ? null : CanonicalJson::hash(['plan' => $built['plan']]),
            'manifest' => $source['manifest'],
            // The revisions this read was built from, as the resolver read them; lockedPreview() holds them against the ones locked.
            'revisions' => ['phase-10' => (string) $source['manifest']['contract']['phase_id'], 'phase-11' => (string) $contract['contract']['phase_id']],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview  preview(), ready
     * @return VideoPlanningStage the saved plan with this hash, saved once however often it is sent
     *
     * @throws RuntimeException
     */
    public function save(string $projectId, array $preview): VideoPlanningStage
    {
        if ($preview['plan'] === null) {
            throw new RuntimeException('Kế hoạch phim còn bị chặn — không lưu.');
        }

        [$stage, $token, $reason] = $this->stages->claimProjectStage($projectId, PlanningStageName::FILM_PLAN,
            ['execution' => MotoFilmPlanBuilder::VERSION, 'fingerprint' => $preview['hash']], false);

        if ($token !== null) {
            $saved = ['plan' => $preview['plan'], 'hash' => $preview['hash']];

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $saved)) {
                throw new RuntimeException('Không lưu được kế hoạch phim.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Kế hoạch phim đang được xử lý: '.$reason);
        }

        return $stage->fresh();
    }

    /** @param array<string, mixed> $policy */
    private static function policyProblem(array $policy): ?string
    {
        $implemented = self::IMPLEMENTED;
        $same = static function (array $a, array $b): bool {
            ksort($a);
            ksort($b);

            return $a === $b;
        };

        foreach (['timeline', 'picture', 'audio'] as $group) {
            if (! $same((array) $policy[$group], $implemented[$group])) {
                return $group.' '.json_encode($policy[$group]);
            }
        }

        $transitions = (array) $policy['transitions'];
        $unknown = array_diff((array) $transitions['allowed'], $implemented['transitions']);

        if ($unknown !== []) {
            return 'chuyển cảnh '.implode(', ', $unknown);
        }

        if (! $same(array_diff_key((array) $transitions['dip'], ['each_side_ms' => true]), $implemented['dip'])) {
            return 'dip '.json_encode($transitions['dip']);
        }

        if ($policy['cards']['text'] !== $implemented['cards']['text'] || $policy['cards']['reading_speed']['unit'] !== $implemented['cards']['unit']) {
            return 'card '.json_encode($policy['cards']);
        }

        return null;
    }

    /**
     * @return array<string, mixed> the profile with its id and the hash of this copy
     *
     * @throws RuntimeException
     */
    private static function profile(string $id): array
    {
        $profile = config('video.films.profiles.'.$id);

        if (! is_array($profile) || array_diff(self::PROFILE_KEYS, array_keys($profile)) !== [] || array_diff(array_keys($profile), self::PROFILE_KEYS) !== []) {
            throw new RuntimeException('Hồ sơ xuất '.$id.' chưa sẵn sàng: cấu hình thiếu hoặc thừa trường ('.implode(', ', self::PROFILE_KEYS).').');
        }

        $size = $profile['width'].'x'.$profile['height'];

        if (! in_array($size, CompositionPlanBuilder::SIZES, true) || ! in_array($profile['fps'], [24, 25, 30], true) || $profile['crf'] < 16 || $profile['crf'] > 28
            || [$profile['video_codec'], $profile['encoder'], $profile['pixel_format'], $profile['audio_codec'], $profile['sample_rate'], $profile['channels'], $profile['fit'], $profile['pad_colour']]
                !== ['h264', 'libx264', 'yuv420p', 'aac', 48000, 2, 'scale_pad', 'black']) {
            throw new RuntimeException('Hồ sơ xuất '.$id.' đòi điều bộ dựng không làm: '.json_encode($profile).'.');
        }

        $copy = ['id' => $id] + $profile;

        return $copy + ['hash' => CanonicalJson::hash($copy)];
    }

    /**
     * @return array{0: array<string, mixed>, 1: MotoFilmFont} the style with its id and the hash of this copy, and its font, opened only when its bytes are the pinned ones
     *
     * @throws RuntimeException
     */
    private static function style(string $id): array
    {
        $style = config('video.films.card_styles.'.$id);

        if (! is_array($style) || array_diff(self::STYLE_KEYS, array_keys($style)) !== [] || array_diff(array_keys($style), self::STYLE_KEYS) !== []
            || ! is_string($style['font']['path'] ?? null) || ! is_string($style['font']['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $style['font']['sha256']) !== 1) {
            throw new RuntimeException('Kiểu card '.$id.' chưa sẵn sàng: cấu hình thiếu trường, thiếu font hoặc thiếu SHA-256 của font.');
        }

        $path = base_path($style['font']['path']);

        try {
            $font = MotoFilmFont::open($path, $style['font']['sha256']);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Kiểu card '.$id.' chưa sẵn sàng: '.$e->getMessage());
        }

        $copy = ['id' => $id] + $style;

        return [$copy + ['hash' => CanonicalJson::hash($copy)], $font];
    }
}
