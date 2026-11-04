<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Jobs\Video\RunPlanningStageJob;
use App\Models\VideoPlanningStage;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Queue\ProviderRateLimit;
use App\Video\Queue\VideoQueues;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class StageQueue
{
    public const SNAPSHOT_FORMAT = 'planning-request-v1';

    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /**
     * @param  string  $step  a key of RunPlanningStageJob::STEPS, the service a worker runs this stage with
     * @param  \Closure(): array{system: string, payload: string, prompt_version: string, settings: array{model: string, timeout_seconds: int, attempts?: int}, input: array<string, mixed>, metadata: array<string, mixed>}  $prepare  attempts is how many calls the client makes within one run, so the job lasts for all of them
     * @param  bool|\Closure(VideoPlanningStage, array<string, mixed>): bool  $force  whether a succeeded run of the same input is replaced, decided from it and the prepared run
     * @param  ?array{id: string, name: string}  $requester  who sent the run, kept on it for the worker and the page
     * @param  \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string}  $perform  calls the model for a claimed run and stores what it answered; the last argument queues a rate-limited run again
     * @param  ?\Closure(?VideoPlanningStage, string, array<string, mixed>): ?array{0: ?VideoPlanningStage, 1: string}  $refusal  the answer to a refused claim, null keeps the default one
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued|cached|replayed|ok|requeued, or why nothing ran
     */
    public function submit(string $step, string $projectId, PlanningStageName $name, \Closure $prepare, bool|\Closure $force, string $operationId, ?array $requester, \Closure $perform, string $label, string $log, ?\Closure $refusal = null, string $notCalled = 'Chưa gọi model: '): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác không hợp lệ — tải lại trang.'];
        }

        $replayed = $this->replayed($projectId, $name, $operationId);

        if ($replayed !== null) {
            return self::replay($replayed);
        }

        try {
            $run = $prepare();
        } catch (\Throwable $e) {
            Log::error("{$log}: prompt, contract or approved source unavailable, no model call made", ['project_id' => $projectId, 'exception' => $e]);

            return [null, $notCalled.$e->getMessage()];
        }

        $queued = VideoQueues::enabled();
        $jobTimeout = VideoQueues::jobTimeout((int) $run['settings']['timeout_seconds'] * max(1, (int) ($run['settings']['attempts'] ?? 1)));
        $problem = $queued ? VideoQueues::problem($jobTimeout) : null;

        if ($problem !== null) {
            return [null, 'Chưa đưa vào hàng đợi, không gọi model: '.$problem];
        }

        [$stage, $token, $reason] = $this->stageStore->queueProjectStage(
            $projectId,
            $name,
            $run['input'],
            $force instanceof \Closure ? static fn (VideoPlanningStage $served): bool => $force($served, $run) : $force,
            array_diff_key($run['metadata'], ['payload' => true]) + ['step' => $step, 'operation_id' => $operationId, 'requested_by' => $requester, 'queued_at' => now()->toIso8601String(), 'job_timeout' => $jobTimeout],
            self::snapshot($run),
            $queued ? static fn (VideoPlanningStage $stage, string $token) => self::push($stage, $token, ProviderRateLimit::cooldownRemaining(ProviderRateLimit::ANTHROPIC, (string) $run['settings']['model'])) : null,
        );

        if ($token === null) {
            return ($refusal === null ? null : $refusal($stage, $reason, $run)) ?? $this->refused($stage, $reason, $label, $queued);
        }

        if ($queued) {
            return [$stage, 'queued'];
        }

        return $this->execute((string) $stage->id, $token, $prepare, $perform, $label, $log, $run, false, $notCalled);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $prepare
     * @param  \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string}  $perform
     * @param  ?array<string, mixed>  $prepared  the run this same request prepared, so the sources are read once
     * @param  bool  $queued  false in the web request, where a rate limit fails the run instead of queueing it again
     * @return array{0: ?VideoPlanningStage, 1: string} reason ok|requeued, or why the run stopped
     */
    public function execute(string $stageId, string $token, \Closure $prepare, \Closure $perform, string $label, string $log, ?array $prepared = null, bool $queued = true, string $notCalled = 'Chưa gọi model: '): array
    {
        if (! $this->stageStore->acceptQueued($stageId, $token)) {
            return [null, "Lượt {$label} này đã bị huỷ, đã được nhận hoặc không còn — bỏ qua, không gọi model."];
        }

        $stage = VideoPlanningStage::query()->withoutSnapshot()->findOrFail($stageId);

        if ($prepared === null) {
            try {
                $prepared = $prepare();
            } catch (\Throwable $e) {
                Log::error("{$log}: prompt, contract or approved source unavailable when the queued run started, no model call made", ['stage_id' => $stageId, 'exception' => $e]);
                $this->stageStore->finishFailed($stageId, $token, $notCalled.$e->getMessage());

                return [null, $notCalled.$e->getMessage()];
            }

            if (! hash_equals((string) $stage->input_hash, $this->stageStore->inputHash($prepared['input']))) {
                $message = 'Nguồn, prompt hoặc thiết lập đã đổi sau khi xếp hàng — không gọi model; bấm tạo lại để chạy theo nguồn mới.';
                $this->stageStore->finishFailed($stageId, $token, $message);

                return [null, $message];
            }
        }

        try {
            $run = $this->fromSnapshot($stage, $prepared);
        } catch (RuntimeException $e) {
            $this->stageStore->finishFailed($stageId, $token, $e->getMessage());

            return [null, $e->getMessage()];
        }

        if ($queued) {
            $cooldown = ProviderRateLimit::cooldownRemaining(ProviderRateLimit::ANTHROPIC, (string) $run['settings']['model']);

            if ($cooldown > 0) {
                return $this->wait($stage, $token, $run, null, $cooldown, $label, 'cooldown');
            }
        }

        return $perform($stage, $token, $run, $queued ? fn (\Throwable $e): ?array => $this->rateLimited($stage, $token, $run, $e, $label, $log) : null);
    }

    /**
     * @param  \Closure(array<string, mixed>, string): array<string, mixed>  $output  the output_json of a received answer; a throw fails the run with the answer kept
     * @return \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string} a perform that calls the model once and stores its checked answer
     */
    public function singleCall(LlmClient $llm, PlanningStageName $name, \Closure $output, string $label, string $log): \Closure
    {
        return function (VideoPlanningStage $stage, string $token, array $run, ?\Closure $rateLimited) use ($llm, $name, $output, $label, $log): array {
            $settings = $run['settings'];
            $version = (string) $run['prompt_version'];
            $stageId = (string) $stage->id;
            $usage = [];

            try {
                $response = $llm->complete(new LlmRequest(
                    $run['system'],
                    $run['payload'],
                    $version,
                    $settings['model'],
                    (int) $settings['max_tokens'],
                    null,
                    $settings['effort'],
                    (int) $settings['timeout_seconds'],
                    1,
                    fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state),
                ));
                $usage = ClaudeInspirationAnalyst::usageOf($response, $version);

                try {
                    $stored = $output($run, $response->text);
                } catch (\Throwable $e) {
                    Log::error("{$log}: the check threw on a received answer", ['stage_id' => $stageId, 'exception' => $e]);
                    $this->stageStore->finishFailed($stageId, $token, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận: '.$e->getMessage(), $usage, $response->text);

                    return [null, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận — phản hồi và usage đã được lưu: '.$e->getMessage()];
                }

                if (! $this->stageStore->finishSucceeded($stageId, $token, $response->text, $stored, $usage)) {
                    try {
                        $this->stageStore->recordOrphanAttempt((string) $stage->project_id, $name, $run['input'], [], $stageId, $token, 'claim_lost', $usage, $response->text, $stored);
                    } catch (\Throwable $e) {
                        Log::warning("{$log}: recordOrphanAttempt threw", ['stage_id' => $stageId, 'exception' => $e]);
                    }

                    return [null, "Lượt {$label} bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang rồi bấm lại nếu cần."];
                }

                return [VideoPlanningStage::query()->withoutSnapshot()->find($stageId), 'ok'];
            } catch (\Throwable $e) {
                $queued = $usage === [] && $rateLimited !== null ? $rateLimited($e) : null;

                if ($queued !== null) {
                    return $queued;
                }

                Log::error("{$log}: the run failed", ['stage_id' => $stageId, 'exception' => $e]);
                $billed = $e instanceof LlmUnavailable ? $e->billed : null;
                $message = self::failure($e);
                $this->stageStore->finishFailed(
                    $stageId,
                    $token,
                    $message,
                    $usage !== [] ? $usage : ($billed === null ? ['model' => $settings['model'], 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version)),
                    $billed?->text ?? '',
                );

                return [null, $message];
            }
        };
    }

    /** @return string the failure text of a run, which says plainly when the provider account has run out */
    public static function failure(\Throwable $e): string
    {
        return (ProviderRateLimit::of($e)['kind'] ?? null) === ProviderRateLimit::QUOTA
            ? 'Hết hạn mức hoặc tín dụng tài khoản nhà cung cấp — cần người can thiệp, không tự thử lại: '.$e->getMessage()
            : $e->getMessage();
    }

    /** @param array<string, mixed> $run the prepared run whose system text, payload, prompt version and call settings a worker sends later */
    public static function snapshot(array $run): string
    {
        $system = (string) $run['system'];
        $payload = (string) $run['payload'];

        return (string) json_encode([
            'format' => self::SNAPSHOT_FORMAT,
            'system' => $system,
            'system_sha256' => hash('sha256', $system),
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
            'prompt_version' => (string) $run['prompt_version'],
            'settings' => array_intersect_key($run['settings'], array_flip(['model', 'max_tokens', 'effort', 'timeout_seconds'])),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what a request carrying an operation id that already ran gets back */
    public static function replay(VideoPlanningStage $stage): array
    {
        return match ($stage->status) {
            VideoPlanningStageStatus::SUCCEEDED->value => [$stage, 'replayed'],
            VideoPlanningStageStatus::PENDING->value => [$stage, 'queued'],
            VideoPlanningStageStatus::RUNNING->value => [null, 'Thao tác này đang chạy — đợi xong rồi tải lại trang.'],
            default => [null, 'Thao tác này đã chạy và lỗi: '.$stage->error_message.' — tải lại trang để thử lại.'],
        };
    }

    public static function push(VideoPlanningStage $stage, string $token, int $delay): void
    {
        $metadata = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $requester = $metadata['requested_by']['id'] ?? null;

        RunPlanningStageJob::dispatch((string) $metadata['step'], (string) $stage->id, $token, (int) $metadata['job_timeout'])
            ->onConnection(VideoQueues::CONNECTION)
            ->onQueue(VideoQueues::name(VideoQueues::API, is_string($requester) ? $requester : null))
            ->delay($delay)
            ->beforeCommit();
    }

    private function replayed(string $projectId, PlanningStageName $name, string $operationId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', $name->value)
            ->where('input_json->'.PlanningStageStore::METADATA_KEY.'->operation_id', $operationId)->orderByDesc('planning_revision')->first();
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    private function refused(?VideoPlanningStage $stage, string $reason, string $label, bool $queued): array
    {
        return match ($reason) {
            'operation_replayed' => self::replay($stage),
            'already_succeeded' => [$stage, 'cached'],
            'claimed_by_other' => $queued ? [$stage, 'queued'] : [null, "Đang có một lượt {$label} chạy cho dự án này — đợi xong rồi thử lại."],
            default => [null, 'Không tìm thấy dự án.'],
        };
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed> the run as it was prepared when it was queued, with the objects this code prepares now
     */
    private function fromSnapshot(VideoPlanningStage $stage, array $prepared): array
    {
        $raw = $this->stageStore->snapshotOf((string) $stage->id);
        $snapshot = $raw === null ? null : json_decode($raw, true);

        if (! is_array($snapshot) || ($snapshot['format'] ?? null) !== self::SNAPSHOT_FORMAT) {
            throw new RuntimeException('Lượt này không có bản chụp yêu cầu định dạng '.self::SNAPSHOT_FORMAT.' — không gọi model; bấm tạo lại.');
        }

        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $metadata = PlanningStageStore::metadataOf($input);
        unset($input[PlanningStageStore::METADATA_KEY]);
        $system = (string) ($snapshot['system'] ?? '');
        $payload = (string) ($snapshot['payload'] ?? $metadata['payload'] ?? '');
        $payloadSha = (string) ($snapshot['payload_sha256'] ?? $input['payload_sha256'] ?? '');

        if (! hash_equals((string) ($snapshot['system_sha256'] ?? ''), hash('sha256', $system))
            || ! hash_equals($payloadSha, hash('sha256', $payload))
            || (isset($input['payload_sha256']) && ! hash_equals((string) $input['payload_sha256'], hash('sha256', $payload)))) {
            throw new RuntimeException('Bản chụp yêu cầu không khớp mã băm đã lưu — không gọi model; bấm tạo lại.');
        }

        return array_replace($prepared, [
            'system' => $system,
            'payload' => $payload,
            'prompt_version' => (string) $snapshot['prompt_version'],
            'settings' => (array) $snapshot['settings'] + (array) ($prepared['settings'] ?? []),
            'input' => $input,
            'metadata' => $metadata,
        ]);
    }

    /** @return ?array{0: ?VideoPlanningStage, 1: string} the run queued again when the provider refused it for its rate limit before billing, null for every other failure */
    private function rateLimited(VideoPlanningStage $stage, string $token, array $run, \Throwable $e, string $label, string $log): ?array
    {
        $refusal = ProviderRateLimit::of($e);

        if ($refusal === null || $refusal['kind'] !== ProviderRateLimit::RATE_LIMIT) {
            return null;
        }

        Log::warning("{$log}: the provider limited the rate, the run waits in the queue", ['stage_id' => (string) $stage->id, 'retry_after' => $refusal['retry_after']]);

        return $this->wait($stage, $token, $run, $refusal['retry_after'], 0, $label, ProviderRateLimit::RATE_LIMIT, $e->getMessage());
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    private function wait(VideoPlanningStage $stage, string $token, array $run, ?int $retryAfter, int $cooldown, string $label, string $reason, string $detail = ''): array
    {
        $stageId = (string) $stage->id;
        $model = (string) $run['settings']['model'];
        $previous = is_array($run['metadata']['retry'] ?? null) ? $run['metadata']['retry'] : [];
        $attempt = (int) ($previous['attempts'] ?? 0) + 1;
        $firstAt = (int) ($previous['first_at'] ?? time());
        $maxWait = (int) config('video.async.rate_limit.max_wait_seconds');
        $delay = ProviderRateLimit::delay($retryAfter, $attempt, max($cooldown, ProviderRateLimit::cooldownRemaining(ProviderRateLimit::ANTHROPIC, $model)));

        if ($reason === ProviderRateLimit::RATE_LIMIT) {
            ProviderRateLimit::coolDown(ProviderRateLimit::ANTHROPIC, $model, $delay);
        }

        if (time() + $delay > $firstAt + $maxWait) {
            $message = "Nhà cung cấp vẫn giới hạn tốc độ sau {$attempt} lần chờ — hết thời hạn chờ ".intdiv($maxWait, 60).' phút, không gửi lại. Bấm tạo lại sau.'.($detail === '' ? '' : ' '.$detail);
            $this->stageStore->finishFailed($stageId, $token, $message);

            return [null, $message];
        }

        $retry = [
            'attempts' => $attempt,
            'first_at' => $firstAt,
            'deadline_at' => $firstAt + $maxWait,
            'reason' => $reason,
            'retry_at' => time() + $delay,
            'detail' => mb_substr($detail, 0, 300),
        ];

        return $this->stageStore->requeue($stageId, $token, $retry, static fn (string $next) => self::push($stage, $next, $delay))
            ? [$stage, 'requeued']
            : [null, "Lượt {$label} không còn giữ quyền chạy — không xếp hàng lại."];
    }
}
