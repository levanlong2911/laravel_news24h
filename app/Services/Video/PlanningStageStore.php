<?php

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanningStageStore
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string} [$stage, $claimToken, $reason]
     *                                                              reason: already_succeeded|claimed_by_other|claimed
     */
    public function claim(
        string $sessionId,
        int $planningRevision,
        PlanningStageName $stage,
        array $input,
    ): array {
        $claimToken = (string) Str::uuid();

        return DB::transaction(function () use ($sessionId, $planningRevision, $stage, $input, $claimToken) {
            $row = VideoPlanningStage::query()
                ->where('session_id', $sessionId)
                ->where('planning_revision', $planningRevision)
                ->where('stage', $stage->value)
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->status === VideoPlanningStageStatus::SUCCEEDED->value) {
                return [$row, null, 'already_succeeded'];
            }

            if ($row !== null && $row->claim_token !== null) {
                return [$row, null, 'claimed_by_other'];
            }

            $attributes = [
                'status' => VideoPlanningStageStatus::RUNNING->value,
                'input_json' => $input,
                'input_hash' => $this->hash($input),
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'started_at' => now(),
                'error_message' => null,
            ];

            if ($row === null) {
                $row = VideoPlanningStage::create($attributes + [
                    'session_id' => $sessionId,
                    'planning_revision' => $planningRevision,
                    'stage' => $stage->value,
                ]);
            } else {
                $row->update($attributes);
            }

            return [$row, $claimToken, 'claimed'];
        });
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array{model?: string, instruction_version?: string, tokens_in?: int, tokens_out?: int, cost_usd?: float}  $usage
     */
    public function finishSucceeded(
        string $stageId,
        string $claimToken,
        string $rawResponse,
        array $output,
        array $usage = [],
    ): bool {
        return $this->finishClaimed($stageId, $claimToken, [
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'raw_response' => $rawResponse,
            'output_json' => $output,
            'output_hash' => hash('sha256', $rawResponse),
            'model' => $usage['model'] ?? null,
            'instruction_version' => $usage['instruction_version'] ?? null,
            'tokens_in' => $usage['tokens_in'] ?? 0,
            'tokens_out' => $usage['tokens_out'] ?? 0,
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ]);
    }

    /** @param array{tokens_in?: int, tokens_out?: int, cost_usd?: float} $usage */
    public function finishFailed(string $stageId, string $claimToken, string $error, array $usage = []): bool
    {
        return $this->finishClaimed($stageId, $claimToken, [
            'status' => VideoPlanningStageStatus::FAILED->value,
            'error_message' => $error,
            'tokens_in' => $usage['tokens_in'] ?? 0,
            'tokens_out' => $usage['tokens_out'] ?? 0,
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function outputOf(string $sessionId, int $planningRevision, PlanningStageName $stage): ?array
    {
        $row = VideoPlanningStage::query()
            ->where('session_id', $sessionId)
            ->where('planning_revision', $planningRevision)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        return $row?->output_json;
    }

    public function rawResponseOf(string $sessionId, int $planningRevision, PlanningStageName $stage): ?string
    {
        return VideoPlanningStage::query()
            ->where('session_id', $sessionId)
            ->where('planning_revision', $planningRevision)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->value('raw_response');
    }

    /** @param array<string, mixed> $attributes */
    private function finishClaimed(string $stageId, string $claimToken, array $attributes): bool
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->update($attributes + [
                'claim_token' => null,
                'claimed_at' => null,
                'finished_at' => now(),
            ]) > 0;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
