<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoFinal;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Models\VideoSession;
use App\Video\FinalComposition\CompositionMeasure;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Media\FfmpegBinary;
use App\Video\Media\FfmpegRunner;
use App\Video\Moto\Film\MotoFilmQcReport;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The QC of a cut film, for a project whose release declares the QC action: code measures the file, a person reviews what
 * the film says and shows, findings are raised and settled, and a film is approved only with a report of that very file.
 * A project of a release without the action has no QC here and approves a film as before.
 *
 * Every change makes a new revision of the report; a stored revision is never rewritten. Who reviewed and who settled a
 * finding are what the server recorded. No model is called anywhere here, and measuring a file never holds a transaction open.
 */
final class MotoFilmQcService
{
    /** @var string what a content review is recorded against: the film, by its final */
    public const REVIEW_ENTITY = 'moto_film_qc_review';

    /** @var string what raising or settling a finding is recorded against: the film, by its final */
    public const FINDING_ENTITY = 'moto_film_qc_finding';

    public const CHANGES_REQUESTED = 'changes_requested';

    public function __construct(
        private readonly MotoFilmSourceService $sources,
        private readonly PlanningStageStore $stages,
        private readonly CompositionMeasure $measure,
        private readonly PhaseResolver $resolver,
    ) {}

    /**
     * @return ?array{contract: array{phase_id: string, phase_revision: int, policy_hash: string}, policy: array<string, mixed>, schemas: list<array<string, mixed>>} the QC contract of the project: the QC policy read from the DB and the schema a report is written in; null for a project whose release declares no QC action
     *
     * @throws RuntimeException when the release declares the QC action and its contract or its policy is not ready
     */
    public function contract(string $projectId): ?array
    {
        $flow = $this->sources->workflowFor($projectId);
        $action = $flow['settings']['qc_action'] ?? null;
        $project = VideoProject::query()->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $key = config('video.superyacht.contracts.workflows.'.$flow['workflow'].'.release_key');
        $release = is_string($key) ? ($project->metadata_json[$key] ?? null) : null;

        if (! is_string($action) || $this->resolver->phaseOf($flow['workflow'], is_string($release) && $release !== '' ? $release : null, $action) === null) {
            return null;
        }

        $resolved = $this->resolver->forProject($project, $action);

        if (! $resolved->ready()) {
            throw new RuntimeException('Hợp đồng QC Phase 11 chưa sẵn sàng: '.implode('; ', $resolved->mismatches()).'.');
        }

        $manifest = $resolved->manifest();
        $text = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');
        $report = $resolved->validateSlot('input', 'qc_policy', $text);

        if (! $report->passes()) {
            throw new RuntimeException('Chính sách QC trong DB không đạt MotoFilmQCPolicy: '.implode('; ', array_slice($report->messages(), 0, 3)).'.');
        }

        $policy = (array) json_decode($text, true, 64, JSON_THROW_ON_ERROR);

        if (($policy['report'] ?? null) !== MotoFilmQcReport::SCHEMA || array_diff((array) $policy['content']['required'], (array) $policy['content']['areas']) !== []) {
            throw new RuntimeException('Chính sách QC đòi điều bản này chưa làm được: báo cáo '.json_encode($policy['report'] ?? null).' (code viết '.MotoFilmQcReport::SCHEMA.'), hoặc mục bắt buộc không nằm trong danh sách mục review.');
        }

        return [
            'contract' => ['phase_id' => (string) $manifest['phase']['id'], 'phase_revision' => (int) $manifest['phase']['revision'], 'policy_hash' => CanonicalJson::hash($policy)],
            'policy' => $policy,
            'schemas' => $resolved->outputSchemas(),
            // Where the policy was read from, so the revision can be held and read again: hold().
            'workflow' => (string) $flow['workflow'],
            'release' => is_string($release) && $release !== '' ? $release : null,
            'phase_code' => (string) $manifest['phase']['code'],
        ];
    }

