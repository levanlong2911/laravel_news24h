Được. Phần 14 là phần biến code Part 13 từ “gọi provider và lưu artifact được” thành render worker production-grade: nhiều worker chạy đồng thời, worker chết giữa chừng, network timeout, provider 429/5xx, request đã bị tính tiền nhưng response chưa checkpoint, queue retry, provider fallback, và cost không bị ghi hai lần.

Tôi giữ nguyên boundary:

Laravel
    = Control Plane + DB truth
    = render state
    = atomic claim / lease
    = idempotency ownership
    = attempt ledger
    = cost financial truth

Python
    = Execution Plane
    = claim work
    = verify request
    = call provider
    = write artifacts
    = checkpoint result
    = heartbeat / resume

Provider Adapter
    = ONE provider call
    = không retry semantic
    = không fallback
    = không update DB

Và nguyên tắc quan trọng nhất của Part 14:

Queue retry không được đồng nghĩa với provider render retry.

Một worker bị kill sau khi provider đã nhận request không được đơn giản gọi provider lần nữa.

PHẦN 14 — Kiến trúc cuối
Laravel Render Record
      │
      │ status = queued
      ▼
Atomic Claim
      │
      ├── claim_token
      ├── worker_id
      ├── lease_expires_at
      └── attempt_no
      ▼
Python Worker
      │
      ├── download exact RenderRequest
      ├── verify request_hash
      ├── verify references
      └── heartbeat lease
      ▼
Create Render Attempt
      │
      ▼
Provider Call
      │
      ├── retryable transport error?
      │       ↓
      │   retry classification
      │
      ├── ambiguous outcome?
      │       ↓
      │   UNKNOWN / reconciliation
      │
      └── definitive response
              ↓
      Artifact Write
              ↓
      Hash Verification
              ↓
      Laravel Checkpoint
              ↓
      CostEntry idempotent accounting
              ↓
      Render SUCCEEDED
              ↓
      Release Claim

Code structure:

Laravel
app/Video/Render/
├── Enums/
│   ├── RenderStatus.php
│   ├── RenderAttemptStatus.php
│   ├── RenderFailureClass.php
│   └── RenderExecutionDisposition.php
│
├── Models/
│   ├── VideoRender.php
│   └── VideoRenderAttempt.php
│
├── DTO/
│   ├── RenderClaim.php
│   ├── RenderCheckpoint.php
│   ├── RenderAttemptUsage.php
│   └── RenderExecutionPayload.php
│
├── StateMachine/
│   ├── RenderStateMachine.php
│   └── InvalidRenderStateTransition.php
│
├── Claims/
│   ├── RenderClaimService.php
│   └── RenderLeaseService.php
│
├── Attempts/
│   └── RenderAttemptService.php
│
├── Cost/
│   └── RenderCostAccountingService.php
│
├── RenderCheckpointService.php
├── RenderDispatchService.php
└── Controllers/
    └── RenderWorkerController.php

Python
media_runtime/
├── orchestration/
│   ├── __init__.py
│   ├── enums.py
│   ├── exceptions.py
│   ├── clock.py
│   ├── retry.py
│   ├── backoff.py
│   ├── lease.py
│   ├── execution.py
│   ├── resume.py
│   ├── fallback.py
│   ├── checkpoint_client.py
│   └── orchestrator.py
│
├── render/
│   └── ... Part 13
│
└── tests/orchestration/
    ├── test_retry_classifier.py
    ├── test_lease.py
    ├── test_resume.py
    ├── test_duplicate_claim.py
    ├── test_ambiguous_provider_outcome.py
    ├── test_cost_idempotency.py
    └── test_orchestrator.py
14.1 Laravel render state

Không dùng:

pending
processing
done

quá thô.

RenderStatus.php:

<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderStatus: string
{
    case QUEUED =
        'queued';

    case CLAIMED =
        'claimed';

    case PREPARING =
        'preparing';

    case SUBMITTING =
        'submitting';

    case SUBMITTED =
        'submitted';

    case PROVIDER_UNKNOWN =
        'provider_unknown';

    case ARTIFACT_WRITING =
        'artifact_writing';

    case CHECKPOINTING =
        'checkpointing';

    case SUCCEEDED =
        'succeeded';

    case RETRY_WAIT =
        'retry_wait';

    case FAILED =
        'failed';

    case CANCELLED =
        'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED => true,

            default => false,
        };
    }

    public function canBeClaimed(): bool
    {
        return match ($this) {
            self::QUEUED,
            self::RETRY_WAIT => true,

            default => false,
        };
    }
}

PROVIDER_UNKNOWN đặc biệt quan trọng.

Nó có nghĩa:

request có thể đã tới provider
nhưng worker không biết provider
đã tạo/tính tiền hay chưa

Không được retry blind.

14.2 Attempt status
<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderAttemptStatus: string
{
    case CREATED =
        'created';

    case PREPARING =
        'preparing';

    case SUBMITTING =
        'submitting';

    case SUBMITTED =
        'submitted';

    case SUCCEEDED =
        'succeeded';

    case RETRYABLE_FAILED =
        'retryable_failed';

    case PERMANENT_FAILED =
        'permanent_failed';

    case AMBIGUOUS =
        'ambiguous';

    case ABANDONED =
        'abandoned';
}
14.3 Failure classification
<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderFailureClass: string
{
    /*
     * Safe to retry same semantic request.
     */
    case TRANSIENT_NETWORK =
        'transient_network';

    case RATE_LIMIT =
        'rate_limit';

    case PROVIDER_5XX =
        'provider_5xx';

    /*
     * Request definitely invalid.
     */
    case AUTHENTICATION =
        'authentication';

    case INVALID_REQUEST =
        'invalid_request';

    case UNSUPPORTED_CAPABILITY =
        'unsupported_capability';

    case ARTIFACT_INTEGRITY =
        'artifact_integrity';

    /*
     * Critical:
     * timeout/disconnect after provider may have
     * accepted the request.
     */
    case AMBIGUOUS_PROVIDER_OUTCOME =
        'ambiguous_provider_outcome';

    case INTERNAL =
        'internal';
}
14.4 Migration video_renders

Nếu bạn đã có renders table từ ERD trước, không tạo table trùng. Thêm các field tương ứng vào table hiện tại.

Tôi dùng video_renders trong code dưới đây để contract rõ ràng; nếu project hiện tại tên là renders, giữ nguyên tên của bạn.

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'renders',
            function (Blueprint $table): void {
                /*
                 * Exact execution identity.
                 */
                $table
                    ->char('request_hash', 64)
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'idempotency_key',
                        190
                    )
                    ->nullable();

                /*
                 * Render state machine.
                 */
                $table
                    ->string(
                        'execution_status',
                        40
                    )
                    ->default('queued')
                    ->index();

                /*
                 * Atomic worker ownership.
                 */
                $table
                    ->uuid('claim_token')
                    ->nullable()
                    ->unique();

                $table
                    ->string(
                        'claimed_by',
                        190
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'claimed_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'lease_expires_at'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->timestampTz(
                        'heartbeat_at'
                    )
                    ->nullable();

                /*
                 * Attempt accounting.
                 */
                $table
                    ->unsignedInteger(
                        'attempt_count'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'max_attempts'
                    )
                    ->default(3);

                $table
                    ->timestampTz(
                        'next_retry_at'
                    )
                    ->nullable()
                    ->index();

                /*
                 * Provider execution identity.
                 */
                $table
                    ->string(
                        'provider_request_id',
                        255
                    )
                    ->nullable()
                    ->index();

                /*
                 * Exact immutable semantic lineage.
                 */
                $table
                    ->uuid(
                        'canonical_concept_revision_id'
                    )
                    ->nullable();

                $table
                    ->char(
                        'canonical_hash',
                        64
                    )
                    ->nullable();

                $table
                    ->char(
                        'projection_hash',
                        64
                    )
                    ->nullable();

                $table
                    ->char(
                        'constraint_set_hash',
                        64
                    )
                    ->nullable();

                $table
                    ->char(
                        'prompt_spec_hash',
                        64
                    )
                    ->nullable();

                $table
                    ->char(
                        'provider_prompt_plan_hash',
                        64
                    )
                    ->nullable();

                $table
                    ->char(
                        'prompt_hash',
                        64
                    )
                    ->nullable();

                /*
                 * Exact request payload sent to Python.
                 * LONGTEXT because byte representation
                 * is useful for hash verification.
                 */
                $table
                    ->longText(
                        'render_request_json'
                    )
                    ->nullable();

                /*
                 * Final result.
                 */
                $table
                    ->json(
                        'artifact_manifest'
                    )
                    ->nullable();

                $table
                    ->char(
                        'primary_artifact_hash',
                        64
                    )
                    ->nullable();

                /*
                 * Failure state.
                 */
                $table
                    ->string(
                        'failure_class',
                        80
                    )
                    ->nullable();

                $table
                    ->string(
                        'failure_code',
                        190
                    )
                    ->nullable();

                $table
                    ->text(
                        'failure_message'
                    )
                    ->nullable();

                /*
                 * Optimistic version for audit.
                 */
                $table
                    ->unsignedInteger(
                        'execution_version'
                    )
                    ->default(0);

                $table
                    ->timestampTz(
                        'execution_started_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'execution_completed_at'
                    )
                    ->nullable();

                $table->unique(
                    [
                        'video_session_id',
                        'idempotency_key',
                    ],
                    'render_session_idempotency_uq'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'renders',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'render_session_idempotency_uq'
                );

                $table->dropColumn([
                    'request_hash',
                    'idempotency_key',
                    'execution_status',
                    'claim_token',
                    'claimed_by',
                    'claimed_at',
                    'lease_expires_at',
                    'heartbeat_at',
                    'attempt_count',
                    'max_attempts',
                    'next_retry_at',
                    'provider_request_id',
                    'canonical_concept_revision_id',
                    'canonical_hash',
                    'projection_hash',
                    'constraint_set_hash',
                    'prompt_spec_hash',
                    'provider_prompt_plan_hash',
                    'prompt_hash',
                    'render_request_json',
                    'artifact_manifest',
                    'primary_artifact_hash',
                    'failure_class',
                    'failure_code',
                    'failure_message',
                    'execution_version',
                    'execution_started_at',
                    'execution_completed_at',
                ]);
            }
        );
    }
};
14.5 Render attempt table

Không overwrite attempt trước.

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'render_attempts',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table
                    ->uuid('render_id');

                $table
                    ->unsignedInteger(
                        'attempt_no'
                    );

                $table
                    ->string(
                        'status',
                        40
                    );

                $table
                    ->string(
                        'provider_key',
                        80
                    );

                $table
                    ->string(
                        'model_key',
                        190
                    );

                $table
                    ->char(
                        'request_hash',
                        64
                    );

                /*
                 * Provider fallback may change
                 * provider/model/request hash.
                 *
                 * base_semantic_hash identifies the
                 * original render intent.
                 */
                $table
                    ->char(
                        'base_semantic_hash',
                        64
                    );

                $table
                    ->uuid(
                        'claim_token'
                    );

                $table
                    ->string(
                        'worker_id',
                        190
                    );

                $table
                    ->string(
                        'provider_request_id',
                        255
                    )
                    ->nullable();

                $table
                    ->string(
                        'failure_class',
                        80
                    )
                    ->nullable();

                $table
                    ->string(
                        'error_code',
                        190
                    )
                    ->nullable();

                $table
                    ->text(
                        'error_message'
                    )
                    ->nullable();

                /*
                 * Transport/HTTP telemetry.
                 */
                $table
                    ->unsignedSmallInteger(
                        'http_status'
                    )
                    ->nullable();

                $table
                    ->unsignedInteger(
                        'latency_ms'
                    )
                    ->nullable();

                /*
                 * Provider usage snapshot.
                 */
                $table
                    ->unsignedBigInteger(
                        'input_tokens'
                    )
                    ->nullable();

                $table
                    ->unsignedBigInteger(
                        'output_tokens'
                    )
                    ->nullable();

                $table
                    ->unsignedBigInteger(
                        'image_input_tokens'
                    )
                    ->nullable();

                $table
                    ->unsignedBigInteger(
                        'text_input_tokens'
                    )
                    ->nullable();

                $table
                    ->decimal(
                        'provider_cost_usd',
                        16,
                        8
                    )
                    ->nullable();

                /*
                 * Link to global financial ledger.
                 */
                $table
                    ->uuid(
                        'cost_entry_id'
                    )
                    ->nullable();

                /*
                 * Artifact result of THIS attempt.
                 */
                $table
                    ->json(
                        'artifact_manifest'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'started_at'
                    );

                $table
                    ->timestampTz(
                        'submitted_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'completed_at'
                    )
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'render_id',
                        'attempt_no',
                    ],
                    'render_attempt_no_uq'
                );

                $table->index(
                    [
                        'render_id',
                        'status',
                    ],
                    'render_attempt_status_idx'
                );

                $table
                    ->foreign('render_id')
                    ->references('id')
                    ->on('renders')
                    ->cascadeOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'render_attempts'
        );
    }
};
14.6 Cost idempotency

Nếu cost_entries hiện tại chưa có external idempotency key, thêm:

Schema::table(
    'cost_entries',
    function (Blueprint $table): void {
        $table
            ->string(
                'source_type',
                80
            )
            ->nullable();

        $table
            ->uuid(
                'source_id'
            )
            ->nullable();

        $table
            ->string(
                'cost_idempotency_key',
                190
            )
            ->nullable();

        $table->unique(
            'cost_idempotency_key',
            'cost_entry_idempotency_uq'
        );
    }
);

Ví dụ:

cost_idempotency_key =
render-attempt:{attempt_id}:provider-usage

Dù callback Python gửi lại hai lần:

CostEntry vẫn chỉ có 1 row.
14.7 Render Model
<?php

declare(strict_types=1);

namespace App\Models;

use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Render extends Model
{
    protected $table =
        'renders';

    protected $guarded = [];

    protected $casts = [
        'execution_status' =>
            RenderStatus::class,

        'failure_class' =>
            RenderFailureClass::class,

        'artifact_manifest' =>
            'array',

        'attempt_count' =>
            'integer',

        'max_attempts' =>
            'integer',

        'execution_version' =>
            'integer',

        'claimed_at' =>
            'immutable_datetime',

        'lease_expires_at' =>
            'immutable_datetime',

        'heartbeat_at' =>
            'immutable_datetime',

        'next_retry_at' =>
            'immutable_datetime',

        'execution_started_at' =>
            'immutable_datetime',

        'execution_completed_at' =>
            'immutable_datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(
            RenderAttempt::class,
            'render_id'
        );
    }

    public function isTerminal(): bool
    {
        return $this
            ->execution_status
            ->isTerminal();
    }
}
14.8 Attempt model
<?php

declare(strict_types=1);

namespace App\Models;

use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RenderAttempt extends Model
{
    use HasUuids;

    protected $table =
        'render_attempts';

    protected $guarded = [];

    protected $casts = [
        'attempt_no' =>
            'integer',

        'status' =>
            RenderAttemptStatus::class,

        'failure_class' =>
            RenderFailureClass::class,

        'artifact_manifest' =>
            'array',

        'input_tokens' =>
            'integer',

        'output_tokens' =>
            'integer',

        'image_input_tokens' =>
            'integer',

        'text_input_tokens' =>
            'integer',

        'provider_cost_usd' =>
            'decimal:8',

        'http_status' =>
            'integer',

        'latency_ms' =>
            'integer',

        'started_at' =>
            'immutable_datetime',

        'submitted_at' =>
            'immutable_datetime',

        'completed_at' =>
            'immutable_datetime',
    ];

    public function render(): BelongsTo
    {
        return $this->belongsTo(
            Render::class,
            'render_id'
        );
    }
}
14.9 State machine
<?php

declare(strict_types=1);

namespace App\Video\Render\StateMachine;

use App\Video\Render\Enums\RenderStatus;

