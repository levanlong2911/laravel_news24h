<?php

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanningStageStore
{
    private const LEASE_SECONDS = 180;

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
        return $this->claimIn('session_id', $sessionId, $planningRevision, $stage, $input);
    }

    /**
     * `$force` bo qua DUNG MOT dieu: "da co ket qua thanh cong voi cung input".
     * Moi chot khac giu nguyen — dac biet la `$live`, nen hai lan bam lien tiep
     * trong mot ky lease van chi mot lan goi model.
     *
     * Dung cho nut "dung lai concept": cung bai, cung brief, nhung nguoi dung
     * muon model thu lai. Do la mot lan CHI TIEN CO CHU DICH, khac han voi viec
     * bam nham hai lan — nen no phai di qua mot nut rieng, khong phai mot co an
     * trong request.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string} [$stage, $claimToken, $reason]
     *                                                              reason: project_not_found|claimed_by_other|already_succeeded|claimed
     */
    public function claimProjectStage(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        bool $force = false,
    ): array {
        $hash = $this->hash($input);
        $claimToken = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($projectId, $stage, $input, $hash, $claimToken, $force) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $rows = VideoPlanningStage::query()
                    ->where('project_id', $projectId)
                    ->where('stage', $stage->value)
                    ->get();

                $live = $rows->first(fn ($r) => $r->status === VideoPlanningStageStatus::RUNNING->value
                    && $r->claim_token !== null
                    && $r->lease_expires_at?->isFuture());

                if ($live !== null) {
                    return [$live, null, 'claimed_by_other'];
                }

                $done = $rows->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
                    ->where('input_hash', $hash)
                    ->sortByDesc('planning_revision')
                    ->first();

                if ($done !== null && ! $force) {
                    return [$done, null, 'already_succeeded'];
                }

                $attributes = [
                    'status' => VideoPlanningStageStatus::RUNNING->value,
                    'input_json' => $input,
                    'input_hash' => $hash,
                    'claim_token' => $claimToken,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                    'started_at' => now(),
                    'error_message' => null,
                ];

                $expired = $rows->firstWhere('status', VideoPlanningStageStatus::RUNNING->value);

                if ($expired !== null) {
                    $expired->update($attributes);

                    return [$expired, $claimToken, 'claimed'];
                }

                return [
                    VideoPlanningStage::create($attributes + [
                        'project_id' => $projectId,
                        'planning_revision' => ((int) $rows->max('planning_revision')) + 1,
                        'stage' => $stage->value,
                    ]),
                    $claimToken,
                    'claimed',
                ];
            });
        } catch (ModelNotFoundException $e) {
            return [null, null, 'project_not_found'];
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string}
     */
    private function claimIn(
        string $column,
        string $id,
        int $planningRevision,
        PlanningStageName $stage,
        array $input,
    ): array {
        $claimToken = (string) Str::uuid();

        return DB::transaction(function () use ($column, $id, $planningRevision, $stage, $input, $claimToken) {
            $row = VideoPlanningStage::query()
                ->where($column, $id)
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
                    $column => $id,
                    'planning_revision' => $planningRevision,
                    'stage' => $stage->value,
                ]);
            } else {
                $row->update($attributes);
            }

            return [$row, $claimToken, 'claimed'];
        });
    }


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
            'provider_model' => $usage['provider_model'] ?? null,
            'instruction_version' => $usage['instruction_version'] ?? null,
            'tokens_in' => $usage['tokens_in'] ?? 0,
            'tokens_out' => $usage['tokens_out'] ?? 0,
            'thinking_tokens' => $usage['thinking_tokens'] ?? 0,
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ]);
    }

    /** @param array{tokens_in?: int, tokens_out?: int, cost_usd?: float} $usage */
    /**
     * `$rawResponse` di CUOI de moi loi goi hien co giu nguyen hieu luc.
     *
     * Duong that bai truoc day chi ghi tien va thong bao loi, trong khi duong
     * thanh cong ghi ca raw, hash, model va phien ban. Bat doi xung do chinh la
     * con bo: mot cu goi da tra tien sinh ra cau tra loi BAT KE no co qua duoc
     * validate hay khong.
     */
    public function finishFailed(
        string $stageId,
        string $claimToken,
        string $error,
        array $usage = [],
        string $rawResponse = '',
    ): bool {
        return $this->finishClaimed($stageId, $claimToken, [
            'status' => VideoPlanningStageStatus::FAILED->value,
            'error_message' => $error,
            // null chu khong phai chuoi rong: loi mang thi that su khong co raw,
            // va mot o rong trong DB khong phan biet duoc hai truong hop do.
            'raw_response' => $rawResponse !== '' ? $rawResponse : null,
            'output_hash' => $rawResponse !== '' ? hash('sha256', $rawResponse) : null,
            'model' => $usage['model'] ?? null,
            'provider_model' => $usage['provider_model'] ?? null,
            'instruction_version' => $usage['instruction_version'] ?? null,
            'tokens_in' => $usage['tokens_in'] ?? 0,
            'tokens_out' => $usage['tokens_out'] ?? 0,
            'thinking_tokens' => $usage['thinking_tokens'] ?? 0,
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function outputOf(string $sessionId, int $planningRevision, PlanningStageName $stage): ?array
    {
        return $this->succeeded('session_id', $sessionId, $stage)
            ->where('planning_revision', $planningRevision)
            ->value('output_json');
    }

    public function rawResponseOf(string $sessionId, int $planningRevision, PlanningStageName $stage): ?string
    {
        return $this->succeeded('session_id', $sessionId, $stage)
            ->where('planning_revision', $planningRevision)
            ->value('raw_response');
    }

    /** @return array<string, mixed>|null */
    public function latestOutputForProject(string $projectId, PlanningStageName $stage): ?array
    {
        return $this->succeeded('project_id', $projectId, $stage)
            ->orderByDesc('planning_revision')
            ->value('output_json');
    }

    /** @return array{0: ?VideoPlanningStage, 1: bool} */
    public function latestStageForProject(string $projectId, PlanningStageName $stage, array $input): array
    {
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->orderByDesc('planning_revision')
            ->first();

        return [$latest, $latest?->input_hash === $this->hash($input)];
    }

    public function hasSucceededForProject(string $projectId, PlanningStageName $stage, array $input): bool
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_hash', $this->hash($input))
            ->exists();
    }

    public function releaseClaim(string $stageId, string $reason): bool
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->whereNotNull('claim_token')
            ->update([
                'status' => VideoPlanningStageStatus::FAILED->value,
                'error_message' => $reason,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]) > 0;
    }

    private function succeeded(string $column, string $id, PlanningStageName $stage): Builder
    {
        return VideoPlanningStage::query()
            ->where($column, $id)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value);
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
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]) > 0;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
