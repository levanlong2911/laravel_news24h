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
    private const LEASE_SECONDS = 1900;

    public const METADATA_KEY = '_meta';

    /** @var string the metadata key of an attempt recorded apart from the run that lost its claim: why it was lost and whether the usage stored with it is the whole usage of the call */
    public const ORPHAN_KEY = 'orphan';

    private const PRICING_METADATA_KEYS = ['pricing', 'pricing_version', 'design_repair'];

    /** @var array<string, ?string> the snapshot payloads already read in this request, by stage id */
    private array $payloads = [];

    /**
     * @param  array<string, mixed>|null  $inputJson
     * @return array<string, mixed>
     */
    public static function metadataOf(?array $inputJson): array
    {
        $metadata = $inputJson[self::METADATA_KEY] ?? null;

        return is_array($metadata) ? $metadata : [];
    }

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
     * @param  bool|\Closure(VideoPlanningStage): bool  $force  a closure decides inside the claim transaction from the succeeded row that would be reused
     * @param  array<string, mixed>  $metadata  a non-empty `operation_id` here is checked under the project lock: a row already holding it is returned, never claimed again
     * @param  ?\Closure(): bool  $refuse  read under the project lock, before any claim: true opens no run
     * @param  bool  $reuseExpired  take over a running row whose lease ran out instead of adding one; false always adds a row, so the answer and cost a run kept stay its own
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string} [$stage, $claimToken, $reason]
     *                                                              reason: project_not_found|operation_replayed|refused|claimed_by_other|already_succeeded|claimed
     */
    public function claimProjectStage(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        bool|\Closure $force = false,
        array $metadata = [],
        ?\Closure $refuse = null,
        bool $reuseExpired = true,
    ): array {
        return $this->openProjectStage($projectId, $stage, $input, $force, $metadata, VideoPlanningStageStatus::RUNNING, null, null, $refuse, $reuseExpired);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  bool|\Closure(VideoPlanningStage): bool  $force
     * @param  array<string, mixed>  $metadata
     * @param  string  $snapshot  the request a worker sends later, kept apart from input_json
     * @param  ?\Closure(VideoPlanningStage, string): void  $push  inserts the job of the pending row inside the same transaction, on the same connection
     * @param  ?\Closure(): bool  $refuse  read under the project lock, before any claim: true opens no run
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string} [$stage, $claimToken, $reason]
     *                                                              reason: project_not_found|operation_replayed|refused|claimed_by_other|already_succeeded|queued
     */
    public function queueProjectStage(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        bool|\Closure $force,
        array $metadata,
        string $snapshot,
        ?\Closure $push,
        ?\Closure $refuse = null,
    ): array {
        return $this->openProjectStage($projectId, $stage, $input, $force, $metadata, VideoPlanningStageStatus::PENDING, $snapshot, $push, $refuse);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  bool|\Closure(VideoPlanningStage): bool  $force
     * @param  array<string, mixed>  $metadata
     * @param  ?\Closure(VideoPlanningStage, string): void  $push
     * @param  ?\Closure(): bool  $refuse
     * @param  bool  $reuseExpired  take over a running row whose lease ran out
     * @return array{0: ?VideoPlanningStage, 1: ?string, 2: string}
     */
    private function openProjectStage(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        bool|\Closure $force,
        array $metadata,
        VideoPlanningStageStatus $status,
        ?string $snapshot,
        ?\Closure $push,
        ?\Closure $refuse = null,
        bool $reuseExpired = true,
    ): array {
        if (array_key_exists(self::METADATA_KEY, $input)) {
            throw new \InvalidArgumentException(
                'Planning stage input may not carry a reserved '.self::METADATA_KEY.' key.'
            );
        }

        $hash = $this->hash($input);
        $stored = $metadata === [] ? $input : array_replace($input, [self::METADATA_KEY => $metadata]);
        $claimToken = (string) Str::uuid();

        try {
            $operationId = is_string($metadata['operation_id'] ?? null) && $metadata['operation_id'] !== '' ? $metadata['operation_id'] : null;

            return DB::transaction(function () use ($projectId, $stage, $stored, $hash, $claimToken, $force, $operationId, $status, $snapshot, $push, $refuse, $reuseExpired) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $rows = static fn (): Builder => VideoPlanningStage::query()
                    ->withoutSnapshot()
                    ->where('project_id', $projectId)
                    ->where('stage', $stage->value);

                $replayed = $operationId === null ? null : $rows()
                    ->where('input_json->'.self::METADATA_KEY.'->operation_id', $operationId)
                    ->orderByDesc('planning_revision')
                    ->first();

                if ($replayed !== null) {
                    return [$replayed, null, 'operation_replayed'];
                }

                if ($refuse !== null && $refuse()) {
                    return [null, null, 'refused'];
                }

                $open = $rows()
                    ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
                    ->orderBy('planning_revision')
                    ->get();
                $live = $open->first(fn ($r) => $r->claim_token !== null && (
                    ($r->status === VideoPlanningStageStatus::RUNNING->value && $r->lease_expires_at?->isFuture())
                    || $r->status === VideoPlanningStageStatus::PENDING->value
                ));

                if ($live !== null) {
                    return [$live, null, 'claimed_by_other'];
                }

                $done = $rows()
                    ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
                    ->where('input_hash', $hash)
                    ->orderByDesc('planning_revision')
                    ->first();

                if ($done !== null && ! ($force instanceof \Closure ? $force($done) : $force)) {
                    return [$done, null, 'already_succeeded'];
                }

                $running = $status === VideoPlanningStageStatus::RUNNING;
                $attributes = [
                    'status' => $status->value,
                    'input_json' => $stored,
                    'input_hash' => $hash,
                    'request_snapshot' => $snapshot,
                    'claim_token' => $claimToken,
                    'claimed_at' => $running ? now() : null,
                    'lease_expires_at' => $running ? now()->addSeconds(self::LEASE_SECONDS) : null,
                    'started_at' => $running ? now() : null,
                    'error_message' => null,
                ];

                $expired = $reuseExpired ? $open->firstWhere('status', VideoPlanningStageStatus::RUNNING->value) : null;

                if ($expired !== null) {
                    $expired->update($attributes);
                    $row = $expired;
                } else {
                    $row = VideoPlanningStage::create($attributes + [
                        'project_id' => $projectId,
                        'planning_revision' => ((int) $rows()->max('planning_revision')) + 1,
                        'stage' => $stage->value,
                    ]);
                }

                if ($push !== null) {
                    $push($row, $claimToken);
                }

                return [$row, $claimToken, $running ? 'claimed' : 'queued'];
            });
        } catch (ModelNotFoundException $e) {
            return [null, null, 'project_not_found'];
        }
    }

    public function acceptQueued(string $stageId, string $claimToken): bool
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->where('status', VideoPlanningStageStatus::PENDING->value)
            ->update([
                'status' => VideoPlanningStageStatus::RUNNING->value,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'started_at' => now(),
            ]) > 0;
    }

    /**
     * @param  array<string, mixed>  $retry
     * @param  \Closure(string): void  $push  inserts the delayed job for the new claim token inside the same transaction
     * @return bool false when the run no longer holds this claim, so a reset or a recovered run is never brought back
     */
    public function requeue(string $stageId, string $claimToken, array $retry, \Closure $push): bool
    {
        $table = (new VideoPlanningStage)->getTable();
        $meta = '$."'.self::METADATA_KEY.'"';
        $path = $meta.'."retry"';

        return DB::transaction(function () use ($table, $meta, $path, $stageId, $claimToken, $retry, $push): bool {
            $next = (string) Str::uuid();
            $moved = DB::update(
                "UPDATE {$table} SET status = ?, claim_token = ?, claimed_at = NULL, lease_expires_at = NULL, input_json = JSON_SET(JSON_INSERT(input_json, '{$meta}', JSON_OBJECT()), '{$path}', JSON_EXTRACT(?, '$')), updated_at = ? WHERE id = ? AND claim_token = ? AND status = ?",
                [VideoPlanningStageStatus::PENDING->value, $next, json_encode($retry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), $stageId, $claimToken, VideoPlanningStageStatus::RUNNING->value],
            ) > 0;

            if ($moved) {
                $push($next);
            }

            return $moved;
        });
    }

    public function snapshotOf(string $stageId): ?string
    {
        $snapshot = VideoPlanningStage::query()->whereKey($stageId)->value('request_snapshot');

        return is_string($snapshot) ? $snapshot : null;
    }

    /**
     * @param  \Closure(string): bool  $hasJob  whether the queue still holds a job for a pending stage id
     * @return array{running: int, pending: int} the runs marked failed in this pass
     */
    public function recoverExpired(int $limit, int $pendingGraceSeconds, \Closure $hasJob): array
    {
        $recovered = ['running' => 0, 'pending' => 0];
        $expired = VideoPlanningStage::query()
            ->where('status', VideoPlanningStageStatus::RUNNING->value)
            ->whereNotNull('claim_token')
            ->where('lease_expires_at', '<', now())
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->get(['id', 'claim_token']);

        foreach ($expired as $row) {
            $recovered['running'] += VideoPlanningStage::query()
                ->whereKey($row->id)
                ->where('claim_token', $row->claim_token)
                ->where('status', VideoPlanningStageStatus::RUNNING->value)
                ->where('lease_expires_at', '<', now())
                ->update($this->recoveredAttributes('Lease hết hạn — tiến trình chạy lượt này đã dừng hoặc mất kết nối. Không tự gửi lại yêu cầu tính phí; bấm tạo lại nếu cần.'));
        }

        $orphans = VideoPlanningStage::query()
            ->where('status', VideoPlanningStageStatus::PENDING->value)
            ->whereNotNull('claim_token')
            ->where('updated_at', '<', now()->subSeconds($pendingGraceSeconds))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'claim_token']);

        foreach ($orphans as $row) {
            if ($hasJob((string) $row->id)) {
                continue;
            }

            $recovered['pending'] += VideoPlanningStage::query()
                ->whereKey($row->id)
                ->where('claim_token', $row->claim_token)
                ->where('status', VideoPlanningStageStatus::PENDING->value)
                ->update($this->recoveredAttributes('Lượt chờ không còn job trong hàng đợi — chưa gọi model. Bấm tạo lại nếu cần.'));
        }

        return $recovered;
    }

    /** @param array<string, mixed> $input */
    public function inputHash(array $input): string
    {
        return $this->hash($input);
    }

    /** @return array<string, mixed> */
    private function recoveredAttributes(string $error): array
    {
        return [
            'status' => VideoPlanningStageStatus::FAILED->value,
            'error_message' => $error,
            'claim_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ];
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


    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $usage
     * @param  array<string, ?string>  $pricing
     */
    public function finishSucceeded(
        string $stageId,
        string $claimToken,
        string $rawResponse,
        array $output,
        array $usage = [],
        array $pricing = [],
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
            'thinking_tokens' => self::measuredThinking($usage),
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ] + $this->pricingAttributes($pricing));
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
        array $output = [],
        array $pricing = [],
    ): bool {
        return $this->finishClaimed($stageId, $claimToken, ($output === [] ? [] : ['output_json' => $output]) + [
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
            'thinking_tokens' => self::measuredThinking($usage),
            'cost_usd' => $usage['cost_usd'] ?? 0,
        ] + $this->pricingAttributes($pricing));
    }

    /**
     * @param  array<string, ?string>  $pricing
     * @return array<string, ?string>
     */
    private function pricingAttributes(array $pricing): array
    {
        $unknown = array_diff(array_keys($pricing), self::PRICING_METADATA_KEYS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Only pricing metadata may change when a stage finishes: '.implode(', ', $unknown)
            );
        }

        $attributes = [];

        foreach ($pricing as $key => $value) {
            $attributes['input_json->'.self::METADATA_KEY.'->'.$key] = $value;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $usage
     */
    public function recordOrphanAttempt(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        array $metadata,
        string $orphanOfStageId,
        string $lostClaimToken,
        string $error,
        array $usage = [],
        string $rawResponse = '',
        array $output = [],
    ): bool {
        if (array_key_exists(self::METADATA_KEY, $input)) {
            throw new \InvalidArgumentException(
                'Planning stage input may not carry a reserved '.self::METADATA_KEY.' key.'
            );
        }

        $attempt = array_replace($input, [
            'orphan_of_stage_id' => $orphanOfStageId,
            'orphan_claim_token' => $lostClaimToken,
        ]);

        $hash = $this->hash($attempt);
        $stored = $metadata === [] ? $attempt : array_replace($attempt, [self::METADATA_KEY => $metadata]);

        return DB::transaction(function () use (
            $projectId, $stage, $stored, $hash, $error, $usage, $rawResponse, $output
        ) {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

            $already = VideoPlanningStage::query()
                ->where('project_id', $projectId)
                ->where('stage', $stage->value)
                ->where('status', VideoPlanningStageStatus::FAILED->value)
                ->where('input_hash', $hash)
                ->exists();

            if ($already) {
                return false;
            }

            $revision = ((int) VideoPlanningStage::query()
                ->where('project_id', $projectId)
                ->where('stage', $stage->value)
                ->max('planning_revision')) + 1;

            VideoPlanningStage::create([
                'project_id' => $projectId,
                'planning_revision' => $revision,
                'stage' => $stage->value,
                'status' => VideoPlanningStageStatus::FAILED->value,
                'input_json' => $stored,
                'input_hash' => $hash,
                'output_json' => $output === [] ? null : $output,
                'error_message' => $error,
                'raw_response' => $rawResponse !== '' ? $rawResponse : null,
                'output_hash' => $rawResponse !== '' ? hash('sha256', $rawResponse) : null,
                'model' => $usage['model'] ?? null,
                'provider_model' => $usage['provider_model'] ?? null,
                'instruction_version' => $usage['instruction_version'] ?? null,
                'tokens_in' => $usage['tokens_in'] ?? 0,
                'tokens_out' => $usage['tokens_out'] ?? 0,
                'thinking_tokens' => self::measuredThinking($usage),
                'cost_usd' => $usage['cost_usd'] ?? 0,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            return true;
        });
    }

    /** @return array<string, mixed>|null */
    public function latestOutputForProject(string $projectId, PlanningStageName $stage): ?array
    {
        return $this->succeeded('project_id', $projectId, $stage)
            ->orderByDesc('planning_revision')
            ->value('output_json');
    }

    public function stageForProjectRevision(
        string $projectId,
        PlanningStageName $stage,
        int $revision,
    ): ?VideoPlanningStage {
        return $this->succeeded('project_id', $projectId, $stage)
            ->orderByDesc('planning_revision')
            ->cursor()
            ->first(static fn (VideoPlanningStage $row) => is_array($row->output_json)
                && (int) ($row->output_json['revision'] ?? 0) === $revision);
    }

    /** @return array{records: int, unpriced: bool} */
    public function attemptSummaryForProject(string $projectId, PlanningStageName $stage): array
    {
        $rows = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->pluck('input_json');

        $unpriced = $rows->contains(static fn ($input) => is_array($input)
            && ($input[self::METADATA_KEY]['pricing'] ?? null) === 'unpriced');

        return ['records' => $rows->count(), 'unpriced' => $unpriced];
    }

    /** @return array{0: ?VideoPlanningStage, 1: bool} */
    public function latestStageForProject(
        string $projectId,
        PlanningStageName $stage,
        array $input,
        bool $skipOrphans = false,
    ): array {
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->when($skipOrphans, static fn ($query) => $query->whereNull('input_json->orphan_of_stage_id'))
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

    /** @param array<string, mixed> $input */
    public function latestSucceededForInput(string $projectId, PlanningStageName $stage, array $input): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_hash', $this->hash($input))
            ->orderByDesc('planning_revision')
            ->first();
    }

    /** @return ?array<string, mixed> the stream state saved on a stage that has not succeeded */
    public static function streamOf(?VideoPlanningStage $stage): ?array
    {
        if ($stage === null || $stage->status === VideoPlanningStageStatus::SUCCEEDED->value) {
            return null;
        }

        $stream = self::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['stream'] ?? null;

        return is_array($stream) ? $stream : null;
    }

    /** @return ?array{stage_id: string, status: string, requested_by: ?string, queued_at: ?string, started_at: ?string, retry_at: ?int} the run still waiting or running, as a page and the status endpoint show it */
    public static function taskOf(?VideoPlanningStage $stage): ?array
    {
        if ($stage === null || $stage->claim_token === null || ! in_array($stage->status, [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value], true)) {
            return null;
        }

        $metadata = self::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $retryAt = $metadata['retry']['retry_at'] ?? null;

        return [
            'stage_id' => (string) $stage->id,
            'status' => (string) $stage->status,
            'requested_by' => is_string($metadata['requested_by']['name'] ?? null) ? $metadata['requested_by']['name'] : null,
            'queued_at' => is_string($metadata['queued_at'] ?? null) ? $metadata['queued_at'] : null,
            'started_at' => $stage->started_at?->toIso8601String(),
            'retry_at' => $stage->status === VideoPlanningStageStatus::PENDING->value && is_int($retryAt) ? $retryAt : null,
        ];
    }

    /** @return bool false when the stored tokens and cost are only what was known before the call broke */
    public static function costKnown(VideoPlanningStage $stage): bool
    {
        return $stage->status === VideoPlanningStageStatus::SUCCEEDED->value || (self::streamOf($stage)['usage_complete'] ?? false) === true
            || (self::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)[self::ORPHAN_KEY]['usage_complete'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return bool false once the claim is no longer held; while it is held by a running stage, the progress is stored and the lease renewed for LEASE_SECONDS in one conditional update
     */
    public function recordStream(string $stageId, string $claimToken, array $state): bool
    {
        $table = (new VideoPlanningStage)->getTable();
        $meta = '$."'.self::METADATA_KEY.'"';
        $path = $meta.'."stream"';
        $running = VideoPlanningStageStatus::RUNNING->value;

        return DB::update(
            "UPDATE {$table} SET input_json = JSON_SET(JSON_INSERT(input_json, '{$meta}', JSON_OBJECT()), '{$path}', JSON_EXTRACT(?, '$')), lease_expires_at = ?, updated_at = ? WHERE id = ? AND claim_token = ? AND status = ?",
            [json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()->addSeconds(self::LEASE_SECONDS), now(), $stageId, $claimToken, $running],
        ) > 0 || VideoPlanningStage::query()->whereKey($stageId)->where('claim_token', $claimToken)->where('status', $running)->exists();
    }

    /**
     * @return array{0: bool, 1: string} the latest run of a step reset, read again under the project lock; reason pending when it was still waiting in the queue. A waiting run is cancelled and a running one is reset only once its lease ran out: a run whose worker still holds its lease is refused, a finished run is left as it is. The write names the claim token, the status and the expired lease it read, so a run that was accepted, renewed or claimed again in between keeps its claim and the reset says the state changed
     */
    public function resetLatest(string $projectId, PlanningStageName $stage, string $none): array
    {
        return $this->resetRead($projectId, static fn (Builder $runs) => $runs
            ->where('stage', $stage->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision'), $none);
    }

    /**
     * @param  string  $stageId  the run a caller found as the one its screen shows, by its own rule for what the latest run of its step is
     * @return array{0: bool, 1: string} that run reset under the rule of resetLatest(): read again under the project lock, a waiting run cancelled, a running one reset only once its lease ran out, and nothing written when it moved on in between
     */
    public function resetRun(string $projectId, string $stageId, string $none): array
    {
        return $this->resetRead($projectId, static fn (Builder $runs) => $runs->whereKey($stageId), $none);
    }

    /**
     * @param  \Closure(Builder): Builder  $choose  narrows the runs of the project to the one to reset
     * @return array{0: bool, 1: string}
     */
    private function resetRead(string $projectId, \Closure $choose, string $none): array
    {
        return DB::transaction(function () use ($projectId, $choose, $none): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();
            $latest = $choose(VideoPlanningStage::query()->where('project_id', $projectId))
                ->first(['id', 'status', 'claim_token', 'lease_expires_at']);

            if ($latest === null) {
                return [false, $none];
            }

            $pending = $latest->status === VideoPlanningStageStatus::PENDING->value;
            $running = $latest->status === VideoPlanningStageStatus::RUNNING->value;

            if ($latest->claim_token === null || ! ($pending || $running)) {
                return [false, 'Lượt này không còn giữ claim — không có gì để reset.'];
            }

            if ($running && $latest->lease_expires_at?->isFuture() === true) {
                return [false, 'Lượt này đang chạy và còn giữ quyền đến '.$latest->lease_expires_at->format('H:i:s').' — không reset; đợi lượt xong hoặc hết hạn giữ quyền rồi thử lại.'];
            }

            $released = VideoPlanningStage::query()
                ->whereKey($latest->id)
                ->where('claim_token', $latest->claim_token)
                ->where('status', $latest->status)
                ->when($running, static fn (Builder $query) => $query->where(static fn (Builder $lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now())))
                ->update(self::releasedAttributes($pending ? 'Người dùng huỷ lượt đang chờ trong hàng đợi' : 'Người dùng reset thủ công')) > 0;

            return $released
                ? [true, $pending ? 'pending' : 'ok']
                : [false, 'Trạng thái của lượt này vừa đổi (đã được nhận, gia hạn hoặc chạy lại) — không reset; tải lại trang để xem trạng thái mới.'];
        });
    }

    /** @return ?string the payload a stage was sent with: its snapshot for a run queued with one, its metadata for a run made before snapshots */
    public function payloadOf(VideoPlanningStage $stage): ?string
    {
        $id = (string) $stage->id;

        if (array_key_exists($id, $this->payloads)) {
            return $this->payloads[$id];
        }

        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $raw = $this->snapshotOf($id);

        if ($raw === null) {
            $metadata = self::metadataOf($input);

            return $this->payloads[$id] = is_string($metadata['payload'] ?? null) ? $metadata['payload'] : null;
        }

        $snapshot = json_decode($raw, true);
        $payload = is_array($snapshot) ? ($snapshot['payload'] ?? null) : null;

        if (! is_array($snapshot) || ($snapshot['format'] ?? null) !== StageQueue::SNAPSHOT_FORMAT || ! is_string($payload)
            || ! is_string($snapshot['payload_sha256'] ?? null) || ! hash_equals($snapshot['payload_sha256'], hash('sha256', $payload))
            || (isset($input['payload_sha256']) && ! hash_equals((string) $input['payload_sha256'], hash('sha256', $payload)))) {
            throw new \RuntimeException("Bản chụp yêu cầu của lượt {$id} hỏng, sai định dạng hoặc không khớp mã băm — không dùng được lượt này.");
        }

        return $this->payloads[$id] = $payload;
    }

    /**
     * @param  ?string  $claimToken  the claim the caller holds or read: with it only that very claim is released, so a run claimed again since keeps its own
     * @return bool false when the run holds no claim, or no longer the one named
     */
    public function releaseClaim(string $stageId, string $reason, ?string $claimToken = null): bool
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->whereNotNull('claim_token')
            ->when($claimToken !== null, static fn (Builder $query) => $query->where('claim_token', $claimToken))
            ->update(self::releasedAttributes($reason)) > 0;
    }

    /** @return array<string, mixed> what a run becomes when its claim is released without an answer */
    private static function releasedAttributes(string $reason): array
    {
        return [
            'status' => VideoPlanningStageStatus::FAILED->value,
            'error_message' => $reason,
            'claim_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ];
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

    /** @param array<string, mixed> $usage */
    private static function measuredThinking(array $usage): ?int
    {
        $value = $usage['thinking_tokens'] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