final class RenderStateMachine
{
    /**
     * @var array<string,list<RenderStatus>>
     */
    private const TRANSITIONS = [

        'queued' => [
            RenderStatus::CLAIMED,
            RenderStatus::CANCELLED,
            RenderStatus::FAILED,
        ],

        'claimed' => [
            RenderStatus::PREPARING,
            RenderStatus::QUEUED,
            RenderStatus::FAILED,
            RenderStatus::CANCELLED,
        ],

        'preparing' => [
            RenderStatus::SUBMITTING,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],

        'submitting' => [
            RenderStatus::SUBMITTED,
            RenderStatus::PROVIDER_UNKNOWN,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],

        'submitted' => [
            RenderStatus::ARTIFACT_WRITING,
            RenderStatus::PROVIDER_UNKNOWN,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],

        'provider_unknown' => [
            /*
             * Only reconciliation logic decides.
             */
            RenderStatus::SUBMITTED,
            RenderStatus::ARTIFACT_WRITING,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],

        'artifact_writing' => [
            RenderStatus::CHECKPOINTING,
            RenderStatus::FAILED,
        ],

        'checkpointing' => [
            RenderStatus::SUCCEEDED,
            RenderStatus::FAILED,
        ],

        'retry_wait' => [
            RenderStatus::CLAIMED,
            RenderStatus::FAILED,
            RenderStatus::CANCELLED,
        ],

        'succeeded' => [],

        'failed' => [],

        'cancelled' => [],
    ];

    public function assert(
        RenderStatus $from,
        RenderStatus $to,
    ): void {
        if (
            !in_array(
                $to,
                self::TRANSITIONS[
                    $from->value
                ] ?? [],
                true
            )
        ) {
            throw InvalidRenderStateTransition
                ::between(
                    $from,
                    $to
                );
        }
    }
}

Exception:

<?php

declare(strict_types=1);

namespace App\Video\Render\StateMachine;

use App\Video\Render\Enums\RenderStatus;
use RuntimeException;

final class InvalidRenderStateTransition
    extends RuntimeException
{
    public static function between(
        RenderStatus $from,
        RenderStatus $to,
    ): self {
        return new self(
            sprintf(
                'Invalid render transition %s -> %s.',
                $from->value,
                $to->value
            )
        );
    }
}
14.10 Render claim DTO
<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

use DateTimeImmutable;

final class RenderClaim
{
    public function __construct(
        public readonly string $renderId,
        public readonly string $claimToken,
        public readonly string $workerId,
        public readonly int $attemptNo,
        public readonly DateTimeImmutable $leaseExpiresAt,
        public readonly string $renderRequestJson,
        public readonly string $requestHash,
    ) {
    }
}
14.11 Atomic claim service

Đây là phần quan trọng nhất phía Laravel.

<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\DTO\RenderClaim;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderClaimService
{
    public function __construct(
        private readonly RenderStateMachine $states,
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
        if ($this->leaseSeconds < 30) {
            throw new RuntimeException(
                'Render lease must be at least 30 seconds.'
            );
        }
    }

    public function claimNext(
        string $workerId,
    ): ?RenderClaim {
        return DB::transaction(
            function () use (
                $workerId
            ): ?RenderClaim {
                $now =
                    $this->clock->now();

                /*
                 * SKIP LOCKED is ideal for multiple
                 * render workers.
                 */
                $render =
                    Render::query()
                        ->where(
                            function ($query) use (
                                $now
                            ): void {
                                $query
                                    ->where(
                                        'execution_status',
                                        RenderStatus::QUEUED->value
                                    )
                                    ->orWhere(
                                        function ($retry) use (
                                            $now
                                        ): void {
                                            $retry
                                                ->where(
                                                    'execution_status',
                                                    RenderStatus::RETRY_WAIT->value
                                                )
                                                ->where(
                                                    'next_retry_at',
                                                    '<=',
                                                    $now
                                                );
                                        }
                                    );
                            }
                        )
                        ->where(
                            'attempt_count',
                            '<',
                            DB::raw('max_attempts')
                        )
                        ->orderBy('created_at')
                        ->lock(
                            'for update skip locked'
                        )
                        ->first();

                if ($render === null) {
                    return null;
                }

                $from =
                    $render->execution_status;

                $this->states->assert(
                    $from,
                    RenderStatus::CLAIMED
                );

                $attemptNo =
                    $render->attempt_count + 1;

                $claimToken =
                    (string) Str::uuid();

                $leaseExpiresAt =
                    $now->add(
                        new DateInterval(
                            'PT'
                            . $this->leaseSeconds
                            . 'S'
                        )
                    );

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::CLAIMED,

                    'claim_token' =>
                        $claimToken,

                    'claimed_by' =>
                        $workerId,

                    'claimed_at' =>
                        $now,

                    'heartbeat_at' =>
                        $now,

                    'lease_expires_at' =>
                        $leaseExpiresAt,

                    'attempt_count' =>
                        $attemptNo,

                    'next_retry_at' =>
                        null,

                    'execution_started_at' =>
                        $render
                            ->execution_started_at
                            ?? $now,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                RenderAttempt::query()
                    ->create([
                        'render_id' =>
                            $render->id,

                        'attempt_no' =>
                            $attemptNo,

                        'status' =>
                            RenderAttemptStatus::CREATED,

                        'provider_key' =>
                            $render->provider,

                        'model_key' =>
                            $render->model,

                        'request_hash' =>
                            $render->request_hash,

                        /*
                         * In V1 same exact request.
                         * Part fallback below may create
                         * a new request hash.
                         */
                        'base_semantic_hash' =>
                            $render->request_hash,

                        'claim_token' =>
                            $claimToken,

                        'worker_id' =>
                            $workerId,

                        'started_at' =>
                            $now,
                    ]);

                return new RenderClaim(
                    renderId:
                        $render->id,

                    claimToken:
                        $claimToken,

                    workerId:
                        $workerId,

                    attemptNo:
                        $attemptNo,

                    leaseExpiresAt:
                        $leaseExpiresAt,

                    renderRequestJson:
                        (string)
                        $render->render_request_json,

                    requestHash:
                        (string)
                        $render->request_hash,
                );
            },
            attempts: 3
        );
    }
}
14.12 Vì sao attempt_count tăng lúc claim?

Không đợi tới provider call.

Vì:

worker claim
↓
process crash

đó vẫn là một execution attempt.

Nếu không tăng lúc claim, process có thể chết vô hạn trước call và không bao giờ chạm retry ceiling.

Nhưng sau này ta phân biệt:

execution attempt
vs
provider billable submission

bằng attempt status.

14.13 Lease heartbeat

Worker không được giữ render vô hạn.

<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\Render;
use App\Video\Concept\Support\Clock;
use DateInterval;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderLeaseService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
    }

    public function heartbeat(
        string $renderId,
        string $claimToken,
        string $workerId,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $workerId,
            ): void {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $this->assertOwner(
                    $render,
                    $claimToken,
                    $workerId
                );

                if (
                    $render->isTerminal()
                ) {
                    throw new RuntimeException(
                        'Cannot heartbeat terminal render.'
                    );
                }

                $now =
                    $this->clock->now();

                $render->forceFill([
                    'heartbeat_at' =>
                        $now,

                    'lease_expires_at' =>
                        $now->add(
                            new DateInterval(
                                'PT'
                                . $this->leaseSeconds
                                . 'S'
                            )
                        ),

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();
            }
        );
    }

    public function assertValid(
        string $renderId,
        string $claimToken,
        string $workerId,
    ): Render {
        $render =
            Render::query()
                ->findOrFail(
                    $renderId
                );

        $this->assertOwner(
            $render,
            $claimToken,
            $workerId
        );

        $now =
            $this->clock->now();

        if (
            $render->lease_expires_at === null
            || $render
                ->lease_expires_at
                ->lessThanOrEqualTo(
                    $now
                )
        ) {
            throw new RuntimeException(
                'Render claim lease expired.'
            );
        }

        return $render;
    }

    private function assertOwner(
        Render $render,
        string $claimToken,
        string $workerId,
    ): void {
        if (
            !hash_equals(
                (string)
                $render->claim_token,
                $claimToken
            )
        ) {
            throw new RuntimeException(
                'Render claim token mismatch.'
            );
        }

        if (
            $render->claimed_by
            !== $workerId
        ) {
            throw new RuntimeException(
                'Render worker ownership mismatch.'
            );
        }
    }
}
14.14 Lease recovery

Nếu worker chết:

CLAIMED / PREPARING
+
lease_expires_at < now

render phải được recover.

Nhưng không phải mọi status đều safe requeue.

Safe:

CLAIMED
PREPARING

Potentially unsafe:

SUBMITTING
SUBMITTED

vì provider có thể đã nhận.

Service:

<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;

final class ExpiredRenderLeaseRecovery
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function recover(
        int $limit = 100
    ): int {
        $now =
            $this->clock->now();

        $ids =
            Render::query()
                ->whereNotNull(
                    'lease_expires_at'
                )
                ->where(
                    'lease_expires_at',
                    '<',
                    $now
                )
                ->whereIn(
                    'execution_status',
                    [
                        RenderStatus::CLAIMED->value,
                        RenderStatus::PREPARING->value,
                        RenderStatus::SUBMITTING->value,
                        RenderStatus::SUBMITTED->value,
                    ]
                )
                ->limit(
                    $limit
                )
                ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(
                function () use (
                    $id,
                    $now,
                    &$count,
                ): void {
                    $render =
                        Render::query()
                            ->whereKey($id)
                            ->lockForUpdate()
                            ->first();

                    if (
                        $render === null
                        || $render
                            ->lease_expires_at
                            === null
                        || $render
                            ->lease_expires_at
                            ->greaterThan(
                                $now
                            )
                    ) {
                        return;
                    }

                    $safeBeforeSubmission =
                        in_array(
                            $render
                                ->execution_status,
                            [
                                RenderStatus::CLAIMED,
                                RenderStatus::PREPARING,
                            ],
                            true
                        );

                    $attempt =
                        RenderAttempt::query()
                            ->where(
                                'render_id',
                                $render->id
                            )
                            ->where(
                                'attempt_no',
                                $render->attempt_count
                            )
                            ->lockForUpdate()
                            ->first();

                    if (
                        $safeBeforeSubmission
                    ) {
                        if ($attempt !== null) {
                            $attempt->forceFill([
                                'status' =>
                                    RenderAttemptStatus::ABANDONED,

                                'error_code' =>
                                    'worker_lease_expired',

                                'error_message' =>
                                    'Worker lease expired '
                                    . 'before provider submission.',

                                'completed_at' =>
                                    $now,
                            ])->save();
                        }

                        $render->forceFill([
                            'execution_status' =>
                                RenderStatus::RETRY_WAIT,

                            'claim_token' =>
                                null,

                            'claimed_by' =>
                                null,

                            'claimed_at' =>
                                null,

                            'heartbeat_at' =>
                                null,

                            'lease_expires_at' =>
                                null,

                            'next_retry_at' =>
                                $now,

                            'failure_class' =>
                                RenderFailureClass::TRANSIENT_NETWORK,

                            'failure_code' =>
                                'worker_lease_expired_pre_submit',

                            'execution_version' =>
                                $render
                                    ->execution_version
                                + 1,
                        ])->save();

                        $count++;

                        return;
                    }

                    /*
                     * SUBMITTING or SUBMITTED:
                     * cannot safely assume provider
                     * never received the request.
                     */
                    if ($attempt !== null) {
                        $attempt->forceFill([
                            'status' =>
                                RenderAttemptStatus::AMBIGUOUS,

                            'failure_class' =>
                                RenderFailureClass
                                    ::AMBIGUOUS_PROVIDER_OUTCOME,

                            'error_code' =>
                                'worker_lease_expired_post_submit',

                            'error_message' =>
                                'Worker disappeared after '
                                . 'provider submission may '
                                . 'have occurred.',

                            'completed_at' =>
                                $now,
                        ])->save();
                    }

                    $render->forceFill([
                        'execution_status' =>
                            RenderStatus::PROVIDER_UNKNOWN,

                        'failure_class' =>
                            RenderFailureClass
                                ::AMBIGUOUS_PROVIDER_OUTCOME,

                        'failure_code' =>
                            'provider_outcome_unknown',

                        'claim_token' =>
                            null,

                        'claimed_by' =>
                            null,

                        'claimed_at' =>
                            null,

                        'heartbeat_at' =>
                            null,

                        'lease_expires_at' =>
                            null,

                        'execution_version' =>
                            $render
                                ->execution_version
                            + 1,
                    ])->save();

                    $count++;
                }
            );
        }

        return $count;
    }
}

Đây là một trong các điểm quan trọng nhất của cả render system.

14.15 Không requeue PROVIDER_UNKNOWN tự động

Không:

PROVIDER_UNKNOWN
↓
wait 10 seconds
↓
render lại

Có thể bị charge hai lần.

Phải:

PROVIDER_UNKNOWN
↓
Provider reconciliation
    ├── query request status nếu provider support
    ├── recover output bằng provider_request_id
    ├── inspect callback/webhook
    └── nếu chứng minh request chưa được accepted
            ↓
          retry

Nếu provider không cho reconciliation:

human/policy decision

hoặc controlled duplicate-risk retry.

Không tự giả định.

14.16 Attempt service
<?php

declare(strict_types=1);

namespace App\Video\Render\Attempts;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderAttemptService
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function markPreparing(
        string $renderId,
        string $claimToken,
    ): void {
        $this->transitionAttempt(
            renderId:
                $renderId,

            claimToken:
                $claimToken,

            renderStatus:
                RenderStatus::PREPARING,

            attemptStatus:
                RenderAttemptStatus::PREPARING,
        );
    }

    public function markSubmitting(
        string $renderId,
        string $claimToken,
    ): void {
        $this->transitionAttempt(
            renderId:
                $renderId,

            claimToken:
                $claimToken,

            renderStatus:
                RenderStatus::SUBMITTING,

            attemptStatus:
                RenderAttemptStatus::SUBMITTING,
        );
    }

    public function markSubmitted(
        string $renderId,
        string $claimToken,
        ?string $providerRequestId,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $providerRequestId,
            ): void {
                [$render, $attempt] =
                    $this->lockCurrent(
                        $renderId,
                        $claimToken
                    );

                $now =
                    $this->clock->now();

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::SUBMITTED,

                    'provider_request_id' =>
                        $providerRequestId,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                $attempt->forceFill([
                    'status' =>
                        RenderAttemptStatus::SUBMITTED,

                    'provider_request_id' =>
                        $providerRequestId,

                    'submitted_at' =>
                        $now,
                ])->save();
            }
        );
    }

    public function markAmbiguous(
        string $renderId,
        string $claimToken,
        string $code,
        string $message,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $code,
                $message,
            ): void {
                [$render, $attempt] =
                    $this->lockCurrent(
                        $renderId,
                        $claimToken
                    );

                $now =
                    $this->clock->now();

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::PROVIDER_UNKNOWN,

                    'failure_class' =>
                        RenderFailureClass
                            ::AMBIGUOUS_PROVIDER_OUTCOME,

                    'failure_code' =>
                        $code,

                    'failure_message' =>
                        $message,

                    'claim_token' =>
                        null,

                    'claimed_by' =>
                        null,

                    'lease_expires_at' =>
                        null,

                    'heartbeat_at' =>
                        null,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                $attempt->forceFill([
                    'status' =>
                        RenderAttemptStatus::AMBIGUOUS,

                    'failure_class' =>
                        RenderFailureClass
                            ::AMBIGUOUS_PROVIDER_OUTCOME,

                    'error_code' =>
                        $code,

                    'error_message' =>
                        $message,

                    'completed_at' =>
                        $now,
                ])->save();
            }
        );
    }

    private function transitionAttempt(
        string $renderId,
        string $claimToken,
        RenderStatus $renderStatus,
        RenderAttemptStatus $attemptStatus,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $renderStatus,
                $attemptStatus,
            ): void {
                [$render, $attempt] =
                    $this->lockCurrent(
                        $renderId,
                        $claimToken
                    );

                $render->forceFill([
                    'execution_status' =>
                        $renderStatus,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                $attempt->forceFill([
                    'status' =>
                        $attemptStatus,
                ])->save();
            }
        );
    }

    /**
     * @return array{Render,RenderAttempt}
     */
    private function lockCurrent(
        string $renderId,
        string $claimToken,
    ): array {
        $render =
            Render::query()
                ->whereKey(
                    $renderId
                )
                ->lockForUpdate()
                ->firstOrFail();

        if (
            !hash_equals(
                (string)
                $render->claim_token,
                $claimToken
            )
        ) {
            throw new RuntimeException(
                'Claim token mismatch.'
            );
        }

        $attempt =
            RenderAttempt::query()
                ->where(
                    'render_id',
                    $render->id
                )
                ->where(
                    'attempt_no',
                    $render->attempt_count
                )
                ->lockForUpdate()
                ->firstOrFail();

        return [
            $render,
            $attempt,
        ];
    }
}
14.17 Retry disposition

