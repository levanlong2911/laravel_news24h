<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\FinalComposition\CompositionPlanBuilder;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Moto\Film\MotoFilmEditPlan;
use App\Video\Moto\Film\MotoFilmFont;
use App\Video\Moto\Film\MotoFilmPlanBuilder;
use App\Video\Moto\Film\MotoFilmScope;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the phase-11 policy from the DB, the profile and the card style it names, and the sources, and compiles the film plan.
 * A preview writes nothing; a render request saves the plan it freezes. No model is called anywhere here.
 */
final class MotoFilmPlanService implements MotoFilmPlans
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

    /** @var string what a confirmation of an edit plan is recorded against: the stored edit plan, by its stage */
    public const EDIT_ENTITY = 'moto_film_edit_plan';

    public function __construct(
        private readonly MotoFilmSourceService $sources,
        private readonly PlanningStageStore $stages,
        private readonly PhaseResolver $resolver,
    ) {}

    /**
     * @return ?array{contract: array{phase_id: string, phase_revision: int, policy_sha256: string, policy_hash: string, bundle_hash: string}, policy: array<string, mixed>, schemas: list<array<string, mixed>>, phase_code: string} the edit contract of the project: the edit policy read from the DB and the schema an edit plan is written in; null for a project whose release declares no edit action, which cuts its film with no edit plan, as before
     *
     * @throws RuntimeException when the release declares the edit action and its contract or its policy is not ready
     */
    public function editContract(string $projectId): ?array
    {
        $flow = $this->sources->workflowFor($projectId);
        $action = $flow['settings']['edit_action'] ?? null;
        $project = VideoProject::query()->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');

        if (! is_string($action) || $this->resolver->phaseOf($flow['workflow'], self::releaseOf($flow['workflow'], $project->metadata_json), $action) === null) {
            return null;
        }

        $resolved = $this->resolver->forProject($project, $action);

        if (! $resolved->ready()) {
            throw new RuntimeException('Hợp đồng phương án dựng Phase 11 chưa sẵn sàng: '.implode('; ', $resolved->mismatches()).'.');
        }

        $manifest = $resolved->manifest();
        $text = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');
        $report = $resolved->validateSlot('input', 'edit_policy', $text);

        if (! $report->passes()) {
            throw new RuntimeException('Chính sách phương án dựng trong DB không đạt MotoFilmEditPolicy: '.implode('; ', array_slice($report->messages(), 0, 3)).'.');
        }

        $policy = (array) json_decode($text, true, 64, JSON_THROW_ON_ERROR);

        // The plan this code writes is the default one, in the schema this code knows; a policy that asks for another is one this code cannot serve.
        if (($policy['edit_plan'] ?? null) !== MotoFilmEditPlan::SCHEMA || ! in_array(MotoFilmEditPlan::ORIGIN_DEFAULT, (array) ($policy['origins'] ?? []), true)) {
            throw new RuntimeException('Chính sách phương án dựng đòi điều bản này chưa làm được: schema '.json_encode($policy['edit_plan'] ?? null).', nguồn tạo '.json_encode($policy['origins'] ?? null).' — code đang viết '.MotoFilmEditPlan::SCHEMA.' với nguồn tạo '.MotoFilmEditPlan::ORIGIN_DEFAULT.'.');
        }

        return [
            'contract' => [
                'phase_id' => (string) $manifest['phase']['id'],
                'phase_revision' => (int) $manifest['phase']['revision'],
                'policy_sha256' => (string) $manifest['phase']['sha256'],
                'policy_hash' => CanonicalJson::hash($policy),
                'bundle_hash' => $resolved->bundleHash(),
            ],
            'policy' => $policy,
            'schemas' => $resolved->outputSchemas(),
            'phase_code' => (string) $manifest['phase']['code'],
        ];
    }

    /** @return ?string the release a project pinned for the workflow, null when it pinned none or the workflow declares no release key */
    private static function releaseOf(string $workflow, mixed $metadata): ?string
    {
        $key = config('video.superyacht.contracts.workflows.'.$workflow.'.release_key');
        $release = is_string($key) && is_array($metadata) ? ($metadata[$key] ?? null) : null;

        return is_string($release) && $release !== '' ? $release : null;
    }

    /**
     * @return array{contract: array<string, mixed>, policy: array<string, mixed>, profile: array<string, mixed>, style: array<string, mixed>, font: MotoFilmFont, schemas: list<array<string, mixed>>}
     *
     * @throws RuntimeException when the phase-11 contract, its policy, the profile, the card style or its font is not ready
     */
    public function contract(string $projectId): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $resolved = $this->resolver->forProject($projectId, $flow['action']);

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
     * @return list<array{id: string, phase_code: string, revision: int}> the revisions held: for each phase the one the resolver chooses for the project under the release it pinned, chosen among the rows locked here
     *
     * @throws RuntimeException outside a transaction, or for a project with no film flow
     */
    public function guard(string $projectId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('guard() holds the sources only inside a transaction.');
        }

        $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first(['id', 'article_id', 'metadata_json']) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = DB::table('articles')->where('id', $project->article_id)->sharedLock()->value('category_id');
        $slug = $category === null ? null : DB::table('categories')->where('id', $category)->sharedLock()->value('slug');
        $workflow = (string) (config('video.superyacht.contracts.categories')[(string) $slug] ?? '');

        if ($workflow === '' || ! is_array(config('video.films.workflows')[$workflow] ?? null)) {
            throw new RuntimeException('Dự án này không dựng final theo chính sách Phase 11.');
        }

        $release = self::releaseOf($workflow, $project->metadata_json);
        // A release that cuts from an edit plan reads the edit policy too, so its revision is held with the others.
        $action = config('video.films.workflows')[$workflow]['edit_action'] ?? null;
        $edit = is_string($action) ? $this->resolver->phaseOf($workflow, $release, $action) : null;
        $codes = $edit === null ? self::LIVE_PHASES : [...self::LIVE_PHASES, $edit];
        $rows = AiPhase::query()
            ->where('category_id', $category)
            ->where('workflow_code', $workflow)
            ->whereIn('phase_code', $codes)
            ->orderBy('phase_code')
            ->sharedLock()
            ->get()
            ->groupBy('phase_code');
        $held = [];

        foreach ($codes as $code) {
            $phase = PhaseResolver::choose($rows->get($code, collect())->all(), $this->resolver->rule($workflow, $release, $code));

            if ($phase !== null) {
                $held[] = ['id' => (string) $phase->id, 'phase_code' => (string) $phase->phase_code, 'revision' => (int) $phase->revision];
            }
        }

        return $held;
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
     * @return array{ready: bool, blockers: list<array{code: string, subject: string, message: string}>, warnings: list<array<string, mixed>>, plan: ?array<string, mixed>, hash: ?string, rows: list<array<string, mixed>>, scope: array<string, mixed>, manifest: array<string, mixed>, revisions: array{phase-10: string, phase-11: string}}
     *
     * @throws RuntimeException when the contract is not ready or the sources cannot be read at all
     */
    public function preview(string $projectId): array
    {
        $contract = $this->contract($projectId);
        $edit = $this->editContract($projectId);
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
            'revisions' => ['phase-10' => (string) $source['manifest']['contract']['phase_id'], 'phase-11' => (string) $contract['contract']['phase_id']]
                + ($edit === null ? [] : [$edit['phase_code'] => (string) $edit['contract']['phase_id']]),
            'edit' => $edit === null ? null : self::editPlans($projectId, $source, $contract, $edit, $built),
        ];
    }

    /**
     * The default edit plan for each purpose the film can be cut for, held against the sources, the policies and the film plan compiled from the same sources.
     *
     * @param  array<string, mixed>  $source  MotoFilmSourceService::source()
     * @param  array<string, mixed>  $contract  contract()
     * @param  array<string, mixed>  $edit  editContract()
     * @param  array<string, mixed>  $built  MotoFilmPlanBuilder::build()
     * @return array{origin: string, contract: array<string, mixed>, confirmation: string, plans: array<string, array{plan: array<string, mixed>, hash: string, problems: list<array{code: string, subject: string, message: string}>}>, refusals: array<string, string>} plans by purpose; a purpose the film cannot be cut for has no plan and says why in refusals. With no film plan there is no edit plan either: what blocks the film is already reported
     *
     * @throws RuntimeException when the plan code wrote breaks its own schema
     */
    private static function editPlans(string $projectId, array $source, array $contract, array $edit, array $built): array
    {
        $plans = [];
        $refusals = [];

        foreach ($built['plan'] === null ? [] : MotoFilmScope::PURPOSES as $purpose) {
            $short = $purpose === MotoFilmScope::FINAL ? MotoFilmScope::finalProblem((array) $built['scope']) : null;

            if ($short !== null) {
                $refusals[$purpose] = $short;

                continue;
            }

            $plan = MotoFilmEditPlan::default($projectId, $purpose, $source, $contract, $edit);
            $errors = PhaseOneEvidence::schemaErrors(json_decode((string) json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), $edit['schemas']);

            if ($errors !== []) {
                throw new RuntimeException('Phương án dựng mặc định không đạt schema '.MotoFilmEditPlan::SCHEMA.': '.implode('; ', array_slice($errors, 0, 3)).'.');
            }

            $plans[$purpose] = [
                'plan' => $plan,
                'hash' => MotoFilmEditPlan::hash($plan),
                'problems' => [...MotoFilmEditPlan::problems($plan, $projectId, $source, $contract, $edit), ...MotoFilmEditPlan::compiled($plan, (array) $built['plan'])],
            ];
        }

        return ['origin' => MotoFilmEditPlan::ORIGIN_DEFAULT, 'contract' => $edit['contract'], 'confirmation' => (string) $edit['policy']['confirmation'], 'plans' => $plans, 'refusals' => $refusals];
    }

    /**
     * Stores the edit plan of that purpose once, by its hash, and records one confirmation of it for this request: the person who
     * asks for the film saw this plan on the page and cuts from it. No stored plan is rewritten; a plan made from other sources is
     * another hash and another revision.
     *
     * @param  array<string, mixed>  $preview  preview(), ready
     * @return ?array{stage_id: string, hash: string, origin: string, purpose: string, schema: string, decision_id: string, plan: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function confirm(string $projectId, array $preview, string $purpose, string $operationId, ?string $reviewerId): ?array
    {
        $edit = $preview['edit'] ?? null;

        if ($edit === null) {
            return null;
        }

        $chosen = $edit['plans'][$purpose] ?? throw new RuntimeException($edit['refusals'][$purpose] ?? 'Chưa có phương án dựng cho mục đích này — kế hoạch phim còn bị chặn.');

        if ($chosen['problems'] !== []) {
            throw new RuntimeException('Phương án dựng không biên dịch được: '.$chosen['problems'][0]['message']);
        }

        // Who wrote the plan is what the server knows, kept beside the plan and outside its hash.
        [$stage, $token, $reason] = $this->stages->claimProjectStage($projectId, PlanningStageName::FILM_EDIT_PLAN,
            ['execution' => MotoFilmEditPlan::VERSION, 'fingerprint' => $chosen['hash']], false, ['origin' => $edit['origin'], 'purpose' => $purpose]);

        if ($token !== null) {
            $saved = ['plan' => $chosen['plan'], 'hash' => $chosen['hash'], 'origin' => $edit['origin'], 'purpose' => $purpose];

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $saved)) {
                throw new RuntimeException('Không lưu được phương án dựng.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Phương án dựng đang được xử lý: '.$reason);
        }

        $decision = VideoReviewDecision::create([
            'project_id' => $projectId,
            'entity_type' => self::EDIT_ENTITY,
            'entity_id' => $stage->id,
            'decision' => 'approved',
            'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', self::EDIT_ENTITY)->where('entity_id', $stage->id)->max('revision'),
            'reviewer_id' => $reviewerId,
            'reason' => 'Xác nhận phương án dựng khi yêu cầu dựng phim.',
            'metadata_json' => [
                'edit_plan_hash' => $chosen['hash'],
                'origin' => $edit['origin'],
                'purpose' => $purpose,
                'operation_id' => $operationId,
                'confirmation' => (string) $edit['confirmation'],
            ],
        ]);

        return [
            'stage_id' => (string) $stage->id,
            'hash' => (string) $chosen['hash'],
            'origin' => (string) $edit['origin'],
            'purpose' => $purpose,
            'schema' => MotoFilmEditPlan::SCHEMA,
            'decision_id' => (string) $decision->id,
            'plan' => $chosen['plan'],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview  preview(), ready
     * @param  ?array<string, mixed>  $edit  confirm(): the edit plan the film plan was compiled for; null for a project that cuts with none
     * @return VideoPlanningStage the saved plan with this hash, this scope and this edit plan, saved once however often it is sent
     *
     * @throws RuntimeException
     */
    public function save(string $projectId, array $preview, ?array $edit = null): VideoPlanningStage
    {
        if ($preview['plan'] === null) {
            throw new RuntimeException('Kế hoạch phim còn bị chặn — không lưu.');
        }

        $scope = $preview['scope'] ?? null;

        if (! is_array($scope)) {
            throw new RuntimeException('Kế hoạch phim không có phạm vi shot — không lưu.');
        }

        // A plan saved with this hash is used again only when it carries this very scope. One saved before the scope was stored with it, or with another reason for a shot left out (the hash covers which shots are left out, not why), stays in the history as it was, and this one is saved as a new revision: no saved row is ever rewritten.
        // The same timeline compiled for another edit plan — a preview and a final of the same clips — is stored apart: a film plan names the one edit plan it was compiled for. With no edit plan the key is what it always was.
        [$stage, $token, $reason] = $this->stages->claimProjectStage($projectId, PlanningStageName::FILM_PLAN,
            ['execution' => MotoFilmPlanBuilder::VERSION, 'fingerprint' => $preview['hash']] + ($edit === null ? [] : ['edit_plan' => (string) $edit['hash']]),
            static fn (VideoPlanningStage $saved): bool => ! self::sameScope($saved->output_json['scope'] ?? null, $scope));

        if ($token !== null) {
            // The shots of the plan this film holds and the ones it leaves out are stored with it: a film that leaves shots out says so wherever its plan is read. So is the edit plan it was compiled for, by the row that stores it and its hash.
            $saved = ['plan' => $preview['plan'], 'hash' => $preview['hash'], 'scope' => $scope]
                + ($edit === null ? [] : ['edit_plan' => array_intersect_key($edit, ['stage_id' => true, 'hash' => true, 'origin' => true, 'purpose' => true, 'schema' => true])]);

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $saved)) {
                throw new RuntimeException('Không lưu được kế hoạch phim.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Kế hoạch phim đang được xử lý: '.$reason);
        }

        return $stage->fresh();
    }

    /**
     * @param  array<string, mixed>  $scope  MotoFilmScope::of() for the plan being saved
     * @return bool whether a saved plan carries that scope: the same shots held and left out, each for the same reason
     */
    private static function sameScope(mixed $saved, array $scope): bool
    {
        return is_array($saved) && CanonicalJson::hash($saved) === CanonicalJson::hash($scope);
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
