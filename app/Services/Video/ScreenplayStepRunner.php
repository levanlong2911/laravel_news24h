<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayFailure;
use App\Video\Screenplay\ScreenplayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

final class ScreenplayStepRunner
{
    public const CURL_OPERATION_TIMEDOUT = 28;

    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $fixed
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     * @param  callable(ScreenplayResult, array{stage_id: string, lease_expires_at: mixed}): array{0: ?string, 1: ?array<string, mixed>, 2: string, 3?: array{usage?: array<string, mixed>, metadata?: array<string, mixed>}}  $finish
     * @param  array<string, mixed>|null  $constrainedSchema
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function run(
        string $projectId,
        PlanningStageName $stage,
        string $label,
        ScreenplayAuthor $author,
        array $source,
        array $profile,
        array $requirements,
        array $fixed,
        array $input,
        array $meta,
        bool $force,
        callable $finish,
        ?array $constrainedSchema = null,
    ): array {
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            $projectId, $stage, $input, $force, $meta,
        );

        if ($claimReason === 'already_succeeded') {
            return [$claimed->output_json ?? [], 'cached'];
        }

        if ($token === null) {
            return [null, 'screenplay_running'];
        }

        $fail = function (string $error, string $reason, array $usage = [], string $raw = '', array $metadata = []) use (
            $projectId, $stage, $label, $input, $meta, $claimed, $token,
        ): string {
            $recorded = $this->recordFailure($projectId, $claimed->id, $token, $error, $reason, $usage, $raw, $metadata);

            return $recorded === 'screenplay_claim_lost' && ($usage !== [] || $raw !== '')
                ? $this->keepLostAttempt($projectId, $stage, $label, $input, $meta, $claimed->id, $token, $error, $usage, $raw)
                : $recorded;
        };

        $startedAt = microtime(true);

        try {
            $result = $author->author($source, $profile, $requirements, $fixed, $constrainedSchema);
        } catch (ScreenplayFailure $e) {
            Log::error("screenplay {$label}: author failed after a paid response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'kind' => $e->kind,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $fail(
                $e->getMessage(),
                $e->kind === ScreenplayFailure::TRUNCATED ? 'screenplay_truncated' : 'screenplay_author_failed',
                $e->usage, $e->rawResponse,
            )];
        } catch (ConnectionException $e) {
            $errno = $this->curlErrorNumber($e);

            Log::error("screenplay {$label}: the request never reached a response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'curl_errno' => $errno,
                'exception' => $e,
            ]);

            return [null, $this->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(),
                $errno === self::CURL_OPERATION_TIMEDOUT ? 'screenplay_timeout' : 'screenplay_connection_failed',
            )];
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: author failed before any response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(), 'screenplay_call_failed',
            )];
        }

        try {
            $finished = $finish($result, ['stage_id' => (string) $claimed->id, 'lease_expires_at' => $claimed->lease_expires_at]);
            [$error, $output, $okReason] = $finished;
            $extra = is_array($finished[3] ?? null) ? $finished[3] : [];
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: failed after a paid response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, $fail(
                $e->getMessage(), 'screenplay_after_response_failed', $result->usage, $result->rawResponse,
            )];
        }

        $usage = self::combinedUsage($result->usage, is_array($extra['usage'] ?? null) ? $extra['usage'] : []);
        $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];

        if ($error !== null || $output === null) {
            return [null, $fail((string) $error, 'screenplay_invalid', $usage, $result->rawResponse, $metadata)];
        }

        try {
            $recorded = $this->stageStore->finishSucceeded(
                $claimed->id, $token, $result->rawResponse, $output, $usage, $metadata,
            );
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: writing the paid result threw, stored state unknown", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, 'screenplay_result_not_stored'];
        }

        if (! $recorded) {
            return [null, $this->keepLostAttempt(
                $projectId, $stage, $label, $input, $meta, $claimed->id, $token,
                'claim_lost', $usage, $result->rawResponse, $output,
            )];
        }

        return [$output, $okReason];
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $added
     * @return array<string, mixed>
     */
    public static function combinedUsage(array $primary, array $added): array
    {
        if ($added === []) {
            return $primary;
        }

        foreach (['tokens_in', 'tokens_out', 'cost_usd'] as $key) {
            $primary[$key] = ($primary[$key] ?? 0) + ($added[$key] ?? 0);
        }

        return $primary;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $metadata
     */
    public function recordFailure(
        string $projectId,
        string $stageId,
        string $token,
        string $error,
        string $reason,
        array $usage = [],
        string $rawResponse = '',
        array $metadata = [],
    ): string {
        try {
            $written = $this->stageStore->finishFailed($stageId, $token, $error, $usage, $rawResponse, [], $metadata);
        } catch (\Throwable $storage) {
            Log::error('screenplay: writing the failed attempt threw, stored state unknown', [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'failure' => $error,
                'exception' => $storage,
            ]);

            return 'screenplay_result_not_stored';
        }

        if (! $written) {
            Log::warning('screenplay: claim no longer held, failure not recorded', [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'failure' => $error,
            ]);

            return 'screenplay_claim_lost';
        }

        return $reason;
    }

    public function curlErrorNumber(ConnectionException $e): ?int
    {
        $previous = $e->getPrevious();

        if ($previous instanceof \GuzzleHttp\Exception\ConnectException) {
            $errno = $previous->getHandlerContext()['errno'] ?? null;

            if (is_int($errno)) {
                return $errno;
            }
        }

        return preg_match('/cURL error (\d+)/', $e->getMessage(), $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $output
     */
    private function keepLostAttempt(
        string $projectId,
        PlanningStageName $stage,
        string $label,
        array $input,
        array $meta,
        string $stageId,
        string $token,
        string $error,
        array $usage,
        string $rawResponse,
        array $output = [],
    ): string {
        $kept = false;

        try {
            $kept = $this->stageStore->recordOrphanAttempt(
                $projectId, $stage, $input, $meta, $stageId, $token, $error, $usage, $rawResponse, $output,
            );
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: keeping the lost paid attempt threw", [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'exception' => $e,
            ]);
        }

        Log::warning("screenplay {$label}: claim lost after a paid response", [
            'project_id' => $projectId,
            'stage_id' => $stageId,
            'orphan_recorded' => $kept,
        ]);

        return 'screenplay_claim_lost';
    }
}