Không phải mọi exception đều retry.

Python cần classifier deterministic.

orchestration/enums.py:

from __future__ import annotations

from enum import StrEnum


class RetryDisposition(StrEnum):
    RETRY_SAFE = "retry_safe"

    DO_NOT_RETRY = "do_not_retry"

    AMBIGUOUS = "ambiguous"


class FailureClass(StrEnum):
    TRANSIENT_NETWORK = "transient_network"

    RATE_LIMIT = "rate_limit"

    PROVIDER_5XX = "provider_5xx"

    AUTHENTICATION = "authentication"

    INVALID_REQUEST = "invalid_request"

    UNSUPPORTED_CAPABILITY = (
        "unsupported_capability"
    )

    ARTIFACT_INTEGRITY = (
        "artifact_integrity"
    )

    AMBIGUOUS_PROVIDER_OUTCOME = (
        "ambiguous_provider_outcome"
    )

    INTERNAL = "internal"
14.18 Structured provider exception

Part 13 generic ProviderAdapterError chưa đủ.

Bổ sung:

from __future__ import annotations


class ProviderHttpError(
    ProviderAdapterError
):
    def __init__(
        self,
        message: str,
        *,
        http_status: int | None,
        provider_code: str | None = None,
        request_may_have_been_accepted: bool = False,
        retry_after_seconds: float | None = None,
    ) -> None:
        super().__init__(
            message
        )

        self.http_status = http_status

        self.provider_code = (
            provider_code
        )

        self.request_may_have_been_accepted = (
            request_may_have_been_accepted
        )

        self.retry_after_seconds = (
            retry_after_seconds
        )

Transport phải normalize provider-specific error vào exception này.

14.19 Retry classifier

