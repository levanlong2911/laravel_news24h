<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Video\Moto\MotoCheckRules;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoDesign;
use App\Video\Moto\MotoDesignCheck;
use App\Video\Moto\MotoDesignPatch;
use App\Video\Moto\MotoDesignRefs;
use App\Video\Moto\MotoSheetPrompt;
use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\YachtDesign\DesignRunPolicy;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosis and hand revision of a stored themed moto design, with no model call.
 *
 * The diagnosis reads one design run as it was stored, against the contract that run pinned. A
 * revision keeps that contract: the revised answer is checked by the same check a run of the model
 * gets, and is stored only when it passes, as a new run that names the run it revises. The run it
 * revises keeps its answer, cost and check. A design approved with its model sheet is never revised,
 * and a revision is only written from the newest design of the project, under the project lock.
 */
final class MotoDesignRevisionService
{
    public const VERSION = 'moto-design-revise-v1';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly DesignApprovalLock $approval,
        private readonly DesignRevisionRuns $runs,
    ) {}

    /**
     * @return array{reason: ?string, stage: ?array<string, mixed>, schema: list<array<string, mixed>>, schema_lines: list<string>, refs: list<array<string, mixed>>, texts: list<array{path: string, ids: list<string>, text: string}>, text_error: ?string, check: ?array<string, mixed>, proposed: list<array<string, mixed>>, decisions: list<array<string, mixed>>} the stored run, every schema violation with the value it holds, every ref problem, every text the model-sheet prompt takes that holds an id, the stored check and the changes they lead to; reason says why the run cannot be read, null when it was
     */
    public function diagnose(string $projectId, string $stageId): array
    {
        $report = ['reason' => null, 'stage' => null, 'schema' => [], 'schema_lines' => [], 'refs' => [], 'texts' => [], 'text_error' => null, 'check' => null, 'proposed' => [], 'decisions' => []];
        [$stage, $problem] = $this->source($projectId, $stageId);

        if ($stage === null) {
            return ['reason' => $problem] + $report;
        }

        $manifest = self::manifestOf($stage);
        [$answer, $parseError] = JsonAnswer::parse((string) $stage->raw_response);
        $report['stage'] = $this->describe($stage, $manifest);
        $report['check'] = is_array($stage->output_json['check'] ?? null) ? $stage->output_json['check'] : null;

        if ($parseError !== null || ! is_object($answer)) {
            return ['reason' => 'Phản hồi đã lưu không đọc được JSON: '.($parseError ?? 'không phải đối tượng').'.'] + $report;
        }

        $data = self::dataOf($answer);
        $themed = MotoDesign::themed($manifest);

        try {
            $report['schema'] = PhaseOneEvidence::schemaDiagnostics($answer, MotoDesign::schemas($manifest));
            $report['schema_lines'] = MotoDesignRefs::schemaIssues($report['schema'], $data, $themed);
        } catch (\Throwable $e) {
            $report['schema_lines'] = ['không kiểm được schema: '.$e->getMessage()];
        }

        if (! $themed) {
            return ['reason' => 'Lượt này theo hợp đồng '.MotoContract::DESIGN_FORMAT.'; chẩn đoán refs và bản vá chỉ áp cho '.MotoContract::DESIGN_FORMAT_V2.'.'] + $report;
        }

        $report['refs'] = MotoDesignRefs::storyRefProblems($data);
        [$paths, $report['text_error']] = self::textPaths($data);

        foreach ($paths as $path) {
            $text = self::read($data, $path);

            if (is_string($text)) {
                $report['texts'][] = ['path' => $path, 'ids' => MotoDesignCheck::promptIds($text), 'text' => $text];
            }
        }

        return MotoDesignPatch::proposals($data, $paths) + $report;
    }

    /**
     * @param  array<string, mixed>  $patch  reason and changes (MotoDesignPatch)
     * @param  ?string  $expected  the patch hash of the preview the reviewer approved; required to write
     * @return array{passed: bool, reason: string, source: ?array<string, mixed>, changes: list<array<string, string>>, check: ?array<string, mixed>, patch_hash: ?string, revision_stage_id: ?string, revision: ?int} the revised design checked like a run of the model under the contract its run pinned; previewed, or with $write and the approved patch hash stored as a new run of zero cost that names the run it revises
     */
    public function revise(string $projectId, string $stageId, array $patch, bool $write, ?string $expected, ?string $actor): array
    {
        $report = ['passed' => false, 'reason' => '', 'source' => null, 'changes' => [], 'check' => null, 'patch_hash' => null, 'revision_stage_id' => null, 'revision' => null];
        [$stage, $problem] = $this->source($projectId, $stageId);

        if ($stage === null) {
            return ['reason' => (string) $problem] + $report;
        }

        $manifest = self::manifestOf($stage);
        $report['source'] = $this->describe($stage, $manifest);

        if (! MotoDesign::themed($manifest)) {
            return ['reason' => 'Sửa tay chỉ áp dụng cho thiết kế '.MotoContract::DESIGN_FORMAT_V2.'.'] + $report;
        }

        $locked = $this->lockProblem($projectId, $stage);

        if ($locked !== null) {
            return ['reason' => $locked] + $report;
        }

        // A preview of a design the project no longer shows is refused here; a write is decided under the project lock, after a replay of the same patch is served.
        $older = $write ? null : $this->newestProblem($projectId, $stage);

        if ($older !== null) {
            return ['reason' => $older] + $report;
        }

        $workflow = (string) ($stage->input_json['workflow'] ?? '');
        $integrity = $this->approval->integrityProblem($projectId, $stage, $workflow);

        if ($integrity !== null) {
            return ['reason' => $integrity] + $report;
        }

        try {
            $run = $this->runs->runFor($projectId);
        } catch (\Throwable $e) {
            return ['reason' => 'Chưa dựng được lượt thiết kế từ nguồn hiện hành: '.$e->getMessage()] + $report;
        }

        if (! $this->runs->sameInput($stage, (array) $run['input'])) {
            return ['reason' => "Bản rev {$stage->planning_revision} viết từ DNA, prompt hoặc hợp đồng khác nguồn dự án đang dùng — không vá sang nguồn khác; chạy lại thiết kế để có bản mới."] + $report;
        }

        [$answer, $parseError] = JsonAnswer::parse((string) $stage->raw_response);

        if ($parseError !== null || ! is_object($answer)) {
            return ['reason' => 'Phản hồi đã lưu không đọc được JSON.'] + $report;
        }

        [$paths] = self::textPaths(self::dataOf($answer));
        $applied = MotoDesignPatch::apply($answer, $patch, $paths);

        if ($applied['problem'] !== null || $applied['answer'] === null) {
            return ['reason' => (string) $applied['problem']] + $report;
        }

        $report['changes'] = $applied['changes'];
        $raw = MotoDesignPatch::encode($applied['answer']);
        [, $check] = $this->runs->evaluateAnswer($raw, $run, $manifest);
        $report['check'] = $check;
        $report['patch_hash'] = CanonicalJson::hash([
            'of' => ['stage_id' => (string) $stage->id, 'raw_sha256' => hash('sha256', (string) $stage->raw_response)],
            'patch' => $patch,
            'content_sha256' => hash('sha256', $raw),
            'tools' => [self::VERSION, MotoDesignPatch::VERSION, MotoDesignRefs::VERSION, (string) ($check['version'] ?? '')],
        ]);
        $report['passed'] = MotoCheckRules::usable((array) $check) && ($check['status'] ?? null) === 'ok';

        if (! $report['passed']) {
            return ['reason' => 'Bản sửa chưa qua kiểm của hợp đồng mà lượt gốc đã ghim — chưa tạo revision.'] + $report;
        }

        if (! $write) {
            return ['reason' => 'preview'] + $report;
        }

        if ($expected === null || ! hash_equals($report['patch_hash'], $expected)) {
            return ['reason' => 'Mã xem trước không khớp bản sửa này — xem trước lại rồi duyệt đúng mã.'] + $report;
        }

        $input = DesignRunPolicy::sourceInput((array) $stage->input_json) + ['revision_of' => (string) $stage->id, 'patch_hash' => $report['patch_hash']];
        $existing = $this->stored($projectId, $input);

        if ($existing !== null) {
            return ['reason' => 'already_applied', 'revision_stage_id' => (string) $existing->id, 'revision' => (int) $existing->planning_revision] + $report;
        }

        $metadata = ['manifest' => $manifest, 'revision' => [
            'of' => $report['source'],
            'reason' => trim((string) $patch['reason']),
            'patch' => $patch,
            'patch_hash' => $report['patch_hash'],
            'changes' => $report['changes'],
            'actor' => $actor,
            'revised_at' => now()->toIso8601String(),
            'by' => 'code',
            'tools' => ['revise' => self::VERSION, 'patch' => MotoDesignPatch::VERSION, 'refs' => MotoDesignRefs::VERSION, 'check' => (string) ($check['version'] ?? '')],
        ]];
        $refusal = null;
        // A new row every time: a design run whose lease ran out keeps its own answer and cost.
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::YACHT_DESIGN, $input, false, $metadata, function () use ($projectId, $stage, &$refusal): bool {
            $refusal = $this->lockProblem($projectId, $stage) ?? $this->newestProblem($projectId, $stage);

            return $refusal !== null;
        }, reuseExpired: false);

        if ($token === null) {
            $existing = $this->stored($projectId, $input);

            return match (true) {
                $existing !== null => ['reason' => 'already_applied', 'revision_stage_id' => (string) $existing->id, 'revision' => (int) $existing->planning_revision] + $report,
                $claimReason === 'refused' => ['reason' => (string) $refusal] + $report,
                $claimReason === 'claimed_by_other' => ['reason' => 'Đang có một lượt thiết kế chờ hoặc chạy cho dự án này — đợi xong rồi thử lại.'] + $report,
                default => ['reason' => $claimReason] + $report,
            };
        }

        try {
            $output = $this->runs->outputFor($run, $raw, $stage);
            // The claim only reserved the row: the revision becomes the design here, under the project lock, from the source as it is now.
            $previewInput = $this->stageStore->inputHash((array) $run['input']);
            $problem = DB::transaction(function () use ($projectId, $stage, $claimed, $token, $raw, $output, $report, $previewInput): ?string {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $now = VideoPlanningStage::query()->whereKey($stage->id)->where('project_id', $projectId)->first();

                if ($now === null || $now->status !== VideoPlanningStageStatus::SUCCEEDED->value || ! hash_equals((string) $report['source']['raw_sha256'], hash('sha256', (string) $now->raw_response))) {
                    return 'Bản thiết kế nguồn đã đổi sau bản xem trước — không lưu bản sửa; xem trước lại.';
                }

                $problem = $this->lockProblem($projectId, $now) ?? $this->newestProblem($projectId, $now) ?? $this->inputProblem($projectId, $now, $previewInput);

                if ($problem !== null) {
                    return $problem;
                }

                return $this->stageStore->finishSucceeded((string) $claimed->id, (string) $token, $raw, $output, [
                    'instruction_version' => $stage->instruction_version,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'cost_usd' => 0,
                ]) ? null : 'Lượt lưu bị giành mất — tải lại rồi thử lại.';
            });
        } catch (\Throwable $e) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Bản sửa thiết kế không lưu được: '.$e->getMessage(), (string) $token);

            throw $e;
        }

        if ($problem !== null) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Bản sửa thiết kế không được công bố: '.$problem, (string) $token);

            return ['reason' => $problem] + $report;
        }

        return ['reason' => 'applied', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report;
    }

    /** @return array{0: ?VideoPlanningStage, 1: ?string} the succeeded design run of the project with this id and the moto workflow, or why there is none */
    private function source(string $projectId, string $stageId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId);

        if ($project === null) {
            return [null, 'Không tìm thấy dự án.'];
        }

        $workflow = (string) (config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? '');

        if (! MotoContract::is($workflow)) {
            return [null, 'Dự án không thuộc workflow moto.'];
        }

        $stage = VideoPlanningStage::query()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        return $stage === null ? [null, 'Lượt thiết kế này không thuộc dự án hoặc chưa chạy xong.'] : [$stage, null];
    }

    /** @return ?string why a revision of this design may not be written: it or the project's current design was approved with its model sheet */
    private function lockProblem(string $projectId, VideoPlanningStage $stage): ?string
    {
        return $this->approval->holds($projectId) || $this->approval->approves($projectId, $stage)
            ? 'Bản thiết kế đã duyệt cùng model sheet — không sửa tay, không đổi nguồn của các bước sau.'
            : null;
    }

    /** @return ?string why the revision would no longer be written from the design the project shows: a newer design was stored since */
    private function newestProblem(string $projectId, VideoPlanningStage $stage): ?string
    {
        $current = $this->approval->currentDesign($projectId);

        return $current !== null && (string) $current->id !== (string) $stage->id
            ? "Dự án đã có bản thiết kế mới hơn (rev {$current->planning_revision}) — chỉ sửa tay bản mới nhất."
            : null;
    }

    /**
     * @param  string  $previewInput  the input hash of the run the preview was checked with
     * @return ?string why the revision would no longer be written from the sources of the project: the run it would make now — its DNA, Phase 1, release, prompt and contract — is not the one the preview was checked with, or not the input of the design it revises; a revision never moves to another source
     */
    private function inputProblem(string $projectId, VideoPlanningStage $source, string $previewInput): ?string
    {
        try {
            $run = $this->runs->runFor($projectId);
        } catch (\Throwable $e) {
            return 'Không dựng lại được nguồn hiện hành của dự án ('.$e->getMessage().') — không lưu bản sửa.';
        }

        return hash_equals($previewInput, $this->stageStore->inputHash((array) $run['input'])) && $this->runs->sameInput($source, (array) $run['input'])
            ? null
            : 'Nguồn của dự án (DNA, Phase 1, bản phát hành hoặc prompt) đã đổi sau bản xem trước — không chuyển bản sửa sang nguồn khác; giữ bản thiết kế cũ.';
    }

    /** @param  array<string, mixed>  $input */
    private function stored(string $projectId, array $input): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_hash', $this->stageStore->inputHash($input))->first();
    }

    /**
     * @param  ?array<string, mixed>  $manifest
     * @return array<string, mixed> what names the run: its row, the prompt and schema it pinned, the hash of its answer and the run it revises when it is a revision
     */
    private function describe(VideoPlanningStage $stage, ?array $manifest): array
    {
        $schemas = [];

        try {
            $schemas = MotoDesign::schemas($manifest);
        } catch (\Throwable) {
        }

        $revision = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision'] ?? null;

        return [
            'stage_id' => (string) $stage->id,
            'revision' => (int) $stage->planning_revision,
            'created_at' => $stage->created_at?->toIso8601String(),
            'prompt' => 'phase-3 rev '.($stage->input_json['phase_revision'] ?? '?'),
            'instruction_version' => $stage->instruction_version,
            'schema' => $schemas[0]['label'] ?? null,
            'schema_sha256' => $schemas[0]['sha256'] ?? null,
            'raw_sha256' => hash('sha256', (string) $stage->raw_response),
            'check_version' => is_array($stage->output_json['check'] ?? null) ? ($stage->output_json['check']['version'] ?? null) : null,
            'revision_of' => is_array($revision) ? ($revision['of']['stage_id'] ?? null) : null,
        ];
    }

    /** @return ?array<string, mixed> */
    private static function manifestOf(VideoPlanningStage $stage): ?array
    {
        $manifest = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['manifest'] ?? null;

        return is_array($manifest) ? $manifest : null;
    }

    /** @return array<string, mixed> */
    private static function dataOf(object $answer): array
    {
        $decoded = json_decode((string) json_encode($answer), true);

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<string>, 1: ?string} the texts set_text may change, and why they could not be read
     */
    private static function textPaths(array $data): array
    {
        try {
            return [MotoDesignPatch::textPaths(MotoSheetPrompt::composedSources($data)), null];
        } catch (\Throwable $e) {
            return [[], 'Chưa ghép được prompt model sheet từ bản này ('.$e->getMessage().') — sửa lỗi schema trước rồi chẩn đoán lại phần text.'];
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function read(array $data, string $path): mixed
    {
        $node = $data;

        foreach (explode('.', $path) as $segment) {
            $node = is_array($node) ? ($node[ctype_digit($segment) ? (int) $segment : $segment] ?? null) : null;
        }

        return $node;
    }
}
