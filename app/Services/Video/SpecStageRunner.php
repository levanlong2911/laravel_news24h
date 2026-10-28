<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Script\ScriptEvaluation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SpecStageRunner
{
    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /**
     * @param  \Closure(): array{adapter: \App\Video\Script\CheckedWorkflow, system: string, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>}  $prepare
     * @param  \Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $output  the output_json of a run from its prepared run and evaluation
     * @param  string  $label  what the run does, as the messages name it
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function run(string $projectId, PlanningStageName $name, \Closure $prepare, bool $regenerate, string $operationId, LlmClient $llm, \Closure $output, string $label, string $log): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác không hợp lệ — tải lại trang.'];
        }

        $replayed = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', $name->value)
            ->where('input_json->'.PlanningStageStore::METADATA_KEY.'->operation_id', $operationId)->orderByDesc('planning_revision')->first();

        if ($replayed !== null) {
            return self::replay($replayed);
        }

        try {
            $run = $prepare();
        } catch (\Throwable $e) {
            Log::error("{$log}: prompt, contract or approved source unavailable, no model call made", ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Chưa gọi model: '.$e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, $name, $run['input'], $regenerate, $run['metadata'] + ['operation_id' => $operationId]);

        if ($token === null) {
            return match ($reason) {
                'operation_replayed' => self::replay($stage),
                'already_succeeded' => [$stage, 'cached'],
                'claimed_by_other' => [null, "Đang có một lượt {$label} chạy cho dự án này — đợi xong rồi thử lại."],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $settings = $run['settings'];
        $version = $run['prompt_version'];
        $usage = [];
        $raw = '';
        $stageId = (string) $stage->id;

        try {
            $response = $llm->complete(new LlmRequest(
                $run['system'],
                $run['payload'],
                $version,
                $settings['model'],
                $settings['max_tokens'],
                null,
                $settings['effort'],
                $settings['timeout_seconds'],
                1,
                fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state),
            ));
            $raw = $response->text;
            $usage = ClaudeInspirationAnalyst::usageOf($response, $version);
            $evaluation = ScriptEvaluation::of($raw, $run['metadata']['manifest'], $run['metadata']['context'], $run['metadata']['source'], $run['adapter']);

            if ($evaluation['document'] === null) {
                $this->stageStore->finishFailed($stageId, $token, 'Phản hồi không đọc được JSON: '.$evaluation['parse_error'], $usage, $raw, $output($run, $evaluation));

                return [null, 'Phản hồi không đọc được JSON — phản hồi và usage đã được lưu.'];
            }

            $stored = $output($run, $evaluation);

            if (! $this->stageStore->finishSucceeded($stageId, $token, $raw, $stored, $usage)) {
                try {
                    $this->stageStore->recordOrphanAttempt($projectId, $name, $run['input'], $run['metadata'], $stageId, $token, 'claim_lost', $usage, $raw, $stored);
                } catch (\Throwable $e) {
                    Log::warning("{$log}: recordOrphanAttempt threw", ['stage_id' => $stageId, 'exception' => $e]);
                }

                return [null, "Lượt {$label} bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang."];
            }

            return [VideoPlanningStage::query()->find($stageId), 'ok'];
        } catch (\Throwable $e) {
            Log::error("{$log}: the run failed", ['stage_id' => $stageId, 'exception' => $e]);
            $billed = $e instanceof LlmUnavailable ? $e->billed : null;

            $this->stageStore->finishFailed(
                $stageId,
                $token,
                $e->getMessage(),
                $usage !== [] ? $usage : ($billed === null ? ['model' => $settings['model'], 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version)),
                $raw !== '' ? $raw : ($billed?->text ?? ''),
            );

            return [null, $e->getMessage()];
        }
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what a request carrying an operation id that already ran gets back */
    public static function replay(VideoPlanningStage $stage): array
    {
        return match ($stage->status) {
            VideoPlanningStageStatus::SUCCEEDED->value => [$stage, 'replayed'],
            VideoPlanningStageStatus::RUNNING->value => [null, 'Thao tác này đang chạy — đợi xong rồi tải lại trang.'],
            default => [null, 'Thao tác này đã chạy và lỗi: '.$stage->error_message.' — tải lại trang để thử lại.'],
        };
    }
}