orchestration/retry.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.render.exceptions import (
    ArtifactIntegrityError,
    ProviderHttpError,
    ProviderResponseError,
    RenderRequestError,
)
from media_runtime.orchestration.enums import (
    FailureClass,
    RetryDisposition,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RetryDecision:
    disposition: RetryDisposition

    failure_class: FailureClass

    code: str

    retry_after_seconds: (
        float | None
    ) = None


class RetryClassifier:
    def classify(
        self,
        error: BaseException,
    ) -> RetryDecision:
        if isinstance(
            error,
            RenderRequestError,
        ):
            return RetryDecision(
                disposition=(
                    RetryDisposition.DO_NOT_RETRY
                ),

                failure_class=(
                    FailureClass.INVALID_REQUEST
                ),

                code="render_request_invalid",
            )

        if isinstance(
            error,
            ArtifactIntegrityError,
        ):
            return RetryDecision(
                disposition=(
                    RetryDisposition.DO_NOT_RETRY
                ),

                failure_class=(
                    FailureClass.ARTIFACT_INTEGRITY
                ),

                code="artifact_integrity_failure",
            )

        if isinstance(
            error,
            ProviderResponseError,
        ):
            /*
             * Provider returned a definitive response
             * but body cannot be used.
             *
             * Treat as provider failure, safe retry
             * only if request result is definitely
             * not recoverable. V1 is conservative.
             */
            return RetryDecision(
                disposition=(
                    RetryDisposition.AMBIGUOUS
                ),

                failure_class=(
                    FailureClass
                    .AMBIGUOUS_PROVIDER_OUTCOME
                ),

                code=(
                    "provider_response_unusable"
                ),
            )

        if isinstance(
            error,
            ProviderHttpError,
        ):
            if (
                error
                .request_may_have_been_accepted
            ):
                return RetryDecision(
                    disposition=(
                        RetryDisposition.AMBIGUOUS
                    ),

                    failure_class=(
                        FailureClass
                        .AMBIGUOUS_PROVIDER_OUTCOME
                    ),

                    code=(
                        error.provider_code
                        or "provider_outcome_unknown"
                    ),

                    retry_after_seconds=(
                        error.retry_after_seconds
                    ),
                )

            status = error.http_status

            if status == 429:
                return RetryDecision(
                    disposition=(
                        RetryDisposition.RETRY_SAFE
                    ),

                    failure_class=(
                        FailureClass.RATE_LIMIT
                    ),

                    code=(
                        error.provider_code
                        or "provider_rate_limit"
                    ),

                    retry_after_seconds=(
                        error.retry_after_seconds
                    ),
                )

            if (
                status is not None
                and 500 <= status <= 599
            ):
                return RetryDecision(
                    disposition=(
                        RetryDisposition.RETRY_SAFE
                    ),

                    failure_class=(
                        FailureClass.PROVIDER_5XX
                    ),

                    code=(
                        error.provider_code
                        or "provider_5xx"
                    ),

                    retry_after_seconds=(
                        error.retry_after_seconds
                    ),
                )

            if status in {
                401,
                403,
            }:
                return RetryDecision(
                    disposition=(
                        RetryDisposition.DO_NOT_RETRY
                    ),

                    failure_class=(
                        FailureClass.AUTHENTICATION
                    ),

                    code=(
                        error.provider_code
                        or "provider_authentication"
                    ),
                )

            if (
                status is not None
                and 400 <= status <= 499
            ):
                return RetryDecision(
                    disposition=(
                        RetryDisposition.DO_NOT_RETRY
                    ),

                    failure_class=(
                        FailureClass.INVALID_REQUEST
                    ),

                    code=(
                        error.provider_code
                        or "provider_invalid_request"
                    ),
                )

        if isinstance(
            error,
            (
                TimeoutError,
                ConnectionError,
            ),
        ):
            /*
             * A timeout DURING provider submission
             * cannot be classified here without
             * phase information.
             *
             * Orchestrator will upgrade it to
             * ambiguous when submission has started.
             */
            return RetryDecision(
                disposition=(
                    RetryDisposition.RETRY_SAFE
                ),

                failure_class=(
                    FailureClass.TRANSIENT_NETWORK
                ),

                code="transport_failure",
            )

        return RetryDecision(
            disposition=(
                RetryDisposition.DO_NOT_RETRY
            ),

            failure_class=(
                FailureClass.INTERNAL
            ),

            code="internal_error",
        )
14.20 Retry phase matters

Cùng TimeoutError:

timeout trước HTTP send
→ safe retry

timeout sau request body sent
→ ambiguous

Do đó orchestrator cần execution phase.

from enum import StrEnum


class ExecutionPhase(StrEnum):
    CLAIMED = "claimed"

    PREPARING = "preparing"

    SUBMITTING = "submitting"

    SUBMITTED = "submitted"

    ARTIFACT_WRITING = (
        "artifact_writing"
    )

    CHECKPOINTING = (
        "checkpointing"
    )
14.21 Backoff

orchestration/backoff.py

from __future__ import annotations

import hashlib

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class BackoffPolicy:
    base_seconds: float = 2.0

    multiplier: float = 2.0

    max_seconds: float = 120.0

    jitter_ratio: float = 0.20

    def delay(
        self,
        *,
        attempt_no: int,
        stable_key: str,
        provider_retry_after: (
            float | None
        ) = None,
    ) -> float:
        if provider_retry_after is not None:
            return min(
                max(
                    provider_retry_after,
                    0.0,
                ),
                self.max_seconds,
            )

        raw = min(
            self.base_seconds
            * (
                self.multiplier
                ** max(
                    attempt_no - 1,
                    0,
                )
            ),
            self.max_seconds,
        )

        /*
         * Deterministic jitter:
         * same attempt/key -> same delay.
         *
         * Easier to reproduce in tests.
         */
        digest = hashlib.sha256(
            (
                stable_key
                + ":"
                + str(attempt_no)
            ).encode("utf-8")
        ).digest()

        fraction = int.from_bytes(
            digest[:8],
            "big",
        ) / float(
            2**64 - 1
        )

        jitter = (
            (
                fraction * 2.0
                - 1.0
            )
            * self.jitter_ratio
            * raw
        )

        return max(
            raw + jitter,
            0.0,
        )
14.22 Python checkpoint client contract

Python không query MariaDB.

orchestration/checkpoint_client.py:

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Protocol


@dataclass(
    frozen=True,
    slots=True,
)
class ClaimedRender:
    render_id: str

    claim_token: str

    worker_id: str

    attempt_no: int

    lease_expires_at: str

    request_hash: str

    render_request_json: str


class RenderCheckpointClient(
    Protocol
):
    def claim_next(
        self,
        *,
        worker_id: str,
    ) -> ClaimedRender | None:
        ...

    def heartbeat(
        self,
        *,
        render_id: str,
        claim_token: str,
        worker_id: str,
    ) -> None:
        ...

    def mark_preparing(
        self,
        *,
        render_id: str,
        claim_token: str,
    ) -> None:
        ...

    def mark_submitting(
        self,
        *,
        render_id: str,
        claim_token: str,
    ) -> None:
        ...

    def mark_submitted(
        self,
        *,
        render_id: str,
        claim_token: str,
        provider_request_id: (
            str | None
        ),
    ) -> None:
        ...

    def complete(
        self,
        *,
        render_id: str,
        claim_token: str,
        request_hash: str,
        provider_request_id: (
            str | None
        ),
        artifact_manifest: dict[
            str,
            Any,
        ],
        usage: dict[
            str,
            Any,
        ],
    ) -> None:
        ...

    def retry(
        self,
        *,
        render_id: str,
        claim_token: str,
        failure_class: str,
        error_code: str,
        message: str,
        retry_after_seconds: float,
    ) -> None:
        ...

    def ambiguous(
        self,
        *,
        render_id: str,
        claim_token: str,
        error_code: str,
        message: str,
    ) -> None:
        ...

    def fail(
        self,
        *,
        render_id: str,
        claim_token: str,
        failure_class: str,
        error_code: str,
        message: str,
    ) -> None:
        ...
14.23 Laravel API transport

Python implementation:

from __future__ import annotations

import requests

from typing import Any

from media_runtime.orchestration.checkpoint_client import (
    ClaimedRender,
)


class LaravelRenderCheckpointClient:
    def __init__(
        self,
        *,
        base_url: str,
        worker_token: str,
        timeout_seconds: float = 15.0,
    ) -> None:
        self._base_url = (
            base_url.rstrip("/")
        )

        self._token = worker_token

        self._timeout = (
            timeout_seconds
        )

        self._session = (
            requests.Session()
        )

    def claim_next(
        self,
        *,
        worker_id: str,
    ) -> ClaimedRender | None:
        response = self._post(
            "/api/internal/render-worker/claim",

            {
                "worker_id":
                    worker_id,
            },
        )

        if (
            response.status_code
            == 204
        ):
            return None

        response.raise_for_status()

        data = response.json()

        return ClaimedRender(
            render_id=(
                data["render_id"]
            ),

            claim_token=(
                data["claim_token"]
            ),

            worker_id=worker_id,

            attempt_no=int(
                data["attempt_no"]
            ),

            lease_expires_at=(
                data[
                    "lease_expires_at"
                ]
            ),

            request_hash=(
                data["request_hash"]
            ),

            render_request_json=(
                data[
                    "render_request_json"
                ]
            ),
        )

    def heartbeat(
        self,
        *,
        render_id: str,
        claim_token: str,
        worker_id: str,
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/heartbeat",

            {
                "claim_token":
                    claim_token,

                "worker_id":
                    worker_id,
            },
        )

    def mark_preparing(
        self,
        *,
        render_id: str,
        claim_token: str,
    ) -> None:
        self._checkpoint(
            render_id,
            claim_token,
            "preparing",
        )

    def mark_submitting(
        self,
        *,
        render_id: str,
        claim_token: str,
    ) -> None:
        self._checkpoint(
            render_id,
            claim_token,
            "submitting",
        )

    def mark_submitted(
        self,
        *,
        render_id: str,
        claim_token: str,
        provider_request_id: (
            str | None
        ),
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/submitted",

            {
                "claim_token":
                    claim_token,

                "provider_request_id":
                    provider_request_id,
            },
        )

    def complete(
        self,
        *,
        render_id: str,
        claim_token: str,
        request_hash: str,
        provider_request_id: (
            str | None
        ),
        artifact_manifest: dict[
            str,
            Any,
        ],
        usage: dict[
            str,
            Any,
        ],
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/complete",

            {
                "claim_token":
                    claim_token,

                "request_hash":
                    request_hash,

                "provider_request_id":
                    provider_request_id,

                "artifact_manifest":
                    artifact_manifest,

                "usage":
                    usage,
            },
        )

    def retry(
        self,
        *,
        render_id: str,
        claim_token: str,
        failure_class: str,
        error_code: str,
        message: str,
        retry_after_seconds: float,
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/retry",

            {
                "claim_token":
                    claim_token,

                "failure_class":
                    failure_class,

                "error_code":
                    error_code,

                "message":
                    message,

                "retry_after_seconds":
                    retry_after_seconds,
            },
        )

    def ambiguous(
        self,
        *,
        render_id: str,
        claim_token: str,
        error_code: str,
        message: str,
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/ambiguous",

            {
                "claim_token":
                    claim_token,

                "error_code":
                    error_code,

                "message":
                    message,
            },
        )

    def fail(
        self,
        *,
        render_id: str,
        claim_token: str,
        failure_class: str,
        error_code: str,
        message: str,
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/fail",

            {
                "claim_token":
                    claim_token,

                "failure_class":
                    failure_class,

                "error_code":
                    error_code,

                "message":
                    message,
            },
        )

    def _checkpoint(
        self,
        render_id: str,
        claim_token: str,
        stage: str,
    ) -> None:
        self._post_json(
            f"/api/internal/render-worker/"
            f"{render_id}/checkpoint",

            {
                "claim_token":
                    claim_token,

                "stage":
                    stage,
            },
        )

    def _post_json(
        self,
        path: str,
        payload: dict[
            str,
            Any,
        ],
    ) -> dict[str, Any]:
        response = self._post(
            path,
            payload,
        )

        response.raise_for_status()

        if not response.content:
            return {}

        return response.json()

    def _post(
        self,
        path: str,
        payload: dict[
            str,
            Any,
        ],
    ):
        return self._session.post(
            self._base_url + path,

            json=payload,

            headers={
                "Authorization":
                    "Bearer "
                    + self._token,

                "Accept":
                    "application/json",
            },

            timeout=self._timeout,
        )

Internal worker auth token phải là service secret riêng, không dùng admin session.

14.24 Laravel routes
Route::prefix(
    'internal/render-worker'
)
    ->middleware([
        'render.worker.auth',
        'throttle:render-worker',
    ])
    ->group(
        function (): void {
            Route::post(
                '/claim',
                [
                    RenderWorkerController::class,
                    'claim',
                ]
            );

            Route::post(
                '/{render}/heartbeat',
                [
                    RenderWorkerController::class,
                    'heartbeat',
                ]
            );

            Route::post(
                '/{render}/checkpoint',
                [
                    RenderWorkerController::class,
                    'checkpoint',
                ]
            );

            Route::post(
                '/{render}/submitted',
                [
                    RenderWorkerController::class,
                    'submitted',
                ]
            );

            Route::post(
                '/{render}/complete',
                [
                    RenderWorkerController::class,
                    'complete',
                ]
            );

            Route::post(
                '/{render}/retry',
                [
                    RenderWorkerController::class,
                    'retry',
                ]
            );

            Route::post(
                '/{render}/ambiguous',
                [
                    RenderWorkerController::class,
                    'ambiguous',
                ]
            );

            Route::post(
                '/{render}/fail',
                [
                    RenderWorkerController::class,
                    'fail',
                ]
            );
        }
    );
14.25 Controller claim
public function claim(
    Request $request,
    RenderClaimService $claims,
): Response|JsonResponse {
    $validated =
        $request->validate([
            'worker_id' => [
                'required',
                'string',
                'max:190',
            ],
        ]);

    $claim =
        $claims->claimNext(
            $validated['worker_id']
        );

    if ($claim === null) {
        return response(
            status: 204
        );
    }

    return response()->json([
        'render_id' =>
            $claim->renderId,

        'claim_token' =>
            $claim->claimToken,

        'attempt_no' =>
            $claim->attemptNo,

        'lease_expires_at' =>
            $claim
                ->leaseExpiresAt
                ->format(DATE_ATOM),

        'request_hash' =>
            $claim->requestHash,

        /*
         * Preserve exact stored string.
         */
        'render_request_json' =>
            $claim->renderRequestJson,
    ]);
}
14.26 Important: Python verifies Laravel request hash

Khi nhận claim:

Laravel request_hash
↓
Python parse RenderRequest
↓
RenderRequestHasher.hash()
↓
must equal Laravel request_hash

Nếu không:

FAIL

Không render.

14.27 RenderRequest deserializer

Part 13 chỉ có dataclass. Giờ cần strict hydration.

orchestration/execution.py:

from __future__ import annotations

import json

from media_runtime.render.exceptions import (
    RenderRequestError,
)
from media_runtime.render.request_hash import (
    RenderRequestHasher,
)


class ClaimedRenderRequestLoader:
    def __init__(
        self,
        *,
        hydrator,
        hasher: RenderRequestHasher,
    ) -> None:
        self._hydrator = hydrator
        self._hasher = hasher

    def load(
        self,
        *,
        raw_json: str,
        expected_hash: str,
    ):
        try:
            payload = json.loads(
                raw_json
            )
        except json.JSONDecodeError as exc:
            raise RenderRequestError(
                "Laravel render_request_json "
                "is invalid JSON"
            ) from exc

        request = (
            self._hydrator.hydrate(
                payload
            )
        )

        actual = self._hasher.hash(
            request
        )

        if (
            actual
            != expected_hash
        ):
            raise RenderRequestError(
                "Laravel/Python render request "
                "hash mismatch. "
                f"expected={expected_hash} "
                f"actual={actual}"
            )

        return request

Hydrator phải strict, không cast mơ hồ.

14.28 Heartbeat thread

Provider render có thể mất 30–90s.

Lease 120s nhưng worker vẫn phải heartbeat.

orchestration/lease.py:

from __future__ import annotations

import threading

from dataclasses import dataclass


class LeaseLostError(
    RuntimeError
):
    pass


class LeaseHeartbeat:
    def __init__(
        self,
        *,
        checkpoint_client,
        render_id: str,
        claim_token: str,
        worker_id: str,
        interval_seconds: float = 30.0,
    ) -> None:
        self._client = (
            checkpoint_client
        )

        self._render_id = (
            render_id
        )

        self._claim_token = (
            claim_token
        )

        self._worker_id = worker_id

        self._interval = (
            interval_seconds
        )

        self._stop = (
            threading.Event()
        )

        self._failed = (
            threading.Event()
        )

        self._error: (
            BaseException | None
        ) = None

        self._thread: (
            threading.Thread | None
        ) = None

    def start(
        self,
    ) -> None:
        if self._thread is not None:
            raise RuntimeError(
                "Heartbeat already started"
            )

        self._thread = threading.Thread(
            target=self._run,

            name=(
                "render-heartbeat:"
                + self._render_id
            ),

            daemon=True,
        )

        self._thread.start()

    def stop(
        self,
    ) -> None:
        self._stop.set()

        if self._thread is not None:
            self._thread.join(
                timeout=(
                    self._interval + 5
                )
            )

    def assert_alive(
        self,
    ) -> None:
        if self._failed.is_set():
            raise LeaseLostError(
                "Render lease heartbeat failed"
            ) from self._error

    def _run(
        self,
    ) -> None:
        while not self._stop.wait(
            self._interval
        ):
            try:
                self._client.heartbeat(
                    render_id=(
                        self._render_id
                    ),

                    claim_token=(
                        self._claim_token
                    ),

                    worker_id=(
                        self._worker_id
                    ),
                )

            except BaseException as exc:
                self._error = exc
                self._failed.set()

                /*
                 * Do not continue provider-side
                 * operations after ownership may
                 * have been lost.
                 */
                return

    def __enter__(
        self,
    ) -> "LeaseHeartbeat":
        self.start()
        return self

    def __exit__(
        self,
        exc_type,
        exc,
        tb,
    ) -> None:
        self.stop()
14.29 Một nuance quan trọng với heartbeat

Nếu heartbeat fail trước provider call:

abort safely

Nếu heartbeat fail sau provider submission bắt đầu:

không được tự retry

vì ownership DB có thể đã mất trong lúc provider vẫn xử lý.

Orchestrator phải track phase.

14.30 Render execution outcome
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.render.result import (
    RenderResult,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ExecutionOutcome:
    render_id: str

    attempt_no: int

    succeeded: bool

    result: RenderResult | None

    terminal: bool

    ambiguous: bool
14.31 Tách provider execution khỏi Part13 ImageRenderService

Part 13 ImageRenderService đang tự:

validate
idempotency local ledger
provider call
artifact write

Ở Part 14 production, bỏ local find_by_request_hash() ra khỏi quyết định concurrency chính.

Laravel claim là authoritative.

Ta refactor Part13 service thành:

SingleAttemptImageExecutor

Nó thực thi đúng một provider attempt.

14.32 SingleAttemptImageExecutor
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.render.providers.registry import (
    ImageProviderRegistry,
)
from media_runtime.render.request import (
    RenderRequest,
)
from media_runtime.render.validator import (
    RenderRequestValidator,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SingleAttemptProviderResult:
    request_hash: str

    provider_response: object


class SingleAttemptImageExecutor:
    """
    Executes exactly ONE provider attempt.

    No semantic retry.
    No provider fallback.
    No Laravel state management.
    """

    def __init__(
        self,
        *,
        validator: RenderRequestValidator,
        request_hasher,
        providers: ImageProviderRegistry,
    ) -> None:
        self._validator = validator

        self._hasher = (
            request_hasher
        )

        self._providers = providers

    def submit(
        self,
        request: RenderRequest,
    ) -> SingleAttemptProviderResult:
        self._validator.validate(
            request
        )

        request.references.verify_files()

        request_hash = (
            self._hasher.hash(
                request
            )
        )

        provider = (
            self._providers.get(
                provider_key=(
                    request.provider_key
                ),

                model_key=(
                    request.model_key
                ),
            )
        )

        response = provider.render(
            request
        )

        return SingleAttemptProviderResult(
            request_hash=request_hash,

            provider_response=response,
        )

Artifact persistence cũng tách khỏi provider call.

14.33 Artifact persister
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

from media_runtime.render.artifacts.writer import (
    ArtifactWriter,
)
from media_runtime.render.enums import (
    ArtifactKind,
)


@dataclass(
    frozen=True,
    slots=True,
)
class PersistedAttemptArtifacts:
    artifacts: tuple

    manifest: dict[
        str,
        Any,
    ]


class RenderAttemptArtifactPersister:
    def __init__(
        self,
        writer: ArtifactWriter,
    ) -> None:
        self._writer = writer

    def persist(
        self,
        *,
        request,
        request_hash: str,
        provider_response,
        attempt_no: int,
    ) -> PersistedAttemptArtifacts:
        base = Path(
            request.session_code,
            request.run_id,
            "renders",
            request.render_id,
            f"attempt_{attempt_no:03d}",
        )

        artifacts = []

        artifacts.append(
            self._writer.write_text(
                relative_path=(
                    base
                    / "compiled_prompt.txt"
                ),

                value=(
                    request
                    .compiled_prompt
                    .prompt
                ),

                artifact_id=(
                    f"{request.render_id}:"
                    f"a{attempt_no}:prompt"
                ),

                kind=(
                    ArtifactKind.PROMPT
                ),

                request_hash=(
                    request_hash
                ),
            )
        )

        if (
            request
            .compiled_prompt
            .negative_prompt
        ):
            artifacts.append(
                self._writer.write_text(
                    relative_path=(
                        base
                        / "negative_prompt.txt"
                    ),

                    value=(
                        request
                        .compiled_prompt
                        .negative_prompt
                    ),

                    artifact_id=(
                        f"{request.render_id}:"
                        f"a{attempt_no}:negative"
                    ),

                    kind=(
                        ArtifactKind
                        .NEGATIVE_PROMPT
                    ),

                    request_hash=(
                        request_hash
                    ),
                )
            )

        response_artifact = (
            self._writer.write_json(
                relative_path=(
                    base
                    / "response.json"
                ),

                value=(
                    provider_response
                    .raw_response
                ),

                artifact_id=(
                    f"{request.render_id}:"
                    f"a{attempt_no}:response"
                ),

                kind=(
                    ArtifactKind.RESPONSE
                ),

                request_hash=(
                    request_hash
                ),
            )
        )

        artifacts.append(
            response_artifact
        )

        generated_artifacts = []

        for index, generated in (
            enumerate(
                provider_response
                .artifacts
            )
        ):
            extension = (
                self._extension(
                    generated.mime_type
                )
            )

            artifact = (
                self._writer
                .write_bytes(
                    relative_path=(
                        base
                        / (
                            f"output_{index:03d}."
                            f"{extension}"
                        )
                    ),

                    data=(
                        generated.content
                    ),

                    artifact_id=(
                        f"{request.render_id}:"
                        f"a{attempt_no}:"
                        f"image:{index}"
                    ),

                    kind=(
                        ArtifactKind
                        .GENERATED_IMAGE
                    ),

                    mime_type=(
                        generated.mime_type
                    ),

                    request_hash=(
                        request_hash
                    ),

                    ordinal=index,
                )
            )

            artifacts.append(
                artifact
            )

            generated_artifacts.append(
                artifact
            )

        manifest = {
            "version":
                "render-attempt-manifest-v1",

            "render_id":
                request.render_id,

            "attempt_no":
                attempt_no,

            "request_hash":
                request_hash,

            "provider_key":
                provider_response
                .provider_key,

            "model_key":
                provider_response
                .model_key,

            "provider_request_id":
                provider_response
                .provider_request_id,

            "canonical_hash":
                request
                .source_canonical_hash,

            "projection_hash":
                request
                .projection_hash,

            "constraint_set_hash":
                request
                .constraint_set_hash,

            "prompt_spec_hash":
                request
                .prompt_spec_hash,

            "prompt_hash":
                request
                .compiled_prompt
                .prompt_hash,

            "artifacts": [
                {
                    "artifact_id":
                        artifact.artifact_id,

                    "kind":
                        artifact.kind.value,

                    "sha256":
                        artifact.sha256,

                    "size_bytes":
                        artifact.size_bytes,

                    "mime_type":
                        artifact.mime_type,

                    /*
                     * Prefer storage-relative path
                     * in final implementation.
                     */
                    "path":
                        str(
                            artifact.path
                        ),
                }
                for artifact in artifacts
            ],
        }

        manifest_artifact = (
            self._writer.write_json(
                relative_path=(
                    base
                    / "manifest.json"
                ),

                value=manifest,

                artifact_id=(
                    f"{request.render_id}:"
                    f"a{attempt_no}:manifest"
                ),

                kind=(
                    ArtifactKind.MANIFEST
                ),

                request_hash=(
                    request_hash
                ),
            )
        )

        artifacts.append(
            manifest_artifact
        )

        manifest[
            "manifest_artifact_hash"
        ] = manifest_artifact.sha256

        return PersistedAttemptArtifacts(
            artifacts=tuple(
                artifacts
            ),

            manifest=manifest,
        )

    @staticmethod
    def _extension(
        mime_type: str,
    ) -> str:
        mapping = {
            "image/png":
                "png",

            "image/jpeg":
                "jpg",

            "image/webp":
                "webp",
        }

        try:
            return mapping[
                mime_type
            ]
        except KeyError as exc:
            raise RuntimeError(
                "Unsupported image MIME: "
                + mime_type
            ) from exc
14.34 Resume problem lớn nhất

Có các crash window:

A. claim
   ↓ crash
   provider chưa gọi

B. mark SUBMITTING
   ↓ crash
   provider chưa gọi

C. provider nhận request
   ↓ crash/network disconnect
   chưa có provider_request_id

D. provider response nhận được
   ↓ output files đã write
   ↓ crash trước Laravel complete()

E. Laravel complete()
   ↓ response HTTP mất
   worker tưởng chưa complete

Part 14 phải xử lý từng window khác nhau.

14.35 Window A — safe retry
status CLAIMED
lease expired
attempt = CREATED

Safe:

attempt ABANDONED
render RETRY_WAIT
14.36 Window B — SUBMITTING là conservative ambiguous

Ta không biết request có rời process hay chưa.

Do đó:

SUBMITTING + worker disappears
→ PROVIDER_UNKNOWN

Không safe retry.

Nếu muốn giảm ambiguous window sau này, provider transport có thể expose:

on_request_body_started
on_response_headers

nhưng không cần V1.

14.37 Window D — local artifact recovery

Nếu provider response đã được persist vào attempt folder nhưng Laravel chưa complete:

Python process restart
↓
local manifest exists
↓
verify artifact hashes
↓
call Laravel complete()
↓
DO NOT call provider again

Đây là resume quan trọng.

14.38 Local attempt journal

Ta cần journal nhỏ trên Python.

orchestration/resume.py:

from __future__ import annotations

import json
import os
import tempfile

from dataclasses import dataclass
from pathlib import Path
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class LocalAttemptJournal:
    render_id: str

    attempt_no: int

    request_hash: str

    phase: str

    provider_request_id: (
        str | None
    )

    artifact_manifest: (
        dict[str, Any] | None
    )


class AttemptJournalStore:
    def __init__(
        self,
        root: Path,
    ) -> None:
        self._root = root

    def load(
        self,
        *,
        render_id: str,
        attempt_no: int,
    ) -> LocalAttemptJournal | None:
        path = self._path(
            render_id,
            attempt_no,
        )

        if not path.exists():
            return None

        data = json.loads(
            path.read_text(
                encoding="utf-8"
            )
        )

        return LocalAttemptJournal(
            render_id=(
                data["render_id"]
            ),

            attempt_no=int(
                data["attempt_no"]
            ),

            request_hash=(
                data["request_hash"]
            ),

            phase=data["phase"],

            provider_request_id=(
                data.get(
                    "provider_request_id"
                )
            ),

            artifact_manifest=(
                data.get(
                    "artifact_manifest"
                )
            ),
        )

    def save(
        self,
        journal: LocalAttemptJournal,
    ) -> None:
        path = self._path(
            journal.render_id,
            journal.attempt_no,
        )

        path.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        payload = {
            "render_id":
                journal.render_id,

            "attempt_no":
                journal.attempt_no,

            "request_hash":
                journal.request_hash,

            "phase":
                journal.phase,

            "provider_request_id":
                journal.provider_request_id,

            "artifact_manifest":
                journal.artifact_manifest,
        }

        raw = json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        with tempfile.NamedTemporaryFile(
            mode="wb",
            dir=path.parent,
            delete=False,
            prefix=".journal-",
        ) as handle:
            temp = Path(
                handle.name
            )

            handle.write(raw)
            handle.flush()
            os.fsync(
                handle.fileno()
            )

        os.replace(
            temp,
            path
        )

    def delete(
        self,
        *,
        render_id: str,
        attempt_no: int,
    ) -> None:
        self._path(
            render_id,
            attempt_no,
        ).unlink(
            missing_ok=True
        )

    def _path(
        self,
        render_id: str,
        attempt_no: int,
    ) -> Path:
        return (
            self._root
            / "journals"
            / render_id
            / (
                f"attempt_"
                f"{attempt_no:03d}.json"
            )
        )

Local journal không thay Laravel DB.

Nó chỉ giúp recover output đã có.

14.39 Journal phases
class JournalPhase(StrEnum):
    CLAIMED = "claimed"

    PREPARED = "prepared"

    SUBMITTING = "submitting"

    PROVIDER_RESPONSE_RECEIVED = (
        "provider_response_received"
    )

    ARTIFACTS_PERSISTED = (
        "artifacts_persisted"
    )

    LARAVEL_COMPLETED = (
        "laravel_completed"
    )
14.40 Provider response memory crash issue

Nếu provider response nhận xong nhưng process chết trước artifact write:

provider may have charged
output lost

Không có magic fix nếu provider không hỗ trợ retrieve-by-request-id.

Do đó ngay sau response nhận:

1. persist raw/output bytes locally
2. fsync
3. THEN do further processing

Tức là provider_response_received journal alone chưa đủ.

Provider response artifacts phải được ghi càng sớm càng tốt.

14.41 Attempt execution record
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.orchestration.enums import (
    ExecutionPhase,
)


@dataclass(
    slots=True
)
class AttemptExecutionContext:
    render_id: str

    attempt_no: int

    claim_token: str

    worker_id: str

    request_hash: str

    phase: ExecutionPhase

    provider_request_id: (
        str | None
    ) = None

Mutable context okay vì đây là runtime execution state, không semantic artifact.

14.42 Orchestrator

Đây là file chính của Part 14.

orchestration/orchestrator.py:

from __future__ import annotations

import traceback

from media_runtime.orchestration.backoff import (
    BackoffPolicy,
)
from media_runtime.orchestration.checkpoint_client import (
    ClaimedRender,
)
from media_runtime.orchestration.enums import (
    ExecutionPhase,
    RetryDisposition,
)
from media_runtime.orchestration.execution import (
    AttemptExecutionContext,
)
from media_runtime.orchestration.lease import (
    LeaseHeartbeat,
    LeaseLostError,
)
from media_runtime.orchestration.resume import (
    AttemptJournalStore,
    JournalPhase,
    LocalAttemptJournal,
)
from media_runtime.orchestration.retry import (
    RetryClassifier,
)
from media_runtime.render.exceptions import (
    ProviderHttpError,
)


class RenderOrchestrator:
    def __init__(
        self,
        *,
        worker_id: str,
        checkpoint_client,
        request_loader,
        single_attempt_executor,
        artifact_persister,
        retry_classifier: RetryClassifier,
        backoff_policy: BackoffPolicy,
        journal_store: AttemptJournalStore,
        heartbeat_interval_seconds: float = 30.0,
    ) -> None:
        self._worker_id = worker_id

        self._checkpoint = (
            checkpoint_client
        )

        self._request_loader = (
            request_loader
        )

        self._executor = (
            single_attempt_executor
        )

        self._artifacts = (
            artifact_persister
        )

        self._retry_classifier = (
            retry_classifier
        )

        self._backoff = (
            backoff_policy
        )

        self._journals = (
            journal_store
        )

        self._heartbeat_interval = (
            heartbeat_interval_seconds
        )

    def run_once(
        self,
    ) -> bool:
        claim = (
            self._checkpoint
            .claim_next(
                worker_id=(
                    self._worker_id
                )
            )
        )

        if claim is None:
            return False

        self._execute_claim(
            claim
        )

        return True

    def _execute_claim(
        self,
        claim: ClaimedRender,
    ) -> None:
        context = (
            AttemptExecutionContext(
                render_id=(
                    claim.render_id
                ),

                attempt_no=(
                    claim.attempt_no
                ),

                claim_token=(
                    claim.claim_token
                ),

                worker_id=(
                    claim.worker_id
                ),

                request_hash=(
                    claim.request_hash
                ),

                phase=(
                    ExecutionPhase.CLAIMED
                ),
            )
        )

        journal = (
            self._journals.load(
                render_id=(
                    claim.render_id
                ),

                attempt_no=(
                    claim.attempt_no
                ),
            )
        )

        /*
         * Same Laravel attempt claimed again should
         * normally not happen because attempt_no
         * increments on reclaim.
         *
         * But if local journal exists, verify lineage.
         */
        if journal is not None:
            if (
                journal.request_hash
                != claim.request_hash
            ):
                self._checkpoint.fail(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),

                    failure_class=(
                        "internal"
                    ),

                    error_code=(
                        "journal_request_hash_mismatch"
                    ),

                    message=(
                        "Local attempt journal "
                        "belongs to different request."
                    ),
                )

                return

            if (
                journal.phase
                == JournalPhase.ARTIFACTS_PERSISTED
                and journal.artifact_manifest
                is not None
            ):
                self._resume_checkpoint_only(
                    claim=claim,
                    journal=journal,
                )

                return

        self._journals.save(
            LocalAttemptJournal(
                render_id=(
                    claim.render_id
                ),

                attempt_no=(
                    claim.attempt_no
                ),

                request_hash=(
                    claim.request_hash
                ),

                phase=(
                    JournalPhase.CLAIMED
                ),

                provider_request_id=None,

                artifact_manifest=None,
            )
        )

        with LeaseHeartbeat(
            checkpoint_client=(
                self._checkpoint
            ),

            render_id=(
                claim.render_id
            ),

            claim_token=(
                claim.claim_token
            ),

            worker_id=(
                self._worker_id
            ),

            interval_seconds=(
                self._heartbeat_interval
            ),
        ) as heartbeat:
            try:
                self._checkpoint
                .mark_preparing(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),
                )

                context.phase = (
                    ExecutionPhase.PREPARING
                )

                heartbeat.assert_alive()

                request = (
                    self._request_loader.load(
                        raw_json=(
                            claim
                            .render_request_json
                        ),

                        expected_hash=(
                            claim.request_hash
                        ),
                    )
                )

                /*
                 * Render ID from immutable request must
                 * match claimed DB row.
                 */
                if (
                    request.render_id
                    != claim.render_id
                ):
                    raise RuntimeError(
                        "Claim/render request ID mismatch."
                    )

                self._journals.save(
                    LocalAttemptJournal(
                        render_id=(
                            claim.render_id
                        ),

                        attempt_no=(
                            claim.attempt_no
                        ),

                        request_hash=(
                            claim.request_hash
                        ),

                        phase=(
                            JournalPhase.PREPARED
                        ),

                        provider_request_id=None,

                        artifact_manifest=None,
                    )
                )

                heartbeat.assert_alive()

                /*
                 * Critical boundary:
                 * From this point a transport failure
                 * may become ambiguous.
                 */
                self._checkpoint
                    .mark_submitting(
                        render_id=(
                            claim.render_id
                        ),

                        claim_token=(
                            claim.claim_token
                        ),
                    )

                context.phase = (
                    ExecutionPhase.SUBMITTING
                )

                self._journals.save(
                    LocalAttemptJournal(
                        render_id=(
                            claim.render_id
                        ),

                        attempt_no=(
                            claim.attempt_no
                        ),

                        request_hash=(
                            claim.request_hash
                        ),

                        phase=(
                            JournalPhase.SUBMITTING
                        ),

                        provider_request_id=None,

                        artifact_manifest=None,
                    )
                )

                heartbeat.assert_alive()

                provider_result = (
                    self._executor.submit(
                        request
                    )
                )

                response = (
                    provider_result
                    .provider_response
                )

                context.phase = (
                    ExecutionPhase.SUBMITTED
                )

                context.provider_request_id = (
                    response
                    .provider_request_id
                )

                /*
                 * Checkpoint provider identity ASAP.
                 */
                self._checkpoint
                    .mark_submitted(
                        render_id=(
                            claim.render_id
                        ),

                        claim_token=(
                            claim.claim_token
                        ),

                        provider_request_id=(
                            response
                            .provider_request_id
                        ),
                    )

                heartbeat.assert_alive()

                /*
                 * Persist output before any optional
                 * downstream transformation.
                 */
                persisted = (
                    self._artifacts.persist(
                        request=request,

                        request_hash=(
                            claim.request_hash
                        ),

                        provider_response=(
                            response
                        ),

                        attempt_no=(
                            claim.attempt_no
                        ),
                    )
                )

                context.phase = (
                    ExecutionPhase
                    .ARTIFACT_WRITING
                )

                self._journals.save(
                    LocalAttemptJournal(
                        render_id=(
                            claim.render_id
                        ),

                        attempt_no=(
                            claim.attempt_no
                        ),

                        request_hash=(
                            claim.request_hash
                        ),

                        phase=(
                            JournalPhase
                            .ARTIFACTS_PERSISTED
                        ),

                        provider_request_id=(
                            response
                            .provider_request_id
                        ),

                        artifact_manifest=(
                            persisted.manifest
                        ),
                    )
                )

                heartbeat.assert_alive()

                context.phase = (
                    ExecutionPhase.CHECKPOINTING
                )

                self._checkpoint.complete(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),

                    request_hash=(
                        claim.request_hash
                    ),

                    provider_request_id=(
                        response
                        .provider_request_id
                    ),

                    artifact_manifest=(
                        persisted.manifest
                    ),

                    usage=(
                        self._usage_dict(
                            response.usage
                        )
                    ),
                )

                self._journals.save(
                    LocalAttemptJournal(
                        render_id=(
                            claim.render_id
                        ),

                        attempt_no=(
                            claim.attempt_no
                        ),

                        request_hash=(
                            claim.request_hash
                        ),

                        phase=(
                            JournalPhase
                            .LARAVEL_COMPLETED
                        ),

                        provider_request_id=(
                            response
                            .provider_request_id
                        ),

                        artifact_manifest=(
                            persisted.manifest
                        ),
                    )
                )

                /*
                 * Journal no longer needed once DB
                 * authoritative checkpoint committed.
                 */
                self._journals.delete(
                    render_id=(
                        claim.render_id
                    ),

                    attempt_no=(
                        claim.attempt_no
                    ),
                )

            except BaseException as exc:
                self._handle_failure(
                    claim=claim,

                    context=context,

                    error=exc,
                )

    def _handle_failure(
        self,
        *,
        claim: ClaimedRender,
        context: AttemptExecutionContext,
        error: BaseException,
    ) -> None:
        decision = (
            self._retry_classifier
            .classify(
                error
            )
        )

        /*
         * Once submission boundary was crossed,
         * generic network/lease loss becomes
         * ambiguous unless transport explicitly
         * proves request wasn't accepted.
         */
        submission_started = (
            context.phase
            in {
                ExecutionPhase.SUBMITTING,
                ExecutionPhase.SUBMITTED,
                ExecutionPhase.ARTIFACT_WRITING,
                ExecutionPhase.CHECKPOINTING,
            }
        )

        if (
            submission_started
            and isinstance(
                error,
                (
                    TimeoutError,
                    ConnectionError,
                    LeaseLostError,
                ),
            )
        ):
            decision = (
                type(decision)(
                    disposition=(
                        RetryDisposition.AMBIGUOUS
                    ),

                    failure_class=(
                        decision
                        .failure_class
                        .AMBIGUOUS_PROVIDER_OUTCOME
                    ),

                    code=(
                        "provider_submission_"
                        "outcome_unknown"
                    ),

                    retry_after_seconds=None,
                )
            )

        message = (
            f"{type(error).__name__}: "
            f"{error}"
        )

        if (
            decision.disposition
            == RetryDisposition.AMBIGUOUS
        ):
            self._checkpoint
                .ambiguous(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),

                    error_code=(
                        decision.code
                    ),

                    message=message,
                )

            return

        if (
            decision.disposition
            == RetryDisposition.RETRY_SAFE
        ):
            delay = (
                self._backoff.delay(
                    attempt_no=(
                        claim.attempt_no
                    ),

                    stable_key=(
                        claim.request_hash
                    ),

                    provider_retry_after=(
                        decision
                        .retry_after_seconds
                    ),
                )
            )

            self._checkpoint.retry(
                render_id=(
                    claim.render_id
                ),

                claim_token=(
                    claim.claim_token
                ),

                failure_class=(
                    decision
                    .failure_class
                    .value
                ),

                error_code=(
                    decision.code
                ),

                message=message,

                retry_after_seconds=(
                    delay
                ),
            )

            return

        self._checkpoint.fail(
            render_id=(
                claim.render_id
            ),

            claim_token=(
                claim.claim_token
            ),

            failure_class=(
                decision
                .failure_class
                .value
            ),

            error_code=(
                decision.code
            ),

            message=message,
        )

    def _resume_checkpoint_only(
        self,
        *,
        claim: ClaimedRender,
        journal: LocalAttemptJournal,
    ) -> None:
        /*
         * No provider call.
         *
         * The rendered artifacts already exist.
         * Only complete the authoritative Laravel
         * checkpoint.
         */
        self._checkpoint.complete(
            render_id=(
                claim.render_id
            ),

            claim_token=(
                claim.claim_token
            ),

            request_hash=(
                claim.request_hash
            ),

            provider_request_id=(
                journal.provider_request_id
            ),

            artifact_manifest=(
                journal.artifact_manifest
                or {}
            ),

            /*
             * Usage should ideally also be persisted
             * in journal. Added below.
             */
            usage={},
        )

        self._journals.delete(
            render_id=(
                claim.render_id
            ),

            attempt_no=(
                claim.attempt_no
            ),
        )

    @staticmethod
    def _usage_dict(
        usage,
    ) -> dict:
        return {
            "input_tokens":
                usage.input_tokens,

            "output_tokens":
                usage.output_tokens,

            "image_input_tokens":
                usage.image_input_tokens,

            "text_input_tokens":
                usage.text_input_tokens,

            "provider_cost_usd":
                usage.provider_cost_usd,
        }

