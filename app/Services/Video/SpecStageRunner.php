<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Script\ScriptEvaluation;
use Illuminate\Support\Facades\Log;

final class SpecStageRunner
{
    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /**
     * @param  string  $step  a key of RunPlanningStageJob::STEPS
     * @param  \Closure(): array{adapter: \App\Video\Script\CheckedWorkflow, system: string, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>}  $prepare
     * @param  \Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $output  the output_json of a run from its prepared run and evaluation
     * @param  string  $label  what the run does, as the messages name it
     * @param  ?array{id: string, name: string}  $requester  who sent the run
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued|cached|replayed|ok|requeued, or why nothing ran
     */
    public function submit(string $step, string $projectId, PlanningStageName $name, \Closure $prepare, bool $regenerate, string $operationId, LlmClient $llm, \Closure $output, string $label, string $log, ?array $requester): array
    {
        return (new StageQueue($this->stageStore))->submit($step, $projectId, $name, $prepare, $regenerate, $operationId, $requester, $this->perform($llm, $output, $label, $log), $label, $log);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $prepare
     * @param  \Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $output
     * @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it
     */
    public function execute(string $stageId, string $token, \Closure $prepare, LlmClient $llm, \Closure $output, string $label, string $log): array
    {
        return (new StageQueue($this->stageStore))->execute($stageId, $token, $prepare, $this->perform($llm, $output, $label, $log), $label, $log);
    }

    /**
     * @param  \Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $output
     * @return \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string}
     */
    private function perform(LlmClient $llm, \Closure $output, string $label, string $log): \Closure
    {
        return function (VideoPlanningStage $stage, string $token, array $run, ?\Closure $rateLimited) use ($llm, $output, $label, $log): array {
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
                        $this->stageStore->recordOrphanAttempt((string) $stage->project_id, PlanningStageName::from((string) $stage->stage), $run['input'], $run['metadata'], $stageId, $token, 'claim_lost', $usage, $raw, $stored);
                    } catch (\Throwable $e) {
                        Log::warning("{$log}: recordOrphanAttempt threw", ['stage_id' => $stageId, 'exception' => $e]);
                    }

                    return [null, "Lượt {$label} bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang."];
                }

                return [VideoPlanningStage::query()->withoutSnapshot()->find($stageId), 'ok'];
            } catch (\Throwable $e) {
                $queued = $usage === [] && $rateLimited !== null ? $rateLimited($e) : null;

                if ($queued !== null) {
                    return $queued;
                }

                Log::error("{$log}: the run failed", ['stage_id' => $stageId, 'exception' => $e]);
                $billed = $e instanceof LlmUnavailable ? $e->billed : null;
                $message = StageQueue::failure($e);

                $this->stageStore->finishFailed(
                    $stageId,
                    $token,
                    $message,
                    $usage !== [] ? $usage : ($billed === null ? ['model' => $settings['model'], 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version)),
                    $raw !== '' ? $raw : ($billed?->text ?? ''),
                );

                return [null, $message];
            }
        };
    }
}
