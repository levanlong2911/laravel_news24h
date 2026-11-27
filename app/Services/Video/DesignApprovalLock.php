<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Video\Moto\MotoCheckRules;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoDesignCheck;
use App\Video\Llm\JsonAnswer;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\YachtDesign\DesignRunPolicy;
use App\Video\YachtDesign\YachtDesignCheck;

/**
 * Whether the design of a project was approved with its model sheet, read from the rows alone.
 *
 * It reads no file: a master lost from disk is a problem of that resource and never reopens the
 * design. It calls neither the design panel nor the model sheet context, since those ask it in turn.
 */
final class DesignApprovalLock
{
    public const MESSAGE = 'Bản thiết kế đã duyệt cùng model sheet — không tạo Design hoặc DNA mới.';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly PhaseResolver $resolver,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly VideoDesignImageRepositoryInterface $images,
        private readonly VideoArtifactRepositoryInterface $artifacts,
        private readonly AiPhaseRepositoryInterface $phases,
    ) {}

    /** @return ?VideoPlanningStage the newest succeeded design, the one the panel shows and the model sheet works from */
    public function currentDesign(string $projectId): ?VideoPlanningStage
    {
        return $this->stages->findCurrentDesign($projectId);
    }

    public function holds(string $projectId): bool
    {
        $design = $this->currentDesign($projectId);

        return $design !== null && $this->approves($projectId, $design);
    }

    /** @return bool an approved master of this design, its set, its selected artifact, the canonical hash they pinned and the review that approved them all agree; the sets, the artifacts and the reviews of every master are each read in one query, however many masters there are */
    public function approves(string $projectId, VideoPlanningStage $design): bool
    {
        $canonicalHash = ModelSheetService::designHash($design, (string) ($design->input_json['workflow'] ?? ''));

        if (! is_string($canonicalHash) || $canonicalHash === '') {
            return false;
        }

        $images = $this->images->findApprovedSelectedModelSheetMasters($projectId, (string) $design->id);

        if ($images->isEmpty()) {
            return false;
        }

        $setIds = $images->map(static fn (VideoDesignImage $image): mixed => ((array) $image->prompt_spec_json)['model_sheet_set_id'] ?? null)->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')->unique()->values();
        $sets = $this->stages->findSucceededStagesOfKind($projectId, PlanningStageName::MODEL_SHEET, $setIds)->keyBy('id');
        $artifacts = $this->artifacts->findOwnedArtifactsByIds($projectId, $images->pluck('selected_artifact_id')->unique()->values())->keyBy('id');
        $reviews = [];

        foreach ($images as $image) {
            $spec = (array) $image->prompt_spec_json;
            $set = $sets->get($spec['model_sheet_set_id'] ?? '');
            $artifact = $artifacts->get($image->selected_artifact_id);

            if ($set === null || $artifact === null || ($set->input_json['design_stage_id'] ?? null) !== (string) $design->id
                || ($spec['design_canonical_hash'] ?? null) !== $canonicalHash) {
                continue;
            }

            $reviews[] = $this->stageStore->inputHash(ModelSheetService::reviewInput($set, $image, $artifact));
        }

        return $reviews !== [] && $this->stages->succeededStageExistsForInputHashes($projectId, PlanningStageName::MODEL_SHEET_REVIEW, array_values(array_unique($reviews)));
    }

    /** @return ?string why a stored design cannot be trusted: its own records, and the DNA it says it was written from */
    public function integrityProblem(string $projectId, VideoPlanningStage $design, string $workflow): ?string
    {
        $problem = DesignRunPolicy::integrityProblem($design, $workflow);

        if ($problem !== null) {
            return $problem;
        }

        $dna = $design->input_json['dna_stage_id'] ?? null;

        return is_string($dna) && ! $this->stages->succeededStageExists($projectId, $dna, PlanningStageName::DESIGN_DNA)
                ? 'Không tìm thấy lượt DNA nguồn của bản thiết kế này.'
                : null;
    }

    /**
     * @return ?string why this design can no longer be the source of an approval, read from the rows
     *                 as they are now: call it under the project lock, never from a panel kept earlier
     */
    public function sourceProblem(string $projectId, VideoPlanningStage $design, string $workflow): ?string
    {
        $problem = $this->integrityProblem($projectId, $design, $workflow);

        if ($problem !== null) {
            return $problem;
        }

        if (! MotoCheckRules::usable($this->checkOf($design))) {
            return 'Bản thiết kế nguồn chưa đạt kiểm tự động.';
        }

        $pinned = $design->input_json['dna_stage_id'] ?? null;
        $newest = VideoPlanningStage::query()
            ->passedDna()
            ->where('project_id', $projectId)
            ->orderByDesc('planning_revision')
            ->value('id');

        return is_string($pinned) && $newest !== null && (string) $newest !== $pinned
            ? 'Design DNA đã đổi so với bản thiết kế này.'
            : null;
    }

    /** @var array<string, array<string, mixed>> the checks already made again in this request, by design, its raw answer and the sources it pinned */
    private array $checks = [];

    /**
     * @return array<string, mixed> the check a design is read by now, the same for the design screen, the model sheet and the approval. A moto design is checked again by the current technical check from its raw answer and the sources its run pinned: its prompt revision, its DNA run and its contract; no model is called and the row is not written, so the check stored with the run stays as its history. A moto design that cannot be checked again — no manifest, no raw answer, a pinned prompt revision or DNA that can no longer be read, or a check that throws — is UNSCORED with the reason and carries what the run stored under STORED_CHECK_KEY: a verdict of an earlier check never stands in for the current one. A design of any other workflow is read by the check stored with it, as before
     */
    public function checkOf(VideoPlanningStage $design): array
    {
        $stored = is_array($design->output_json['check'] ?? null) ? $design->output_json['check'] : [];
        $input = is_array($design->input_json) ? $design->input_json : [];
        $manifest = PlanningStageStore::metadataOf($input)['manifest'] ?? null;
        $workflow = is_string($input['workflow'] ?? null) ? $input['workflow'] : (is_array($manifest) ? (string) ($manifest['workflow'] ?? '') : '');

        if (! MotoContract::is($workflow)) {
            return $stored;
        }

        $unverified = static fn (string $why): array => [
            'version' => MotoDesignCheck::VERSION,
            'verdict' => MotoDesignCheck::UNSCORED,
            'status' => null,
            'issues' => [],
            'warnings' => [],
            'error' => 'Chưa xác minh được bản thiết kế này bằng bộ kiểm kỹ thuật hiện tại: '.$why.' — không dùng để render hay duyệt mới.',
            self::STORED_CHECK_KEY => array_intersect_key($stored, array_flip(['verdict', 'version', 'issues', 'warnings', 'error'])),
        ] + $stored;
        $raw = $design->output_json['raw_text'] ?? null;

        if (! is_array($manifest)) {
            return $unverified('lượt chạy không lưu manifest hợp đồng');
        }

        if (! is_string($raw)) {
            return $unverified('lượt chạy không lưu câu trả lời gốc');
        }

        $key = $design->id.'|'.hash('sha256', $raw.'|'.json_encode([$input['phase_id'] ?? null, $input['dna_stage_id'] ?? null]));

        if (isset($this->checks[$key])) {
            return $this->checks[$key];
        }

        $prompt = $this->phases->findPhase($input['phase_id'] ?? null);

        if ($prompt === null) {
            return $unverified('không còn revision prompt Phase 3 mà lượt chạy đã ghim');
        }

        $dnaRun = $this->stages->findStageWithSnapshot($input['dna_stage_id'] ?? null);
        $dna = JsonAnswer::object((string) ($dnaRun?->output_json['raw_text'] ?? ''))['dna'] ?? null;

        if (! is_array($dna)) {
            return $unverified('không đọc được Design DNA mà lượt chạy đã ghim');
        }

        try {
            $adapter = YachtDesignService::adapter($workflow);
            $modelSheet = YachtDesignService::modelSheet($adapter->modelSheetConfig($manifest));

            if (($input['assembly_protocol'] ?? null) !== null) {
                $modelSheet = $this->pinnedModelSheet($design);

                if ($modelSheet === null) {
                    return $unverified('không đọc được bản chụp đầu vào của lượt chạy, nơi ghim camera của model sheet');
                }

                $assembly = $adapter->assembly((string) $prompt->prompt_content, [
                    'project_id' => (string) $design->project_id,
                    'prompt_version' => YachtDesignService::instructionVersion($prompt),
                    'dna' => $dna,
                    'model_sheet' => $modelSheet,
                    'schemas' => $this->resolver->fromManifest($manifest)->outputSchemas(),
                ]);

                if ($assembly === null || ! hash_equals((string) ($input['assembly_dna_sha256'] ?? ''), $assembly['dna_sha256'])) {
                    return $unverified('Design DNA nguồn không còn khớp mã băm mà lượt đã lưu cho toàn bộ khối dna — DNA đã đổi sau khi thiết kế được ghép');
                }

                if (! hash_equals((string) ($input['assembly_sources_sha256'] ?? ''), $assembly['sources_sha256'])) {
                    return $unverified('nguồn đã ghim của lượt chạy (camera model sheet, schema, giá trị hệ thống điền) không còn khớp mã băm mà lượt đã lưu');
                }
            }

            $check = $adapter->evaluate($raw, [
                'project_id' => (string) $design->project_id,
                'prompt_version' => YachtDesignService::instructionVersion($prompt),
                'dna' => $dna,
                'assembly' => $input['assembly_protocol'] ?? null,
                'source_identity' => [],
                'model_sheet' => $modelSheet,
                'forbidden' => [],
            ], $manifest)[1];
        } catch (\Throwable $e) {
            return $unverified('kiểm lại báo lỗi '.mb_substr($e->getMessage(), 0, 200));
        }

        return $this->checks[$key] = $check;
    }

    /**
     * @param  VideoPlanningStage  $design  a design joined under an assembly protocol, or a hand revision of one
     * @return ?array<string, mixed> the model-sheet presentation the run was sent with, read from the request it stored — for a hand revision, from the run it was revised from: the cameras the record was joined with, whatever the configuration says now; null when no stored request can be read
     */
    private function pinnedModelSheet(VideoPlanningStage $design): ?array
    {
        $stage = $design;

        for ($step = 0; $step < 12 && $stage !== null; $step++) {
            try {
                $payload = $this->stageStore->payloadOf($stage);
            } catch (\RuntimeException) {
                return null;
            }

            if ($payload !== null) {
                $sheet = json_decode($payload, true)['model_sheet'] ?? null;

                return is_array($sheet) ? $sheet : null;
            }

            $source = $stage->input_json['revision_of'] ?? null;
            $stage = is_string($source) ? $this->stages->findStageForProject((string) $design->project_id, $source) : null;
        }

        return null;
    }

    /** @var string the key under which a check that could not be made again carries what the run stored, as history only */
    public const STORED_CHECK_KEY = 'stored_check';

    /** @return ?VideoPlanningStage a Design or DNA run still waiting or holding its claim, stuck ones too: each can still change the source until someone settles it */
    public function activeRun(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $projectId)
            ->whereIn('stage', [PlanningStageName::YACHT_DESIGN->value, PlanningStageName::DESIGN_DNA->value])
            ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
            ->whereNotNull('claim_token')
            ->orderByDesc('planning_revision')
            ->first();
    }
}