Có một bug thiết kế nhỏ trong _handle_failure ở đoạn:

decision.failure_class.AMBIGUOUS_PROVIDER_OUTCOME

không nên dùng enum instance như namespace.

Sửa final:

from media_runtime.orchestration.enums import (
    FailureClass,
)

decision = RetryDecision(
    disposition=(
        RetryDisposition.AMBIGUOUS
    ),

    failure_class=(
        FailureClass
        .AMBIGUOUS_PROVIDER_OUTCOME
    ),

    code=(
        "provider_submission_outcome_unknown"
    ),
)

Đây là bản phải dùng.

14.43 Journal cần giữ Usage

Để Window D resume không mất cost telemetry, sửa journal:

@dataclass(
    frozen=True,
    slots=True,
)
class LocalAttemptJournal:
    render_id: str

    attempt_no: int

    request_hash: str

    phase: str

    provider_request_id: (
        str | None
    )

    artifact_manifest: (
        dict[str, Any] | None
    )

    usage: (
        dict[str, Any] | None
    )

Khi artifact persisted:

usage=(
    self._usage_dict(
        response.usage
    )
),

Resume:

usage=(
    journal.usage
    or {}
),

save()/load() thêm field tương ứng.

14.44 Laravel retry endpoint
public function retry(
    Request $request,
    Render $render,
    RenderRetryService $service,
): JsonResponse {
    $data =
        $request->validate([
            'claim_token' => [
                'required',
                'uuid',
            ],

            'failure_class' => [
                'required',
                'string',
            ],

            'error_code' => [
                'required',
                'string',
                'max:190',
            ],

            'message' => [
                'required',
                'string',
                'max:5000',
            ],

            'retry_after_seconds' => [
                'required',
                'numeric',
                'min:0',
                'max:3600',
            ],
        ]);

    $service->schedule(
        renderId:
            $render->id,

        claimToken:
            $data['claim_token'],

        failureClass:
            RenderFailureClass::from(
                $data['failure_class']
            ),

        errorCode:
            $data['error_code'],

        message:
            $data['message'],

        retryAfterSeconds:
            (float)
            $data['retry_after_seconds'],
    );

    return response()->json([
        'ok' => true,
    ]);
}
14.45 Retry service
<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderRetryService
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function schedule(
        string $renderId,
        string $claimToken,
        RenderFailureClass $failureClass,
        string $errorCode,
        string $message,
        float $retryAfterSeconds,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $failureClass,
                $errorCode,
                $message,
                $retryAfterSeconds,
            ): void {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    !hash_equals(
                        (string)
                        $render->claim_token,
                        $claimToken
                    )
                ) {
                    throw new RuntimeException(
                        'Claim token mismatch.'
                    );
                }

                $attempt =
                    RenderAttempt::query()
                        ->where(
                            'render_id',
                            $render->id
                        )
                        ->where(
                            'attempt_no',
                            $render->attempt_count
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $now =
                    $this->clock->now();

                /*
                 * Maximum attempts reached.
                 */
                if (
                    $render->attempt_count
                    >= $render->max_attempts
                ) {
                    $attempt->forceFill([
                        'status' =>
                            RenderAttemptStatus
                                ::PERMANENT_FAILED,

                        'failure_class' =>
                            $failureClass,

                        'error_code' =>
                            $errorCode,

                        'error_message' =>
                            $message,

                        'completed_at' =>
                            $now,
                    ])->save();

                    $render->forceFill([
                        'execution_status' =>
                            RenderStatus::FAILED,

                        'failure_class' =>
                            $failureClass,

                        'failure_code' =>
                            'retry_budget_exhausted',

                        'failure_message' =>
                            $message,

                        'claim_token' =>
                            null,

                        'claimed_by' =>
                            null,

                        'heartbeat_at' =>
                            null,

                        'lease_expires_at' =>
                            null,

                        'execution_completed_at' =>
                            $now,

                        'execution_version' =>
                            $render
                                ->execution_version
                            + 1,
                    ])->save();

                    return;
                }

                $attempt->forceFill([
                    'status' =>
                        RenderAttemptStatus
                            ::RETRYABLE_FAILED,

                    'failure_class' =>
                        $failureClass,

                    'error_code' =>
                        $errorCode,

                    'error_message' =>
                        $message,

                    'completed_at' =>
                        $now,
                ])->save();

                $nextRetryAt =
                    CarbonImmutable::instance(
                        $now
                    )->addMilliseconds(
                        (int) round(
                            $retryAfterSeconds
                            * 1000
                        )
                    );

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::RETRY_WAIT,

                    'failure_class' =>
                        $failureClass,

                    'failure_code' =>
                        $errorCode,

                    'failure_message' =>
                        $message,

                    'next_retry_at' =>
                        $nextRetryAt,

                    'claim_token' =>
                        null,

                    'claimed_by' =>
                        null,

                    'claimed_at' =>
                        null,

                    'heartbeat_at' =>
                        null,

                    'lease_expires_at' =>
                        null,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();
            }
        );
    }
}
14.46 Permanent failure service
<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderFailureService
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function fail(
        string $renderId,
        string $claimToken,
        RenderFailureClass $failureClass,
        string $errorCode,
        string $message,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $failureClass,
                $errorCode,
                $message,
            ): void {
                $render =
                    Render::query()
                        ->whereKey($renderId)
                        ->lockForUpdate()
                        ->firstOrFail();

                /*
                 * Complete callback replay after
                 * terminal state should not mutate.
                 */
                if ($render->isTerminal()) {
                    return;
                }

                if (
                    !hash_equals(
                        (string)
                        $render->claim_token,
                        $claimToken
                    )
                ) {
                    throw new RuntimeException(
                        'Claim token mismatch.'
                    );
                }

                $now =
                    $this->clock->now();

                $attempt =
                    RenderAttempt::query()
                        ->where(
                            'render_id',
                            $render->id
                        )
                        ->where(
                            'attempt_no',
                            $render->attempt_count
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $attempt->forceFill([
                    'status' =>
                        RenderAttemptStatus
                            ::PERMANENT_FAILED,

                    'failure_class' =>
                        $failureClass,

                    'error_code' =>
                        $errorCode,

                    'error_message' =>
                        $message,

                    'completed_at' =>
                        $now,
                ])->save();

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::FAILED,

                    'failure_class' =>
                        $failureClass,

                    'failure_code' =>
                        $errorCode,

                    'failure_message' =>
                        $message,

                    'claim_token' =>
                        null,

                    'claimed_by' =>
                        null,

                    'claimed_at' =>
                        null,

                    'heartbeat_at' =>
                        null,

                    'lease_expires_at' =>
                        null,

                    'execution_completed_at' =>
                        $now,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();
            }
        );
    }
}
14.47 Complete checkpoint phải idempotent