    /**
     * Holds, until the transaction it runs in ends, the QC policy a report or an approval is about to rely on, and proves the
     * contract was read from the revision held. A revision is published by locking the current row of its phase for update, so
     * the rows of the phase are locked for share here and a publish waits until this transaction ends. A locking read sees what
     * was committed last; the contract may have been read from a snapshot taken before that, so the two are held against each
     * other and a contract read from an older revision is refused, never used.
     *
     * @param  array<string, mixed>  $contract  contract(), read inside this transaction
     *
     * @throws RuntimeException outside a transaction, or when the revision in force is not the one the contract was read from
     */
    private function hold(string $projectId, array $contract): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('hold() holds the QC policy only inside a transaction.');
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
            throw new RuntimeException('Chính sách QC vừa có revision mới chen vào — tải lại trang rồi thao tác lại.');
        }
    }

    /**
     * @param  array<string, mixed>  $a  contract()
     * @param  array<string, mixed>  $b  contract()
     * @return bool whether two readings of the QC contract are of the same policy: the same revision row and the same policy text
     */
    private static function samePolicy(array $a, array $b): bool
    {
        return $a['contract']['phase_id'] === $b['contract']['phase_id'] && hash_equals((string) $a['contract']['policy_hash'], (string) $b['contract']['policy_hash']);
    }

    /**
     * @return ?array{stage_id: string, hash: string, report: array<string, mixed>} the newest revision of the report of the film; null when it has none
     */
    public function latest(string $projectId, string $finalId): ?array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::FILM_QC_REPORT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('output_json->final_id', $finalId)
            ->orderByDesc('planning_revision')
            ->first();

        if ($stage === null) {
            return null;
        }

        // The text stored is the report, number for number; the decoded column may have turned 0.0 into 0.
        $saved = (array) json_decode((string) $stage->raw_response, true);

        return ['stage_id' => (string) $stage->id, 'hash' => (string) $saved['hash'], 'report' => (array) $saved['report']];
    }

    /**
     * Measures the file of a cut film and stores the report as a new revision. The file is measured before any lock is taken;
     * the report is then written under the lock of the project, for the very file that was measured.
     *
     * @return ?array{stage_id: string, hash: string, report: array<string, mixed>} null for a project whose release declares no QC action
     *
     * @throws RuntimeException when the film is not a cut film with a file, or its file changed while it was measured
     */
    public function run(string $projectId, string $finalId): ?array
    {
        $contract = $this->contract($projectId);

        if ($contract === null) {
            return null;
        }

        $final = $this->film($projectId, $finalId);
        $film = (array) $final->plan_json['moto_film'];
        $plan = (array) $film['film_plan'];
        $approvals = app(MotoFilmApprovalService::class);
        $receipt = $approvals->receipt($final) ?? throw new RuntimeException('Bản này không có file hoặc không có receipt của lượt dựng — không kiểm QC được.');
        $file = $approvals->file($final);
        $measured = $file === null
            ? ['ok' => false, 'error' => 'file không còn hoặc khác hash đã ghi lúc dựng']
            : $this->measure->measure($file['path'], (int) $plan['timeline']['total_ms'], (array) $contract['policy']['technical'], (int) config('video.veo.ffmpeg_timeout'));
        $runner = app(FfmpegRunner::class);
        $ffmpeg = $runner instanceof FfmpegBinary ? $runner->version() : null;

        $measuredUnder = $contract;

        return DB::transaction(function () use ($projectId, $final, $film, $plan, $receipt, $file, $measured, $ffmpeg, $measuredUnder, $approvals): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            // The policy is read again where the report is written, and held: a report names the policy it was measured and counted under, so one measured with the thresholds of a policy that is no longer in force is not stored under the new one.
            $contract = $this->contract($projectId) ?? throw new RuntimeException('Dự án này không còn kiểm QC theo chính sách Phase 11.');
            $this->hold($projectId, $contract);

            if (! self::samePolicy($measuredUnder, $contract)) {
                throw new RuntimeException('Chính sách QC đã đổi trong lúc đo file — bấm Kiểm QC lại để đo theo chính sách hiện hành.');
            }

            $again = $approvals->receipt($final->fresh());

            if ($again === null || ! hash_equals($receipt['sha256'], $again['sha256'])) {
                throw new RuntimeException('File của bản này đã đổi trong lúc kiểm QC — kiểm lại.');
            }

            $previous = $this->latest($projectId, (string) $final->id);
            // What a person said stays with the file it was said of: a report of another file is not carried into this one.
            $same = $previous !== null && hash_equals((string) $previous['report']['final']['artifact_sha256'], $receipt['sha256']);
            $report = MotoFilmQcReport::measured($projectId, [
                'id' => (string) $final->id,
                'revision' => (int) $final->revision,
                'purpose' => is_string($film['purpose'] ?? null) ? $film['purpose'] : null,
                'artifact_sha256' => $receipt['sha256'],
                'bytes' => (int) ($file['bytes'] ?? @filesize($receipt['path']) ?: 1),
                'film_plan_hash' => (string) $film['film_plan_hash'],
                'edit_plan_hash' => is_string($film['edit_plan']['hash'] ?? null) ? $film['edit_plan']['hash'] : null,
                'file_matches' => $file !== null,
            ], $plan, is_array($film['scope'] ?? null) ? $film['scope'] : null, $contract['contract'], $contract['policy'], $measured, $ffmpeg === null ? null : Str::limit(trim(explode("\n", $ffmpeg)[0]), 190, ''), $same ? $previous['report'] : null);

            return $this->store($projectId, (string) $final->id, $report, $contract, $previous['hash'] ?? null);
        }, attempts: 3);
    }

    /**
     * @param  array<string, array{verdict: string, note: ?string}>  $verdicts  by area, what the reviewer said
     * @return array{stage_id: string, hash: string, report: array<string, mixed>} the report with the review, or the report as it stands when this operation was already recorded
     *
     * @throws RuntimeException
     */
    public function review(string $projectId, string $finalId, array $verdicts, string $operationId, ?string $reviewerId): array
    {
        $contract = $this->contract($projectId) ?? throw new RuntimeException('Dự án này không kiểm QC theo chính sách Phase 11.');
        $given = [];

        foreach ((array) $contract['policy']['content']['areas'] as $area) {
            $verdict = $verdicts[$area]['verdict'] ?? MotoFilmQcReport::NOT_CHECKED;

            if (! in_array($verdict, MotoFilmQcReport::VERDICTS, true)) {
                throw new RuntimeException('Kết luận review không hợp lệ cho mục '.$area.'.');
            }

            $note = trim((string) ($verdicts[$area]['note'] ?? ''));
            $given[] = ['area' => (string) $area, 'verdict' => $verdict, 'note' => $note === '' ? null : Str::limit($note, 2000, '')];
        }

        if (array_diff(array_keys($verdicts), (array) $contract['policy']['content']['areas']) !== []) {
            throw new RuntimeException('Review có mục không thuộc chính sách QC — tải lại trang.');
        }

        if (array_filter($given, static fn (array $entry): bool => $entry['verdict'] !== MotoFilmQcReport::NOT_CHECKED) === []) {
            throw new RuntimeException('Review chưa kết luận mục nào — chọn Đạt hoặc Không đạt cho ít nhất một mục.');
        }

        return $this->change($projectId, $finalId, $operationId, $contract, self::REVIEW_ENTITY, 'reviewed', $reviewerId, 'Review nội dung bản final.', ['verdicts' => $given],
            static fn (array $report, VideoReviewDecision $decision): array => MotoFilmQcReport::reviewed($report, $contract['policy'], $given, $reviewerId, (string) $decision->id));
    }

    /**
     * @param  array{area?: ?string, start_ms?: ?int, end_ms?: ?int, severity: string, description: string, suggestion?: ?string}  $fields  what a person found
     * @return array{stage_id: string, hash: string, report: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function raise(string $projectId, string $finalId, array $fields, string $operationId, ?string $reviewerId): array
    {
        $contract = $this->contract($projectId) ?? throw new RuntimeException('Dự án này không kiểm QC theo chính sách Phase 11.');
        $area = $fields['area'] ?? null;
        $description = trim((string) ($fields['description'] ?? ''));
        $suggestion = trim((string) ($fields['suggestion'] ?? ''));
        $start = $fields['start_ms'] ?? null;
        $end = $fields['end_ms'] ?? null;

        if (! in_array($fields['severity'] ?? null, (array) $contract['policy']['findings']['severities'], true) || $description === ''
            || ($area !== null && ! in_array($area, (array) $contract['policy']['content']['areas'], true))
            || ($start !== null && (! is_int($start) || $start < 0)) || ($end !== null && (! is_int($end) || $end < (int) ($start ?? 0)))) {
            throw new RuntimeException('Finding thiếu mô tả, sai mức độ, sai mục review hoặc sai mốc thời gian.');
        }

        $finding = ['area' => $area, 'start_ms' => $start, 'end_ms' => $end, 'severity' => (string) $fields['severity'], 'description' => Str::limit($description, 2000, ''), 'suggestion' => $suggestion === '' ? null : Str::limit($suggestion, 2000, '')];

        return $this->change($projectId, $finalId, $operationId, $contract, self::FINDING_ENTITY, 'raised', $reviewerId, $finding['description'], ['finding' => $finding],
            static fn (array $report): array => MotoFilmQcReport::raised($report, $contract['policy'], $finding, $reviewerId));
    }

    /**
     * @param  string  $status  one of fixed, rechecked, accepted, false_positive
     * @return array{stage_id: string, hash: string, report: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function settle(string $projectId, string $finalId, string $findingId, string $status, string $reason, string $operationId, ?string $reviewerId): array
    {
        $contract = $this->contract($projectId) ?? throw new RuntimeException('Dự án này không kiểm QC theo chính sách Phase 11.');
        $reason = trim($reason);

        if (! in_array($status, array_diff(MotoFilmQcReport::STATUSES, ['new']), true) || $reason === '') {
            throw new RuntimeException('Xử lý finding cần một trạng thái hợp lệ và lý do.');
        }

        return $this->change($projectId, $finalId, $operationId, $contract, self::FINDING_ENTITY, $status, $reviewerId, Str::limit($reason, 2000, ''), ['finding_id' => $findingId],
            static function (array $report, VideoReviewDecision $decision) use ($contract, $findingId, $status, $reason, $reviewerId): array {
                try {
                    return MotoFilmQcReport::settled($report, $contract['policy'], $findingId, $status, Str::limit($reason, 2000, ''), $reviewerId, (string) $decision->id);
                } catch (\InvalidArgumentException) {
                    throw new RuntimeException("Báo cáo QC không có finding {$findingId} — tải lại trang.");
                }
            });
    }

    /**
     * A person asks for the film to be changed: the film stays in the history and is no longer approved; the fix is a new cut.
     *
     * @return array{0: VideoReviewDecision, 1: string} requested, or replayed when this operation was already recorded
     *
     * @throws RuntimeException when the film is the approved one: taking an approved film back is another operation
     */
    public function requestChanges(string $projectId, string $finalId, string $reason, string $operationId, ?string $reviewerId): array
    {
        $this->contract($projectId) ?? throw new RuntimeException('Dự án này không kiểm QC theo chính sách Phase 11.');
        $reason = trim($reason);

        if (! Str::isUuid($operationId) || $reason === '') {
            throw new RuntimeException('Yêu cầu sửa cần lý do — tải lại trang nếu mã thao tác không hợp lệ.');
        }

        return DB::transaction(function () use ($projectId, $finalId, $reason, $operationId, $reviewerId): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $final = $this->film($projectId, $finalId);
            $replayed = $this->recorded($projectId, $operationId);

            if ($replayed !== null) {
                return [$replayed, 'replayed'];
            }

            if ((app(MotoFilmApprovalService::class)->selection($projectId)['final_id'] ?? null) === (string) $final->id) {
                throw new RuntimeException('Bản này đang là bản đã duyệt — ngừng bàn giao một bản đã duyệt là thao tác riêng, chưa hỗ trợ ở đây.');
            }

            return [VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => $final->session_id,
                'entity_type' => MotoFilmApprovalService::ENTITY_TYPE,
                'entity_id' => $final->id,
                'decision' => self::CHANGES_REQUESTED,
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', MotoFilmApprovalService::ENTITY_TYPE)->where('entity_id', $final->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => Str::limit($reason, 2000, ''),
                'metadata_json' => ['operation_id' => $operationId, 'final_id' => (string) $final->id, 'film_plan_hash' => (string) ($final->plan_json['moto_film']['film_plan_hash'] ?? '')],
            ]), 'requested'];
        }, attempts: 3);
    }

    /**
     * @param  bool  $hold  true inside the transaction that writes an approval: the QC policy is then held until that transaction ends and proved to be the revision in force, so the approval is given under the policy it was checked against
     * @return ?string why the film may not be approved on its QC; null when nothing of the QC holds it back, and always null for a project whose release declares no QC action
     */
    public function approvalProblem(string $projectId, VideoFinal $final, bool $hold = false): ?string
    {
        $contract = $this->contract($projectId);

        if ($contract === null) {
            return null;
        }

        if ($hold) {
            $this->hold($projectId, $contract);
        }

        $asked = $this->changesRequested($final);

        if ($asked !== null) {
            return 'Bản này đã bị yêu cầu sửa ('.Str::limit((string) $asked->reason, 160).') — dựng bản mới rồi duyệt bản đó.';
        }

        $receipt = app(MotoFilmApprovalService::class)->receipt($final);

        if ($receipt === null) {
            return 'Bản này không có file hoặc receipt — không có gì để QC.';
        }

        return MotoFilmQcReport::approvalProblem($this->latest($projectId, (string) $final->id)['report'] ?? null, $contract['contract'], $contract['policy'], $receipt['sha256'], (string) ($final->plan_json['moto_film']['film_plan_hash'] ?? ''), (array) config('video.films.qc_area_labels'));
    }

    /**
     * @param  ?VideoFinal  $final  the film the QC panel is about
     * @return ?array<string, mixed> what the screen shows of the QC of that film; null for a project whose release declares no QC action; reading it writes nothing
     */
    public function screen(string $projectId, ?VideoFinal $final): ?array
    {
        try {
            $contract = $this->contract($projectId);
        } catch (RuntimeException $e) {
            return ['problem' => $e->getMessage(), 'final' => null, 'report' => null, 'areas' => [], 'severities' => [], 'statuses' => [], 'changes_requested' => null, 'approval_problem' => null];
        }

        if ($contract === null) {
            return null;
        }

        $labels = (array) config('video.films.qc_area_labels');
        $latest = $final === null ? null : $this->latest($projectId, (string) $final->id);
        $report = $latest['report'] ?? null;
        $verdicts = array_column((array) ($report['content']['areas'] ?? []), 'verdict', 'area');
        $asked = $final === null ? null : $this->changesRequested($final);

        return [
            'problem' => null,
            'final' => $final === null ? null : ['id' => (string) $final->id, 'revision' => (int) $final->revision],
            'report' => $report,
            'report_hash' => $latest['hash'] ?? null,
            'areas' => array_map(static fn (string $area): array => [
                'id' => $area,
                'label' => (string) ($labels[$area] ?? $area),
                'required' => in_array($area, (array) $contract['policy']['content']['required'], true),
                'verdict' => $verdicts[$area] ?? MotoFilmQcReport::NOT_CHECKED,
            ], array_values((array) $contract['policy']['content']['areas'])),
            'severities' => array_values((array) $contract['policy']['findings']['severities']),
            'blocking' => array_values((array) $contract['policy']['findings']['blocking']),
            'statuses' => array_values(array_diff(MotoFilmQcReport::STATUSES, ['new'])),
            'changes_requested' => $asked === null ? null : ['reason' => (string) $asked->reason, 'at' => $asked->created_at?->format('Y-m-d H:i')],
            'approval_problem' => $final === null ? null : $this->approvalProblem($projectId, $final),
        ];
    }

    /** @return ?VideoReviewDecision the request for changes that stands for the film: the newest decision about it, when that decision asks for changes */
    private function changesRequested(VideoFinal $final): ?VideoReviewDecision
    {
        $last = VideoReviewDecision::query()->where('entity_type', MotoFilmApprovalService::ENTITY_TYPE)->where('entity_id', $final->id)->orderByDesc('revision')->first();

        return $last !== null && $last->decision === self::CHANGES_REQUESTED ? $last : null;
    }

    /**
     * One change of a report by a person: under the lock of the project, the decision is recorded and the report with the change
     * is stored as a new revision; an operation already recorded changes nothing and gives the report as it stands.
     *
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $metadata
     * @param  \Closure(array<string, mixed>, VideoReviewDecision): array<string, mixed>  $apply
     * @return array{stage_id: string, hash: string, report: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    private function change(string $projectId, string $finalId, string $operationId, array $contract, string $entity, string $decision, ?string $reviewerId, string $reason, array $metadata, \Closure $apply): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác không hợp lệ — tải lại trang.');
        }

        return DB::transaction(function () use ($projectId, $finalId, $operationId, $contract, $entity, $decision, $reviewerId, $reason, $metadata, $apply): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $final = $this->film($projectId, $finalId);
            $latest = $this->latest($projectId, (string) $final->id) ?? throw new RuntimeException('Bản này chưa có báo cáo QC — bấm Kiểm QC trước.');

            if ($this->recorded($projectId, $operationId) !== null) {
                return $latest;
            }

            // What a person says is counted under the policy in force, read again here and held. The page they answered was drawn from a policy; when that is no longer the one in force, the areas and severities they were offered may not be the ones that count.
            $now = $this->contract($projectId) ?? throw new RuntimeException('Dự án này không còn kiểm QC theo chính sách Phase 11.');
            $this->hold($projectId, $now);

            if (! self::samePolicy($contract, $now)) {
                throw new RuntimeException('Chính sách QC đã đổi so với trang đang mở — tải lại trang rồi thao tác lại.');
            }

            // And the report they add to must be one made under that policy: a report of an older policy is made again first, so its technical part and its list of areas are the ones of the policy in force.
            if (! MotoFilmQcReport::under($latest['report'], $now['contract'])) {
                throw new RuntimeException('Báo cáo QC đang có được lập theo chính sách QC cũ — bấm Kiểm QC lại trước; không cần dựng lại phim.');
            }

            // What a person says is said of the file the report is about: when the file on the disk is another one, the report is measured again first.
            $receipt = app(MotoFilmApprovalService::class)->receipt($final);

            if ($receipt === null || ! hash_equals((string) $latest['report']['final']['artifact_sha256'], $receipt['sha256'])) {
                throw new RuntimeException('Báo cáo QC đang có là của file khác file hiện tại — bấm Kiểm QC lại trước.');
            }

            $recorded = VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => $final->session_id,
                'entity_type' => $entity,
                'entity_id' => $final->id,
                'decision' => $decision,
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', $entity)->where('entity_id', $final->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => $reason,
                'metadata_json' => ['operation_id' => $operationId, 'final_id' => (string) $final->id, 'report_before' => $latest['hash']] + $metadata,
            ]);

            return $this->store($projectId, (string) $final->id, $apply($latest['report'], $recorded), $contract, $latest['hash']);
        }, attempts: 3);
    }

    /** @return ?VideoReviewDecision the decision an operation already recorded for the project */
    private function recorded(string $projectId, string $operationId): ?VideoReviewDecision
    {
        return VideoReviewDecision::query()->where('project_id', $projectId)->where('metadata_json->operation_id', $operationId)->first();
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $contract
     * @param  ?string  $previous  the hash of the revision this one follows
     * @return array{stage_id: string, hash: string, report: array<string, mixed>} the stored revision: a report follows the one before it, so the same content after another revision is stored again, and the same change sent twice is stored once
     *
     * @throws RuntimeException when the report code wrote breaks its schema, or it cannot be stored
     */
    private function store(string $projectId, string $finalId, array $report, array $contract, ?string $previous): array
    {
        $json = (string) json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $errors = PhaseOneEvidence::schemaErrors(json_decode($json), $contract['schemas']);

        if ($errors !== []) {
            throw new RuntimeException('Báo cáo QC không đạt schema '.MotoFilmQcReport::SCHEMA.': '.implode('; ', array_slice($errors, 0, 3)).'.');
        }

        $hash = MotoFilmQcReport::hash($report);
        [$stage, $token, $reason] = $this->stages->claimProjectStage($projectId, PlanningStageName::FILM_QC_REPORT,
            ['execution' => MotoFilmQcReport::VERSION, 'fingerprint' => $hash, 'previous' => $previous, 'final_id' => $finalId], false);

        if ($token !== null) {
            $saved = ['report' => $report, 'hash' => $hash, 'final_id' => $finalId, 'previous' => $previous];

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $saved)) {
                throw new RuntimeException('Không lưu được báo cáo QC.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Báo cáo QC đang được xử lý: '.$reason);
        }

        return ['stage_id' => (string) $stage->id, 'hash' => $hash, 'report' => $report];
    }

    /**
     * @return VideoFinal a cut film of the project, with its file
     *
     * @throws RuntimeException when it is not one
     */
    private function film(string $projectId, string $finalId): VideoFinal
    {
        $final = VideoFinal::query()
            ->whereKey($finalId)
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('plan_json->moto_film->format', MotoFilmRenderService::FORMAT)
            ->where('plan_json->moto_film->project_id', $projectId)
            ->first() ?? throw new RuntimeException('Không tìm thấy bản final này của dự án.');

        if ($final->status !== MotoFilmRenderService::READY) {
            throw new RuntimeException('Bản này chưa dựng xong ('.$final->status.') — chỉ kiểm QC file đã dựng.');
        }

        return $final;
    }
}