Đây là điểm critical.

Scenario:

Python POST /complete
↓
Laravel transaction commits SUCCEEDED
↓
network breaks before Python receives HTTP 200
↓
Python retries /complete

Second call phải trả success, không tạo duplicate CostEntry.

14.48 Complete DTO
<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

final class RenderAttemptUsage
{
    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $imageInputTokens,
        public readonly ?int $textInputTokens,
        public readonly ?string $providerCostUsd,
    ) {
    }

    public static function fromArray(
        array $data
    ): self {
        return new self(
            inputTokens:
                isset(
                    $data['input_tokens']
                )
                    ? (int)
                    $data['input_tokens']
                    : null,

            outputTokens:
                isset(
                    $data['output_tokens']
                )
                    ? (int)
                    $data['output_tokens']
                    : null,

            imageInputTokens:
                isset(
                    $data[
                        'image_input_tokens'
                    ]
                )
                    ? (int)
                    $data[
                        'image_input_tokens'
                    ]
                    : null,

            textInputTokens:
                isset(
                    $data[
                        'text_input_tokens'
                    ]
                )
                    ? (int)
                    $data[
                        'text_input_tokens'
                    ]
                    : null,

            providerCostUsd:
                isset(
                    $data[
                        'provider_cost_usd'
                    ]
                )
                    ? (string)
                    $data[
                        'provider_cost_usd'
                    ]
                    : null,
        );
    }
}
14.49 Cost accounting service

Nó phải tích hợp existing cost_entries, không tạo hệ thống billing thứ hai.

<?php

declare(strict_types=1);

namespace App\Video\Render\Cost;

use App\Models\CostEntry;
use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Render\DTO\RenderAttemptUsage;

final class RenderCostAccountingService
{
    public function record(
        Render $render,
        RenderAttempt $attempt,
        RenderAttemptUsage $usage,
    ): ?CostEntry {
        $key =
            sprintf(
                'render-attempt:%s:provider-usage',
                $attempt->id
            );

        /*
         * Financial idempotency.
         */
        $existing =
            CostEntry::query()
                ->where(
                    'cost_idempotency_key',
                    $key
                )
                ->first();

        if ($existing !== null) {
            if (
                $attempt->cost_entry_id
                !== $existing->id
            ) {
                $attempt->forceFill([
                    'cost_entry_id' =>
                        $existing->id,
                ])->save();
            }

            return $existing;
        }

        /*
         * If your existing Llm/provider accounting
         * already creates CostEntry elsewhere,
         * replace this with lookup/link instead.
         *
         * Do NOT double charge.
         */

        if (
            $usage->providerCostUsd === null
        ) {
            /*
             * Cost calculator can derive cost from
             * provider/model usage elsewhere.
             */
            return null;
        }

        $entry =
            CostEntry::query()
                ->create([
                    'source_type' =>
                        'render_attempt',

                    'source_id' =>
                        $attempt->id,

                    'cost_idempotency_key' =>
                        $key,

                    'video_session_id' =>
                        $render
                            ->video_session_id,

                    'provider' =>
                        $attempt->provider_key,

                    'model' =>
                        $attempt->model_key,

                    'action' =>
                        'image_render',

                    'input_tokens' =>
                        $usage->inputTokens,

                    'output_tokens' =>
                        $usage->outputTokens,

                    'cost_usd' =>
                        $usage->providerCostUsd,
                ]);

        $attempt->forceFill([
            'cost_entry_id' =>
                $entry->id,
        ])->save();

        return $entry;
    }
}

Nếu existing cost_entries schema khác, map fields sang schema hiện tại thay vì tạo duplicate table/service.

14.50 Complete checkpoint service
<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Cost\RenderCostAccountingService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderCheckpointService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RenderCostAccountingService $costs,
    ) {
    }

    public function complete(
        string $renderId,
        string $claimToken,
        string $requestHash,
        ?string $providerRequestId,
        array $artifactManifest,
        RenderAttemptUsage $usage,
    ): Render {
        return DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $requestHash,
                $providerRequestId,
                $artifactManifest,
                $usage,
            ): Render {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                /*
                 * ----------------------------------
                 * IDEMPOTENT COMPLETE REPLAY
                 * ----------------------------------
                 */
                if (
                    $render->execution_status
                    === RenderStatus::SUCCEEDED
                ) {
                    if (
                        !hash_equals(
                            (string)
                            $render->request_hash,

                            $requestHash
                        )
                    ) {
                        throw new RuntimeException(
                            'Succeeded render replay '
                            . 'request hash mismatch.'
                        );
                    }

                    return $render;
                }

                if (
                    !hash_equals(
                        (string)
                        $render->claim_token,

                        $claimToken
                    )
                ) {
                    throw new RuntimeException(
                        'Complete claim token mismatch.'
                    );
                }

                if (
                    !hash_equals(
                        (string)
                        $render->request_hash,

                        $requestHash
                    )
                ) {
                    throw new RuntimeException(
                        'Complete request hash mismatch.'
                    );
                }

                $this->verifyManifest(
                    $render,
                    $artifactManifest
                );

                $attempt =
                    RenderAttempt::query()
                        ->where(
                            'render_id',
                            $render->id
                        )
                        ->where(
                            'attempt_no',
                            $render->attempt_count
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                /*
                 * Provider request ID can be absent,
                 * but if both exist they must agree.
                 */
                if (
                    $attempt->provider_request_id
                    !== null
                    && $providerRequestId
                    !== null
                    && $attempt->provider_request_id
                    !== $providerRequestId
                ) {
                    throw new RuntimeException(
                        'Provider request ID mismatch.'
                    );
                }

                $now =
                    $this->clock->now();

                $attempt->forceFill([
                    'status' =>
                        RenderAttemptStatus::SUCCEEDED,

                    'provider_request_id' =>
                        $providerRequestId
                        ?? $attempt
                            ->provider_request_id,

                    'artifact_manifest' =>
                        $artifactManifest,

                    'input_tokens' =>
                        $usage->inputTokens,

                    'output_tokens' =>
                        $usage->outputTokens,

                    'image_input_tokens' =>
                        $usage->imageInputTokens,

                    'text_input_tokens' =>
                        $usage->textInputTokens,

                    'provider_cost_usd' =>
                        $usage->providerCostUsd,

                    'completed_at' =>
                        $now,
                ])->save();

                /*
                 * Cost and render success are in the
                 * SAME transaction.
                 */
                $this->costs->record(
                    $render,
                    $attempt,
                    $usage
                );

                $primaryHash =
                    $this->primaryArtifactHash(
                        $artifactManifest
                    );

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::SUCCEEDED,

                    'provider_request_id' =>
                        $providerRequestId
                        ?? $render
                            ->provider_request_id,

                    'artifact_manifest' =>
                        $artifactManifest,

                    'primary_artifact_hash' =>
                        $primaryHash,

                    'failure_class' =>
                        null,

                    'failure_code' =>
                        null,

                    'failure_message' =>
                        null,

                    'claim_token' =>
                        null,

                    'claimed_by' =>
                        null,

                    'claimed_at' =>
                        null,

                    'heartbeat_at' =>
                        null,

                    'lease_expires_at' =>
                        null,

                    'next_retry_at' =>
                        null,

                    'execution_completed_at' =>
                        $now,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                return $render;
            },
            attempts: 3
        );
    }

    private function verifyManifest(
        Render $render,
        array $manifest,
    ): void {
        $required = [
            'render_id',
            'request_hash',
            'canonical_hash',
            'artifacts',
        ];

        foreach ($required as $key) {
            if (
                !array_key_exists(
                    $key,
                    $manifest
                )
            ) {
                throw new RuntimeException(
                    'Artifact manifest missing '
                    . $key
                );
            }
        }

        if (
            $manifest['render_id']
            !== $render->id
        ) {
            throw new RuntimeException(
                'Artifact manifest render mismatch.'
            );
        }

        if (
            !hash_equals(
                (string)
                $render->request_hash,

                (string)
                $manifest['request_hash']
            )
        ) {
            throw new RuntimeException(
                'Artifact manifest request hash mismatch.'
            );
        }

        if (
            !hash_equals(
                (string)
                $render->canonical_hash,

                (string)
                $manifest['canonical_hash']
            )
        ) {
            throw new RuntimeException(
                'Artifact manifest canonical hash mismatch.'
            );
        }

        if (
            !is_array(
                $manifest['artifacts']
            )
            || $manifest['artifacts']
            === []
        ) {
            throw new RuntimeException(
                'Artifact manifest has no artifacts.'
            );
        }
    }

    private function primaryArtifactHash(
        array $manifest
    ): string {
        foreach (
            $manifest['artifacts']
            as $artifact
        ) {
            if (
                ($artifact['kind'] ?? null)
                === 'generated_image'
            ) {
                $hash =
                    $artifact['sha256']
                    ?? null;

                if (
                    is_string($hash)
                    && preg_match(
                        '/^[a-f0-9]{64}$/',
                        $hash
                    )
                ) {
                    return $hash;
                }
            }
        }

        throw new RuntimeException(
            'No generated image artifact found.'
        );
    }
}
14.51 Cost transaction nuance

Nếu provider response báo cost:

$0.025

nhưng DB commit fail:

provider đã charge
DB chưa ghi

Python có local journal nên callback /complete có thể retry.

Vì CostEntry có:

cost_idempotency_key

callback có thể lặp vô hạn mà không double record.

Đây là đúng architecture.

14.52 Provider fallback không phải retry cùng request

Rất quan trọng.

Ví dụ:

attempt 1
provider=openai
model=gpt-image-2
request_hash=A

fail permanent capability.

Nếu chuyển:

provider=google
model=gemini...

thì:

request_hash phải thành B

Không ghi attempt #2 với hash A.

14.53 Fallback plan

orchestration/fallback.py:

from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderCandidate:
    provider_key: str

    model_key: str

    priority: int


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderFallbackPlan:
    version: str

    candidates: tuple[
        ProviderCandidate,
        ...,
    ]

    def ordered(
        self,
    ) -> tuple[
        ProviderCandidate,
        ...,
    ]:
        return tuple(
            sorted(
                self.candidates,
                key=lambda item: (
                    item.priority,
                    item.provider_key,
                    item.model_key,
                ),
            )
        )

Nhưng fallback không nằm trong provider adapter.

Flow:

PromptSpec
↓
candidate provider B capability projection
↓
new ProviderPromptPlan
↓
new CompiledPrompt
↓
new RenderRequest
↓
new request_hash
↓
attempt #2
14.54 Base semantic hash

Để biết A/B vẫn phục vụ cùng một asset intent, thêm:

base_semantic_hash

Nó không chứa provider delivery details.

Tính từ:

canonical_hash
projection_hash
constraint_set_hash
prompt_spec_hash
reference hashes
asset

Không:

provider
model
provider options

Python:

from __future__ import annotations

import hashlib
import json


def base_render_semantic_hash(
    *,
    asset_id: str,
    canonical_hash: str,
    projection_hash: str,
    constraint_set_hash: str,
    prompt_spec_hash: str,
    reference_hashes: tuple[
        str,
        ...,
    ],
) -> str:
    payload = {
        "asset_id":
            asset_id,

        "canonical_hash":
            canonical_hash,

        "projection_hash":
            projection_hash,

        "constraint_set_hash":
            constraint_set_hash,

        "prompt_spec_hash":
            prompt_spec_hash,

        "reference_hashes":
            sorted(
                reference_hashes
            ),
    }

    raw = json.dumps(
        payload,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")

    return hashlib.sha256(
        raw
    ).hexdigest()
14.55 Không fallback vì “ảnh không đẹp”

Provider fallback execution chỉ vì:

provider unavailable
capability incompatibility
rate limit policy
permanent provider error

Không vì:

render nhìn không đẹp
geometry fail Vision QA

Đó là Part 15:

Vision QA
↓
targeted repair/re-render

Không trộn quality feedback vào infrastructure retry.

14.56 Worker loop
from __future__ import annotations

import logging
import time


class RenderWorker:
    def __init__(
        self,
        *,
        orchestrator,
        idle_sleep_seconds: float = 2.0,
    ) -> None:
        self._orchestrator = (
            orchestrator
        )

        self._idle_sleep = (
            idle_sleep_seconds
        )

        self._log = (
            logging.getLogger(
                __name__
            )
        )

    def run_forever(
        self,
    ) -> None:
        while True:
            try:
                worked = (
                    self._orchestrator
                    .run_once()
                )

                if not worked:
                    time.sleep(
                        self._idle_sleep
                    )

            except KeyboardInterrupt:
                return

            except BaseException:
                self._log.exception(
                    "Unhandled render worker error"
                )

                time.sleep(
                    self._idle_sleep
                )

Không để một render fail làm worker process chết.

14.57 Worker ID

Stable trong lifetime của process:

import os
import socket
import uuid


def make_worker_id() -> str:
    return (
        f"{socket.gethostname()}:"
        f"{os.getpid()}:"
        f"{uuid.uuid4()}"
    )

Ví dụ:

render-node-02:18422:8c32...
14.58 Request lifecycle phải freeze trước queue

Laravel flow:

Prompt Compiler result
↓
build RenderRequest
↓
serialize deterministic JSON
↓
calculate request_hash
↓
INSERT render row
    status=queued
    request_hash
    render_request_json
↓
COMMIT
↓
Python claim

Không:

insert render row
↓
queue
↓
Python tự build RenderRequest

vì retry có thể build request khác.

14.59 Laravel RenderDispatchService
<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\Render;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderDispatchService
{
    public function create(
        string $sessionId,
        string $assetId,
        string $provider,
        string $model,
        string $idempotencyKey,
        string $requestJson,
        string $requestHash,
        string $canonicalRevisionId,
        string $canonicalHash,
        string $projectionHash,
        string $constraintSetHash,
        string $promptSpecHash,
        string $providerPromptPlanHash,
        string $promptHash,
        int $maxAttempts = 3,
    ): Render {
        if (
            !preg_match(
                '/^[a-f0-9]{64}$/',
                $requestHash
            )
        ) {
            throw new RuntimeException(
                'Invalid request hash.'
            );
        }

        return DB::transaction(
            function () use (
                $sessionId,
                $assetId,
                $provider,
                $model,
                $idempotencyKey,
                $requestJson,
                $requestHash,
                $canonicalRevisionId,
                $canonicalHash,
                $projectionHash,
                $constraintSetHash,
                $promptSpecHash,
                $providerPromptPlanHash,
                $promptHash,
                $maxAttempts,
            ): Render {
                $existing =
                    Render::query()
                        ->where(
                            'video_session_id',
                            $sessionId
                        )
                        ->where(
                            'idempotency_key',
                            $idempotencyKey
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    if (
                        !hash_equals(
                            (string)
                            $existing->request_hash,

                            $requestHash
                        )
                    ) {
                        throw new RuntimeException(
                            'Idempotency key reused '
                            . 'with different request.'
                        );
                    }

                    return $existing;
                }

                return Render::query()
                    ->create([
                        'video_session_id' =>
                            $sessionId,

                        'asset_id' =>
                            $assetId,

                        'provider' =>
                            $provider,

                        'model' =>
                            $model,

                        'idempotency_key' =>
                            $idempotencyKey,

                        'request_hash' =>
                            $requestHash,

                        'render_request_json' =>
                            $requestJson,

                        'canonical_concept_revision_id' =>
                            $canonicalRevisionId,

                        'canonical_hash' =>
                            $canonicalHash,

                        'projection_hash' =>
                            $projectionHash,

                        'constraint_set_hash' =>
                            $constraintSetHash,

                        'prompt_spec_hash' =>
                            $promptSpecHash,

                        'provider_prompt_plan_hash' =>
                            $providerPromptPlanHash,

                        'prompt_hash' =>
                            $promptHash,

                        'execution_status' =>
                            RenderStatus::QUEUED,

                        'attempt_count' =>
                            0,

                        'max_attempts' =>
                            $maxAttempts,

                        'execution_version' =>
                            0,
                    ]);
            },
            attempts: 3
        );
    }
}
14.60 Render request bytes/hash consistency

Laravel không nên tự tính hash theo algorithm khác Python.

Tốt nhất:

Python Prompt/Request Compiler
↓
request_json
request_hash
↓
Laravel stores exact pair

Nếu Laravel cần build request itself, cả hai phải dùng cùng canonical request hashing contract.

Với architecture hiện tại, tôi ưu tiên:

Python compile RenderRequest artifact
↓
POST exact request_json/hash → Laravel
↓
Laravel freezes work item
↓
execution worker later claims same bytes

Như vậy không có PHP/Python canonicalization drift.

14.61 Cost state không quyết định retry

Sai:

cost entry exists
→ assume render succeeded

Không.

Cost có thể đã incurred nhưng artifact chưa recover được.

Đúng:

render execution state
+
provider reconciliation
+
artifact ledger

quyết định success/retry.

Cost chỉ financial truth.

14.62 Provider reconciliation interface

Chuẩn bị ngay để xử lý ambiguous.

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum
from typing import Protocol


class ReconciliationStatus(StrEnum):
    SUCCEEDED = "succeeded"

    FAILED = "failed"

    STILL_RUNNING = "still_running"

    NOT_FOUND = "not_found"

    UNSUPPORTED = "unsupported"


@dataclass(
    frozen=True,
    slots=True,
)
class ReconciliationResult:
    status: ReconciliationStatus

    provider_response: (
        object | None
    ) = None


class ProviderReconciler(
    Protocol
):
    def reconcile(
        self,
        *,
        provider_request_id: str,
    ) -> ReconciliationResult:
        ...

Không phải provider nào cũng support.

Registry:

class ProviderReconcilerRegistry:
    def __init__(
        self,
        reconcilers,
    ):
        self._items = {
            item.provider_key:
                item
            for item in reconcilers
        }

    def get(
        self,
        provider_key: str,
    ):
        return self._items.get(
            provider_key
        )
14.63 Nếu provider không có request ID

Nếu network disconnect xảy ra trước response:

provider_request_id = null

và provider không support client idempotency/reconciliation:

PROVIDER_UNKNOWN

là đúng.

Không được giả vờ có exactly-once semantics.

Đây là giới hạn thực tế của remote APIs.

Mục tiêu production không phải “không bao giờ duplicate” tuyệt đối; mục tiêu là:

Không duplicate khi hệ thống có đủ thông tin để tránh, và không retry mù khi kết quả provider không xác định.

14.64 Optional provider idempotency

Nếu provider hỗ trợ client idempotency key ở endpoint cụ thể:

RenderRequest.idempotency_key

adapter có thể truyền thành provider idempotency header/field.

Nhưng chỉ khi API contract xác nhận support.

Không tự thêm generic:

Idempotency-Key

cho mọi provider.

14.65 Laravel ambiguous reconciliation state

Ta nên có scheduled job:

final class ReconcileUnknownRendersJob
    implements ShouldQueue
{
    public function handle(
        UnknownRenderReconciliationService $service
    ): void {
        $service->dispatchPending();
    }
}

Nhưng provider-side execution ở Python, nên Laravel có thể chuyển unknown render thành reconciliation task cho Python.

Đây không phải main render queue.

14.66 Retry budget: infrastructure vs QA

max_attempts = 3 ở Part14 có nghĩa:

infra/provider execution attempts

Không gồm:

Vision QA targeted re-render

QA repair sau này tạo:

render_revision mới
hoặc qa_generation_no mới

với request hash mới.

Không ăn chung retry budget.

14.67 Retry rules chốt
Failure	Retry?	Lý do
DNS fail trước submit	Yes	provider chưa nhận
connect refused	Yes	safe
HTTP 429	Yes	provider explicit rejection
HTTP 500 response	Yes	request failed definitively
HTTP 400	No	request/schema bug
HTTP 401/403	No	auth/config
local reference hash fail	No	input integrity
timeout sau request body send	Ambiguous	có thể đã render
worker dies in SUBMITTING	Ambiguous	không biết provider state
worker dies after artifacts persisted	No provider retry	resume checkpoint
Laravel /complete HTTP response lost	No provider retry	replay /complete

Đây là core Part 14.

14.68 Python orchestrator phải biết checkpoint failure sau artifact write

Nếu:

artifact persisted
↓
Laravel complete timeout

generic _handle_failure hiện thấy phase CHECKPOINTING và có thể classify ambiguous.

Nhưng đây không phải provider ambiguous nữa.

Ta đã có output.

Cần special case.

Sửa orchestrator:

except BaseException as exc:
    if (
        context.phase
        == ExecutionPhase.CHECKPOINTING
    ):
        /*
         * Provider output and artifact manifest
         * already exist locally.
         *
         * Do NOT mark provider ambiguous.
         * Leave journal for checkpoint replay.
         */
        return

    self._handle_failure(...)

Worker process tiếp tục vòng sau.

Nhưng Laravel lease sẽ expire và có thể tạo attempt mới. Ta không muốn thế.

Tốt hơn /complete checkpoint phải được retry ngay với limited local HTTP retries before giving up.

14.69 Checkpoint retry ≠ provider retry

Tạo:

class CheckpointRetryPolicy:
    def __init__(
        self,
        *,
        max_attempts: int = 5,
        base_seconds: float = 1.0,
    ) -> None:
        self.max_attempts = (
            max_attempts
        )

        self.base_seconds = (
            base_seconds
        )

Helper:

import time


def retry_checkpoint(
    callback,
    *,
    max_attempts: int = 5,
    base_seconds: float = 1.0,
):
    last = None

    for attempt in range(
        1,
        max_attempts + 1,
    ):
        try:
            return callback()

        except (
            TimeoutError,
            ConnectionError,
        ) as exc:
            last = exc

            if attempt >= max_attempts:
                break

            time.sleep(
                min(
                    base_seconds
                    * (2 ** (attempt - 1)),
                    10.0,
                )
            )

    if last is not None:
        raise last

This retry is safe because /complete is idempotent.

14.70 Complete callback replay before lease expiry

Orchestrator:

retry_checkpoint(
    lambda: self._checkpoint.complete(
        ...
    ),
    max_attempts=5,
)

During this:

heartbeat still running

so claim remains owned.

Good.

14.71 Resume across process restart requires artifact journal search

If process dies after artifact write, lease eventually expires.

Current lease recovery sees:

SUBMITTED / ARTIFACT_WRITING
→ PROVIDER_UNKNOWN

But Python has local journal proving artifacts exist.

We need separate recovery endpoint/flow:

local journal ARTIFACTS_PERSISTED
↓
ask Laravel reconcile render
↓
verify request hash/attempt identity
↓
reclaim checkpoint-only lease
↓
complete without provider

This is safer than creating normal render attempt.

14.72 Checkpoint recovery claim

Laravel endpoint:

POST /internal/render-worker/{render}/recover-artifacts

Payload:

{
  "worker_id": "node...",
  "attempt_no": 2,
  "request_hash": "...",
  "manifest_hash": "..."
}

Laravel verifies attempt is AMBIGUOUS/SUBMITTED and request hash same, then issues recovery claim, not new provider attempt.

This prevents duplicate billable call.

14.73 Recovery claim DTO
final class RenderRecoveryClaim
{
    public function __construct(
        public readonly string $renderId,
        public readonly int $attemptNo,
        public readonly string $claimToken,
        public readonly DateTimeImmutable $leaseExpiresAt,
    ) {
    }
}
14.74 Artifact recovery service
<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\Render;
use App\Models\RenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\DTO\RenderRecoveryClaim;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderArtifactRecoveryService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
    }

    public function claim(
        string $renderId,
        int $attemptNo,
        string $requestHash,
        string $workerId,
    ): RenderRecoveryClaim {
        return DB::transaction(
            function () use (
                $renderId,
                $attemptNo,
                $requestHash,
                $workerId,
            ): RenderRecoveryClaim {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $render->execution_status
                    === RenderStatus::SUCCEEDED
                ) {
                    throw new RuntimeException(
                        'Render already succeeded.'
                    );
                }

                if (
                    !hash_equals(
                        (string)
                        $render->request_hash,

                        $requestHash
                    )
                ) {
                    throw new RuntimeException(
                        'Recovery request hash mismatch.'
                    );
                }

                $attempt =
                    RenderAttempt::query()
                        ->where(
                            'render_id',
                            $render->id
                        )
                        ->where(
                            'attempt_no',
                            $attemptNo
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    !in_array(
                        $attempt->status,
                        [
                            RenderAttemptStatus::SUBMITTED,
                            RenderAttemptStatus::AMBIGUOUS,
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Attempt is not eligible '
                        . 'for artifact recovery.'
                    );
                }

                $now =
                    $this->clock->now();

                $token =
                    (string)
                    Str::uuid();

                $expires =
                    $now->add(
                        new DateInterval(
                            'PT'
                            . $this->leaseSeconds
                            . 'S'
                        )
                    );

                /*
                 * IMPORTANT:
                 * Do not increment attempt_count.
                 * This is recovery of existing attempt.
                 */
                $render->forceFill([
                    'claim_token' =>
                        $token,

                    'claimed_by' =>
                        $workerId,

                    'claimed_at' =>
                        $now,

                    'heartbeat_at' =>
                        $now,

                    'lease_expires_at' =>
                        $expires,

                    'execution_status' =>
                        RenderStatus::CHECKPOINTING,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                return new RenderRecoveryClaim(
                    renderId:
                        $render->id,

                    attemptNo:
                        $attemptNo,

                    claimToken:
                        $token,

                    leaseExpiresAt:
                        $expires,
                );
            }
        );
    }
}
14.75 Artifact integrity during recovery

Local journal alone không đủ.

Before /complete, Python phải re-read all output files and compare hashes from manifest.

from __future__ import annotations

import hashlib
from pathlib import Path


class ArtifactManifestVerifier:
    def verify(
        self,
        manifest: dict,
    ) -> None:
        artifacts = manifest.get(
            "artifacts"
        )

        if not isinstance(
            artifacts,
            list,
        ):
            raise RuntimeError(
                "Manifest artifacts invalid"
            )

        for artifact in artifacts:
            path = Path(
                artifact["path"]
            )

            expected = (
                artifact["sha256"]
            )

            if not path.is_file():
                raise RuntimeError(
                    "Recovery artifact missing: "
                    + str(path)
                )

            actual = self._hash(
                path
            )

            if not hashlib.compare_digest(
                expected,
                actual,
            ):
                raise RuntimeError(
                    "Recovery artifact hash mismatch: "
                    + str(path)
                )

    @staticmethod
    def _hash(
        path: Path,
    ) -> str:
        digest = hashlib.sha256()

        with path.open("rb") as handle:
            while chunk := handle.read(
                1024 * 1024
            ):
                digest.update(chunk)

        return digest.hexdigest()
14.76 Cost accounting for ambiguous attempt

Nếu provider may have charged nhưng no output:

attempt status = AMBIGUOUS

Cost may also be unknown.

Không fabricate:

cost = 0

Giữ:

provider_cost_usd = NULL

Nếu provider billing reconciliation sau đó phát hiện cost:

CostEntry adjustment

không sửa lịch sử attempt.

14.77 Cost types sau này nên support estimated/actual

Nếu cost_entries chưa có, tôi khuyên:

cost_basis =
provider_reported
calculated
estimated
adjustment

nhưng không cần đổi core Part14 nếu existing accounting đã có equivalent.

14.78 Concurrency invariant

Hai Python workers:

A claimNext()
B claimNext()

DB:

SELECT ... FOR UPDATE SKIP LOCKED

guarantee một render row chỉ đi một worker.

Queue/Redis unique locks có thể thêm optimization nhưng không thay DB claim.

14.79 Claim token là fencing token

claim_token không chỉ auth.

Nó bảo vệ stale worker.

Scenario:

Worker A claim token=A
↓
lease expires
↓
Worker B claim token=B
↓
Worker A tỉnh lại
↓
POST complete(token=A)

Laravel phải reject.

Không để stale worker overwrite B.

Đây là fencing token.

14.80 Tốt hơn nữa: execution_version fencing

Có thể gửi thêm:

claim_generation

nhưng UUID claim token đã đủ V1.

Nếu muốn stronger ordering, thêm:

claim_generation INT

tăng mỗi claim.

Tôi khuyên có từ production V1.

Migration:

$table
    ->unsignedInteger(
        'claim_generation'
    )
    ->default(0);

Claim:

'claim_generation' =>
    $render->claim_generation + 1,

Payload:

claim_token
claim_generation

Checkpoint verify cả hai.

Điều này dễ audit stale requests hơn.

14.81 Retry same request không đổi canonical/prompt

Infra retry phải giữ:

canonical_hash        SAME
projection_hash       SAME
constraint_set_hash   SAME
prompt_spec_hash      SAME
prompt_hash           SAME
references            SAME hashes
provider/model        SAME
request_hash          SAME

Chỉ thay:

attempt_no
claim_token
worker_id
timestamps

Nếu semantic field đổi thì đó không còn là retry.

Đó là new render request/revision.

14.82 Fallback đổi provider thì request hash đổi
attempt 1:
openai / A

fallback:
google / B

Nhưng base_semantic_hash giữ same.

Cho phép analytics:

same visual intent
executed by different providers
14.83 Attempt paths phải không overwrite

Part 13:

renders/{render_id}/output.png

Part14 production:

renders/{render_id}/
├── attempt_001/
│   ├── response.json
│   ├── output_000.png
│   └── manifest.json
│
├── attempt_002/
│   └── ...
│
└── selected.json

Không overwrite failed attempt artifacts.

14.84 Selected artifact

Khi render success:

selected.json

có thể:

{
  "render_id": "REN-001",
  "attempt_no": 2,
  "artifact_id": "REN-001:a2:image:0",
  "sha256": "...",
  "request_hash": "..."
}

Không copy file lần nữa.

Laravel primary_artifact_hash trỏ exact selected artifact.

14.85 Retry classification không dùng text parsing

Không:

if "timeout" in str(error):

Transport normalize error thành typed:

ProviderHttpError
ConnectionError
TimeoutError
ProviderResponseError

Classifier dựa class/status/code.

Production mới ổn.

14.86 Provider transport phải biết may_have_been_accepted

Ví dụ HTTP client:

connection error before connect
→ false

connect succeeded, request body sent, read timeout
→ true

Transport nên wrap:

raise ProviderHttpError(
    "...",
    http_status=None,
    request_may_have_been_accepted=True,
)

Adapter không tự suy đoán từ string.

14.87 Python worker shutdown

SIGTERM khi deploy:

stop claiming new work
finish current claim if safe
heartbeat until current finishes

Không kill instant.

Simple handler:

import signal
import threading


shutdown = threading.Event()


def handle_shutdown(
    signum,
    frame,
):
    shutdown.set()


signal.signal(
    signal.SIGTERM,
    handle_shutdown,
)

signal.signal(
    signal.SIGINT,
    handle_shutdown,
)

Worker loop:

while not shutdown.is_set():
    worked = orchestrator.run_once()

    if not worked:
        shutdown.wait(2.0)
14.88 Supervisor/systemd config

Với Python worker:

[program:ai-render-worker]
command=/srv/news24h/venv/bin/python -m media_runtime.worker
directory=/srv/news24h
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=180
stdout_logfile=/var/log/news24h/render-worker.log
stderr_logfile=/var/log/news24h/render-worker-error.log
environment=PYTHONUNBUFFERED="1"

stopwaitsecs phải dài hơn expected provider request + checkpoint window.

14.89 Laravel scheduler lease recovery
Schedule::call(
    function (
        ExpiredRenderLeaseRecovery $service
    ): void {
        $service->recover(
            limit: 100
        );
    }
)->everyMinute()
  ->withoutOverlapping();

Không cần chạy mỗi giây.

Worker heartbeat interval 30s, lease 120s là baseline hợp lý.

14.90 Metrics nên log

Mỗi attempt log structured:

logger.info(
    "render_attempt_succeeded",
    extra={
        "render_id":
            request.render_id,

        "attempt_no":
            attempt_no,

        "request_hash":
            request_hash,

        "provider":
            request.provider_key,

        "model":
            request.model_key,

        "provider_request_id":
            response.provider_request_id,

        "latency_ms":
            response.latency_ms,
    },
)

Không log:

API key
full base64
sensitive headers
14.91 Test — double worker claim
public function test_render_can_only_be_claimed_once(): void
{
    $render =
        Render::factory()
            ->create([
                'execution_status' =>
                    RenderStatus::QUEUED,

                'attempt_count' =>
                    0,

                'max_attempts' =>
                    3,
            ]);

    $first =
        app(
            RenderClaimService::class
        )->claimNext(
            'worker-A'
        );

    $second =
        app(
            RenderClaimService::class
        )->claimNext(
            'worker-B'
        );

    self::assertNotNull(
        $first
    );

    self::assertNull(
        $second
    );

    $render->refresh();

    self::assertSame(
        'worker-A',
        $render->claimed_by
    );
}
14.92 Test stale worker rejected
public function test_stale_claim_token_cannot_complete_render(): void
{
    $render =
        $this->claimedRender(
            claimToken:
                '11111111-1111-1111-1111-111111111111'
        );

    $render->forceFill([
        'claim_token' =>
            '22222222-2222-2222-2222-222222222222',
    ])->save();

    $this->expectException(
        RuntimeException::class
    );

    app(
        RenderCheckpointService::class
    )->complete(
        renderId:
            $render->id,

        claimToken:
            '11111111-1111-1111-1111-111111111111',

        requestHash:
            $render->request_hash,

        providerRequestId:
            null,

        artifactManifest:
            $this->validManifest(
                $render
            ),

        usage:
            $this->emptyUsage(),
    );
}
14.93 Test complete callback idempotent
public function test_complete_callback_can_be_replayed(): void
{
    $render =
        $this->submittedRender();

    $usage =
        new RenderAttemptUsage(
            inputTokens: 100,
            outputTokens: 50,
            imageInputTokens: null,
            textInputTokens: 100,
            providerCostUsd: '0.02500000',
        );

    $service =
        app(
            RenderCheckpointService::class
        );

    $first =
        $service->complete(
            renderId:
                $render->id,

            claimToken:
                $render->claim_token,

            requestHash:
                $render->request_hash,

            providerRequestId:
                'provider-123',

            artifactManifest:
                $this->validManifest(
                    $render
                ),

            usage:
                $usage,
        );

    $second =
        $service->complete(
            renderId:
                $render->id,

            /*
             * On replay frozen SUCCESS is checked
             * before claim token ownership.
             */
            claimToken:
                'old-token',

            requestHash:
                $render->request_hash,

            providerRequestId:
                'provider-123',

            artifactManifest:
                $this->validManifest(
                    $render
                ),

            usage:
                $usage,
        );

    self::assertSame(
        RenderStatus::SUCCEEDED,
        $second->execution_status
    );

    self::assertSame(
        1,
        CostEntry::query()
            ->where(
                'source_type',
                'render_attempt'
            )
            ->count()
    );
}
14.94 Test idempotency key with changed request rejected
public function test_idempotency_key_cannot_reference_different_request(): void
{
    $service =
        app(
            RenderDispatchService::class
        );

    $first =
        $service->create(
            // ...
            idempotencyKey:
                'master:1',

            requestHash:
                str_repeat('a', 64),

            requestJson:
                '{"x":1}',

            // ...
        );

    $this->expectException(
        RuntimeException::class
    );

    $service->create(
        // ...
        idempotencyKey:
            'master:1',

        requestHash:
            str_repeat('b', 64),

        requestJson:
            '{"x":2}',

        // ...
    );
}
14.95 Python retry classifier tests
def test_429_is_safe_retry():
    error = ProviderHttpError(
        "rate limit",

        http_status=429,

        request_may_have_been_accepted=False,

        retry_after_seconds=5,
    )

    result = (
        RetryClassifier()
        .classify(
            error
        )
    )

    assert (
        result.disposition
        == RetryDisposition.RETRY_SAFE
    )

    assert (
        result.failure_class
        == FailureClass.RATE_LIMIT
    )

    assert (
        result.retry_after_seconds
        == 5
    )
14.96 400 không retry
def test_400_is_permanent():
    error = ProviderHttpError(
        "bad request",

        http_status=400,

        request_may_have_been_accepted=False,
    )

    result = (
        RetryClassifier()
        .classify(
            error
        )
    )

    assert (
        result.disposition
        == RetryDisposition.DO_NOT_RETRY
    )
14.97 Timeout after submit → ambiguous
def test_timeout_after_submission_is_ambiguous(
    orchestrator,
):
    context = AttemptExecutionContext(
        render_id="r1",
        attempt_no=1,
        claim_token="token",
        worker_id="worker",
        request_hash="a" * 64,
        phase=(
            ExecutionPhase.SUBMITTING
        ),
    )

    decision = (
        orchestrator
        .classify_execution_failure(
            context=context,
            error=TimeoutError(),
        )
    )

    assert (
        decision.disposition
        == RetryDisposition.AMBIGUOUS
    )

Tôi khuyên extract logic trong _handle_failure() thành public-testable:

ExecutionFailureClassifier

thay vì test private method.

14.98 Test process crash before submit

State:

PREPARING
lease expired

Expected:

RETRY_WAIT
attempt ABANDONED

Không PROVIDER_UNKNOWN.

14.99 Test process crash after submit

State:

SUBMITTING
lease expired

Expected:

PROVIDER_UNKNOWN
attempt AMBIGUOUS

Không retry.

14.100 Test artifact checkpoint recovery
def test_persisted_artifacts_resume_without_provider_call(
    journal_store,
    fake_checkpoint,
    fake_provider,
    orchestrator,
):
    journal_store.save(
        LocalAttemptJournal(
            render_id="r1",
            attempt_no=1,
            request_hash="a" * 64,
            phase=(
                JournalPhase
                .ARTIFACTS_PERSISTED
            ),
            provider_request_id="p1",
            artifact_manifest={
                "render_id": "r1",
                "request_hash": "a" * 64,
                "artifacts": [],
            },
            usage={
                "provider_cost_usd":
                    "0.025",
            },
        )
    )

    orchestrator.run_once()

    assert (
        fake_provider.call_count
        == 0
    )

    assert (
        fake_checkpoint
        .complete_call_count
        == 1
    )

Trong actual production flow cần recovery claim như mục 14.72–14.74, không reuse normal claim blindly.

14.101 Test cost not duplicated
public function test_replayed_completion_does_not_duplicate_cost(): void
{
    // complete twice

    self::assertSame(
        1,
        CostEntry::query()
            ->where(
                'cost_idempotency_key',
                'render-attempt:'
                . $attempt->id
                . ':provider-usage'
            )
            ->count()
    );
}
14.102 Test retry preserves request hash
public function test_infrastructure_retry_preserves_exact_request(): void
{
    $render =
        $this->renderWithFailedAttempt();

    $originalHash =
        $render->request_hash;

    $claim =
        app(
            RenderClaimService::class
        )->claimNext(
            'worker-2'
        );

    self::assertSame(
        $originalHash,
        $claim->requestHash
    );

    self::assertSame(
        2,
        $claim->attemptNo
    );
}
14.103 Test max attempts
public function test_retry_budget_exhaustion_marks_failed(): void
{
    $render =
        $this->claimedRender([
            'attempt_count' => 3,
            'max_attempts' => 3,
        ]);

    app(
        RenderRetryService::class
    )->schedule(
        renderId:
            $render->id,

        claimToken:
            $render->claim_token,

        failureClass:
            RenderFailureClass::RATE_LIMIT,

        errorCode:
            'rate_limit',

        message:
            'Too many requests',

        retryAfterSeconds:
            5,
    );

    $render->refresh();

    self::assertSame(
        RenderStatus::FAILED,
        $render->execution_status
    );
}
14.104 State flow thành công
QUEUED
  ↓
CLAIMED
  ↓
PREPARING
  ↓
SUBMITTING
  ↓
SUBMITTED
  ↓
ARTIFACT_WRITING
  ↓
CHECKPOINTING
  ↓
SUCCEEDED

Lưu ý implementation phía Laravel nên checkpoint ARTIFACT_WRITING/CHECKPOINTING nếu bạn muốn state DB cực chi tiết. Code trên đã biểu diễn state nhưng các endpoint có thể được bổ sung tương tự markPreparing().

14.105 Retry flow
QUEUED
 ↓
CLAIMED
 ↓
PREPARING
 ↓
safe 429 / 5xx
 ↓
RETRY_WAIT
 ↓ next_retry_at
CLAIMED
 ↓
attempt #2

Same:

request_hash
14.106 Ambiguous flow
CLAIMED
 ↓
SUBMITTING
 ↓
network disappears
 ↓
PROVIDER_UNKNOWN
 ↓
reconciliation
 ├── provider says success
 │      ↓
 │    recover artifacts
 │      ↓
 │    SUCCEEDED
 │
 ├── provider says failed/not accepted
 │      ↓
 │    RETRY_WAIT
 │
 └── impossible to know
        ↓
      remain UNKNOWN /
      policy or human decision

Không có automatic render lại.

14.107 Crash-after-output flow
SUBMITTED
 ↓
provider response
 ↓
artifact fsync
 ↓
local journal ARTIFACTS_PERSISTED
 ↓
process dies
 ↓
recovery scanner
 ↓
verify artifact hashes
 ↓
recovery claim SAME attempt
 ↓
POST complete()
 ↓
CostEntry idempotent
 ↓
SUCCEEDED

Không provider call lần 2.

14.108 Laravel endpoint /complete không được trust artifact manifest hoàn toàn

Ở production tốt hơn Laravel biết artifact storage keys đã được uploaded/registered.

Nếu Python chỉ gửi:

"/tmp/foo.png"

Laravel không verify được.

Do đó khi chuyển S3/object storage:

Python
↓
upload artifact
↓
storage key + sha256
↓
Laravel complete
↓
Laravel stores durable reference

Part14 architecture đã chuẩn bị cho việc này.

14.109 Local filesystem vs object storage

Hiện:

ArtifactWriter → local filesystem

được cho một server.

Production multi-worker/multi-node nên:

ArtifactStorage
├── Local shared volume
└── S3-compatible storage

vì recovery worker khác node cần đọc artifact.

Nếu chỉ chạy một Python render machine trong LAN:

local disk + backup

vẫn đủ.

14.110 Không dùng Redis làm render truth

Redis có thể dùng:

queue
rate limiter
cache
worker heartbeat dashboard

Nhưng:

claim owner
render status
request hash
attempt history
cost
artifact lineage

source-of-truth vẫn DB.

Redis restart không được làm mất render history.

14.111 Không dùng Supervisor restart như retry logic

Supervisor:

worker process chết
→ restart process

nhưng không biết:

provider request đã submit chưa.

Do đó Supervisor chỉ phục hồi process.

State machine/lease/journal mới quyết định resume.

14.112 Không dùng Laravel queue tries làm provider retry count

Nếu DispatchPythonSessionJob:

public int $tries = 5;

đó là queue job infrastructure retry.

Không lấy:

$job->attempts()

làm render_attempt_no.

render_attempts DB mới là authoritative execution attempts.

14.113 Cost accounting architecture cuối
Provider
  ↓
ProviderUsage
  ↓
Render Attempt
  ├── token snapshot
  ├── provider_cost snapshot
  └── provider_request_id
        ↓
RenderCostAccountingService
        ↓
cost_entries
  ├── cost_idempotency_key
  ├── source_type=render_attempt
  └── source_id=attempt UUID

Nếu callback lặp:

same cost_idempotency_key
→ same CostEntry
14.114 Full Render Orchestrator production flow
Laravel creates immutable RenderRequest
        ↓
request_json + request_hash
        ↓
renders.status = QUEUED
════════════════════════════════════
PYTHON WORKER
════════════════════════════════════
        ↓
POST claim
        ↓
DB SELECT FOR UPDATE SKIP LOCKED
        ↓
claim_token + claim_generation
        ↓
RenderAttempt #N created
        ↓
heartbeat lease
        ↓
verify request_hash
        ↓
verify canonical lineage
        ↓
verify reference hashes
        ↓
PREPARING
        ↓
SUBMITTING
        ↓
ProviderAdapter
        ↓
        ├── definitive safe failure
        │        ↓
        │   RetryClassifier
        │        ↓
        │   RETRY_WAIT
        │
        ├── ambiguous failure
        │        ↓
        │   PROVIDER_UNKNOWN
        │        ↓
        │   reconciliation
        │
        └── success
              ↓
        provider_request_id
              ↓
        SUBMITTED
              ↓
        write output atomically
              ↓
        SHA-256 artifacts
              ↓
        local recovery journal
              ↓
        checkpoint /complete
              ↓
        DB transaction
          ├── verify request hash
          ├── verify manifest lineage
          ├── complete RenderAttempt
          ├── idempotent CostEntry
          ├── select primary artifact
          ├── Render SUCCEEDED
          └── clear lease
              ↓
        delete local journal
14.115 Sau Part14, hash/attempt relationship
CANONICAL REVISION
canonical_hash = C
       ↓
AssetProjection
projection_hash = P
       ↓
ConstraintSet
constraint_hash = K
       ↓
PromptSpec
prompt_spec_hash = S
       ↓
CompiledPrompt
prompt_hash = H
       ↓
RenderRequest
request_hash = R
       │
       ├── attempt #1
       │     worker A
       │     provider request X
       │     retryable fail
       │
       ├── attempt #2
       │     worker B
       │     provider request Y
       │
       └── attempt #3
             success
             artifact hash A

R remains identical
for infrastructure retries.

Fallback:

same semantic intent
base_semantic_hash = B

OpenAI request_hash = R1
Google request_hash = R2

Không giả vờ R1 = R2.

14.116 Các invariant tôi chốt sau Phần 14
1. Một render tại một thời điểm chỉ có một
   active claim owner.

2. claim_token là fencing token.
   Worker cũ không được checkpoint sau khi
   mất lease.

3. Worker chết trước provider submission
   → safe retry.

4. Worker/network chết sau submission boundary
   → ambiguous, không blind retry.

5. Artifact đã persist
   → resume checkpoint, không gọi provider lại.

6. Infrastructure retry giữ nguyên request_hash.

7. Provider fallback tạo request_hash mới.

8. Queue retry != render attempt.

9. Provider adapter không chứa semantic retry.

10. CostEntry được ghi idempotent theo attempt_id.

11. /complete có thể replay an toàn.

12. DB là execution truth;
    local journal chỉ là recovery aid.

13. Redis/Supervisor không phải render state truth.

14. Vision QA re-render không dùng chung
    infrastructure retry budget.

15. Không claim exactly-once với remote provider
    khi provider không hỗ trợ idempotency/reconciliation;
    ambiguous outcome được giữ explicit.

