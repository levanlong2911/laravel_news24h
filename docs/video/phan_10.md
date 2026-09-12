Được. Với **Phần 10**, tôi sẽ giữ nguyên contract đã khóa ở Phần 1–9 và không đưa thêm domain logic vào persistence.

Điểm quan trọng nhất của Phần 10 là:

> **DB không chỉ lưu “kết quả cuối”. DB phải lưu được toàn bộ lineage từ generation → validation → repair → normalization → freeze, nhưng `canonical_json` đã freeze phải immutable.**

Tôi đề xuất Phần 10 production theo kiến trúc sau:

```text
BuildConceptJob
      ↓
CanonicalConceptPersistenceService
      ↓
create/get revision
      ↓
┌───────────────────────────────────────────────┐
│ canonical_concept_revisions                   │
│                                               │
│ revision = 3                                  │
│ status = generating → ... → frozen            │
│ canonical_json                                │
│ canonical_hash                                │
│ profile/schema/compiler metadata              │
└───────────────────────────────────────────────┘
      │
      ├── canonical_concept_attempts
      │      ├── generation attempt
      │      └── repair attempt ≤ 1
      │
      ├── canonical_validation_runs
      │      ├── core_schema
      │      ├── effective_schema
      │      ├── semantic
      │      └── post_normalization
      │
      ├── canonical_decisions
      │      └── Decision Ledger
      │
      └── canonical_concept_events
             └── append-only state history

FINAL:
canonical_concept_revisions.status = frozen
canonical_json immutable
canonical_hash immutable
```

Tôi sẽ dùng namespace tiếp tục từ code trước:

```text
App\Video\Concept
```

---

# PHẦN 10 — Cấu trúc hoàn chỉnh

```text
app/Video/Concept/
├── Persistence/
│   ├── Models/
│   │   ├── CanonicalConceptRevision.php
│   │   ├── CanonicalConceptAttempt.php
│   │   ├── CanonicalValidationRun.php
│   │   ├── CanonicalDecision.php
│   │   └── CanonicalConceptEvent.php
│   │
│   ├── Enums/
│   │   ├── CanonicalConceptStatus.php
│   │   ├── CanonicalAttemptType.php
│   │   ├── CanonicalAttemptStatus.php
│   │   ├── ValidationStage.php
│   │   ├── ValidationRunStatus.php
│   │   ├── DecisionOrigin.php
│   │   └── CanonicalEventType.php
│   │
│   ├── DTO/
│   │   ├── CanonicalRevisionIdentity.php
│   │   ├── AttemptUsage.php
│   │   └── CanonicalFreezeRecord.php
│   │
│   ├── Repository/
│   │   ├── CanonicalConceptRepository.php
│   │   └── EloquentCanonicalConceptRepository.php
│   │
│   ├── StateMachine/
│   │   ├── InvalidCanonicalStateTransition.php
│   │   └── CanonicalConceptStateMachine.php
│   │
│   ├── Ledger/
│   │   ├── DecisionLedgerWriter.php
│   │   └── CanonicalDecisionExtractor.php
│   │
│   ├── CanonicalConceptPersistenceService.php
│   └── PersistedCanonicalConcept.php
│
└── BuildCanonicalConcept.php
```

Database:

```text
canonical_concept_revisions
canonical_concept_attempts
canonical_validation_runs
canonical_decisions
canonical_concept_events
```

---

# 10.1 State machine chính thức

Tôi không dùng một status chung chung như `processing`.

Ta cần biết pipeline đang đứng chính xác ở đâu:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalConceptStatus: string
{
    case PENDING = 'pending';

    case GENERATING = 'generating';

    case GENERATED = 'generated';

    case VALIDATING = 'validating';

    case VALIDATION_FAILED = 'validation_failed';

    case REPAIRING = 'repairing';

    case REPAIRED = 'repaired';

    case NORMALIZING = 'normalizing';

    case NORMALIZED = 'normalized';

    case FREEZING = 'freezing';

    case FROZEN = 'frozen';

    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::FROZEN,
            self::FAILED => true,

            default => false,
        };
    }

    public function isMutable(): bool
    {
        return $this !== self::FROZEN;
    }
}
```

`FAILED` là terminal cho **revision đó**, không có nghĩa project chết.

Có thể tạo revision mới.

---

# 10.2 Attempt type

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalAttemptType: string
{
    case GENERATION = 'generation';

    case REPAIR = 'repair';
}
```

---

# 10.3 Attempt status

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalAttemptStatus: string
{
    case STARTED = 'started';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';
}
```

---

# 10.4 Validation stages

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum ValidationStage: string
{
    case CORE_SCHEMA =
        'core_schema';

    case EFFECTIVE_SCHEMA =
        'effective_schema';

    case DTO_HYDRATION =
        'dto_hydration';

    case CROSS_FIELD =
        'cross_field';

    case PROVENANCE =
        'provenance';

    case PROFILE_COMPATIBILITY =
        'profile_compatibility';

    case CATEGORY_SEMANTIC =
        'category_semantic';

    case POST_NORMALIZATION_CORE =
        'post_normalization_core';

    case POST_NORMALIZATION_EFFECTIVE =
        'post_normalization_effective';

    case POST_NORMALIZATION_SEMANTIC =
        'post_normalization_semantic';
}
```

---

# 10.5 Validation status

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum ValidationRunStatus: string
{
    case PASSED = 'passed';

    case FAILED = 'failed';
}
```

---

# 10.6 Decision origin

Không trộn với `ProvenanceOrigin`.

`ProvenanceOrigin` thuộc Canonical Design.

`DecisionOrigin` thuộc operational ledger.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum DecisionOrigin: string
{
    case GENERATED = 'generated';

    case REPAIRED = 'repaired';

    case NORMALIZED = 'normalized';

    case SYSTEM = 'system';
}
```

---

# 10.7 Event types

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalEventType: string
{
    case REVISION_CREATED =
        'revision_created';

    case GENERATION_STARTED =
        'generation_started';

    case GENERATION_SUCCEEDED =
        'generation_succeeded';

    case GENERATION_FAILED =
        'generation_failed';

    case VALIDATION_STARTED =
        'validation_started';

    case VALIDATION_PASSED =
        'validation_passed';

    case VALIDATION_FAILED =
        'validation_failed';

    case REPAIR_STARTED =
        'repair_started';

    case REPAIR_SUCCEEDED =
        'repair_succeeded';

    case REPAIR_FAILED =
        'repair_failed';

    case NORMALIZATION_STARTED =
        'normalization_started';

    case NORMALIZATION_COMPLETED =
        'normalization_completed';

    case FREEZE_STARTED =
        'freeze_started';

    case FROZEN =
        'frozen';

    case FAILED =
        'failed';
}
```

---

# 10.8 Migration — `canonical_concept_revisions`

Đây là table quan trọng nhất.

```php
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
            'canonical_concept_revisions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table
                    ->uuid('video_project_id');

                $table
                    ->uuid('video_session_id')
                    ->nullable();

                $table
                    ->unsignedInteger('revision');

                $table
                    ->string('status', 40);

                /*
                 * Semantic/profile identity.
                 */
                $table
                    ->string('object_type', 120);

                $table
                    ->string('profile_key', 120);

                $table
                    ->string('profile_version', 40);

                /*
                 * Schema/version lineage.
                 */
                $table
                    ->string(
                        'canonical_schema_version',
                        40
                    );

                $table
                    ->char(
                        'effective_schema_hash',
                        64
                    )
                    ->nullable();

                /*
                 * Model/prompt lineage.
                 */
                $table
                    ->string(
                        'concept_model',
                        160
                    );

                $table
                    ->string(
                        'concept_prompt_version',
                        80
                    );

                $table
                    ->string(
                        'semantic_validator_version',
                        80
                    )
                    ->nullable();

                $table
                    ->string(
                        'normalizer_version',
                        80
                    )
                    ->nullable();

                $table
                    ->string(
                        'canonicalizer_version',
                        80
                    )
                    ->nullable();

                /*
                 * Frozen truth.
                 *
                 * DO NOT populate until successful freeze.
                 */
                $table
                    ->longText('canonical_json')
                    ->nullable();

                $table
                    ->char(
                        'canonical_hash',
                        64
                    )
                    ->nullable();

                /*
                 * Useful checkpoint data.
                 *
                 * This is NOT canonical truth.
                 */
                $table
                    ->longText(
                        'latest_raw_json'
                    )
                    ->nullable();

                $table
                    ->json(
                        'latest_validation_errors'
                    )
                    ->nullable();

                /*
                 * Exactly one repair allowed.
                 */
                $table
                    ->unsignedTinyInteger(
                        'repair_count'
                    )
                    ->default(0);

                /*
                 * Optimistic operational version.
                 */
                $table
                    ->unsignedInteger(
                        'lock_version'
                    )
                    ->default(0);

                $table
                    ->timestampTz(
                        'frozen_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'failed_at'
                    )
                    ->nullable();

                $table
                    ->text(
                        'failure_code'
                    )
                    ->nullable();

                $table
                    ->text(
                        'failure_message'
                    )
                    ->nullable();

                $table->timestampsTz();

                /*
                 * One canonical revision number
                 * per project.
                 */
                $table->unique(
                    [
                        'video_project_id',
                        'revision',
                    ],
                    'canonical_revision_project_rev_uq'
                );

                /*
                 * Prevent same frozen content being
                 * inserted repeatedly into one project.
                 *
                 * MySQL allows multiple NULL values.
                 */
                $table->unique(
                    [
                        'video_project_id',
                        'canonical_hash',
                    ],
                    'canonical_revision_project_hash_uq'
                );

                $table->index(
                    [
                        'video_project_id',
                        'status',
                    ],
                    'canonical_revision_project_status_idx'
                );

                $table->index(
                    [
                        'video_session_id',
                        'status',
                    ],
                    'canonical_revision_session_status_idx'
                );

                $table->index(
                    'canonical_hash',
                    'canonical_revision_hash_idx'
                );

                /*
                 * Add FK names explicitly if your
                 * existing table names use UUID PK.
                 */
                $table
                    ->foreign('video_project_id')
                    ->references('id')
                    ->on('video_projects')
                    ->cascadeOnDelete();

                $table
                    ->foreign('video_session_id')
                    ->references('id')
                    ->on('video_sessions')
                    ->nullOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'canonical_concept_revisions'
        );
    }
};
```

---

# 10.9 Tại sao revision thuộc project?

Canonical Design là identity của object.

Nó không nên mặc định thuộc một render session.

Ta đã chốt trước đó:

```text
video_project
     ↓
canonical identity
     ↓
many sessions/renders
```

Vì vậy:

```text
video_project_id = ownership
video_session_id = optional originating session
```

Một session sau có thể reuse frozen revision.

---

# 10.10 `canonical_concept_attempts`

Lưu từng Sonnet call.

```php
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
            'canonical_concept_attempts',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table
                    ->uuid(
                        'canonical_concept_revision_id'
                    );

                $table
                    ->unsignedTinyInteger(
                        'attempt_number'
                    );

                $table
                    ->string(
                        'attempt_type',
                        30
                    );

                $table
                    ->string(
                        'status',
                        30
                    );

                $table
                    ->string(
                        'provider',
                        60
                    );

                $table
                    ->string(
                        'model',
                        160
                    );

                $table
                    ->string(
                        'prompt_version',
                        80
                    );

                /*
                 * Hash prompt/input instead of relying
                 * only on mutable application code.
                 */
                $table
                    ->char(
                        'input_hash',
                        64
                    );

                $table
                    ->char(
                        'schema_hash',
                        64
                    );

                /*
                 * Raw Sonnet structured output.
                 */
                $table
                    ->longText('raw_output')
                    ->nullable();

                $table
                    ->char(
                        'raw_output_hash',
                        64
                    )
                    ->nullable();

                /*
                 * Provider metadata.
                 */
                $table
                    ->string(
                        'provider_request_id',
                        255
                    )
                    ->nullable();

                $table
                    ->string(
                        'stop_reason',
                        80
                    )
                    ->nullable();

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
                    ->decimal(
                        'cost_usd',
                        14,
                        8
                    )
                    ->nullable();

                $table
                    ->unsignedInteger(
                        'latency_ms'
                    )
                    ->nullable();

                $table
                    ->string(
                        'error_code',
                        120
                    )
                    ->nullable();

                $table
                    ->text(
                        'error_message'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'started_at'
                    );

                $table
                    ->timestampTz(
                        'completed_at'
                    )
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'canonical_concept_revision_id',
                        'attempt_type',
                        'attempt_number',
                    ],
                    'canonical_attempt_identity_uq'
                );

                $table->index(
                    [
                        'canonical_concept_revision_id',
                        'status',
                    ],
                    'canonical_attempt_revision_status_idx'
                );

                $table
                    ->foreign(
                        'canonical_concept_revision_id',
                        'canonical_attempt_revision_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_revisions')
                    ->cascadeOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'canonical_concept_attempts'
        );
    }
};
```

---

# 10.11 Không dùng attempt number như provider retry

Cực kỳ quan trọng:

```text
GENERATION attempt #1
```

có thể bên trong `LlmClient` có:

```text
HTTP retry #1
HTTP retry #2
```

nhưng DB vẫn là:

```text
semantic attempt = generation #1
```

Tương tự:

```text
REPAIR attempt #1
```

là semantic repair duy nhất.

Không biến network retry thành repair count.

---

# 10.12 Validation runs migration

```php
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
            'canonical_validation_runs',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table
                    ->uuid(
                        'canonical_concept_revision_id'
                    );

                $table
                    ->uuid(
                        'canonical_concept_attempt_id'
                    )
                    ->nullable();

                $table
                    ->string(
                        'stage',
                        80
                    );

                $table
                    ->string(
                        'status',
                        20
                    );

                /*
                 * What exact bytes were validated?
                 */
                $table
                    ->char(
                        'document_hash',
                        64
                    );

                /*
                 * Which validator/schema version?
                 */
                $table
                    ->string(
                        'validator_version',
                        100
                    );

                $table
                    ->json('errors')
                    ->nullable();

                $table
                    ->unsignedInteger(
                        'error_count'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'duration_ms'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'validated_at'
                    );

                $table->timestampsTz();

                $table->index(
                    [
                        'canonical_concept_revision_id',
                        'stage',
                    ],
                    'canonical_validation_revision_stage_idx'
                );

                $table
                    ->foreign(
                        'canonical_concept_revision_id',
                        'canonical_validation_revision_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_revisions')
                    ->cascadeOnDelete();

                $table
                    ->foreign(
                        'canonical_concept_attempt_id',
                        'canonical_validation_attempt_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_attempts')
                    ->nullOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'canonical_validation_runs'
        );
    }
};
```

---

# 10.13 Decision Ledger migration

Đây là ledger semantic.

Không lưu prompt.

```php
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
            'canonical_decisions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table
                    ->uuid(
                        'canonical_concept_revision_id'
                    );

                /*
                 * Stable semantic target.
                 */
                $table
                    ->string(
                        'target_path',
                        500
                    );

                /*
                 * What kind of decision?
                 *
                 * dimension
                 * geometry
                 * relationship
                 * material
                 * exclusion
                 * invariant
                 * etc.
                 */
                $table
                    ->string(
                        'decision_type',
                        80
                    );

                /*
                 * Actual canonical value.
                 */
                $table
                    ->json(
                        'decision_value'
                    );

                /*
                 * Where in pipeline did this ledger
                 * record originate?
                 */
                $table
                    ->string(
                        'decision_origin',
                        30
                    );

                /*
                 * Canonical provenance:
                 * inspired / invented.
                 */
                $table
                    ->string(
                        'provenance_origin',
                        30
                    )
                    ->nullable();

                $table
                    ->json(
                        'source_aspects'
                    )
                    ->nullable();

                /*
                 * Optional invariant links.
                 */
                $table
                    ->json(
                        'invariant_ids'
                    )
                    ->nullable();

                $table
                    ->json(
                        'relationship_ids'
                    )
                    ->nullable();

                /*
                 * Hash exact semantic value.
                 */
                $table
                    ->char(
                        'value_hash',
                        64
                    );

                $table
                    ->unsignedInteger(
                        'ordinal'
                    );

                $table->timestampsTz();

                $table->unique(
                    [
                        'canonical_concept_revision_id',
                        'target_path',
                    ],
                    'canonical_decision_revision_path_uq'
                );

                $table->index(
                    [
                        'canonical_concept_revision_id',
                        'decision_type',
                    ],
                    'canonical_decision_revision_type_idx'
                );

                $table
                    ->foreign(
                        'canonical_concept_revision_id',
                        'canonical_decision_revision_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_revisions')
                    ->cascadeOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'canonical_decisions'
        );
    }
};
```

---

# 10.14 Decision Ledger không phải copy toàn JSON

Ta không muốn:

```text
canonical_decisions
    path = dimensions
    value = toàn bộ dimensions
```

rồi:

```text
path = permanent_geometry
value = toàn bộ geometry
```

Quá coarse.

Ta muốn leaf/semantic-unit:

```text
dimensions.length_m
dimensions.beam_m

permanent_geometry.bow.stem
permanent_geometry.bow.rake_degrees

permanent_geometry.superstructure.primary_tier_count

finished_materials.hull.material

relationships.R001
relationships.R002

exclusions.E001

invariants.I001
```

Decision Ledger sẽ rất hữu ích cho:

```text
Revision diff
↓
Asset projection
↓
Prompt constraint provenance
↓
Vision QA
↓
Repair explanation
```

---

# 10.15 Event log migration

State history phải append-only.

```php
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
            'canonical_concept_events',
            function (Blueprint $table): void {
                $table->bigIncrements('id');

                $table
                    ->uuid(
                        'canonical_concept_revision_id'
                    );

                $table
                    ->string(
                        'event_type',
                        80
                    );

                $table
                    ->string(
                        'from_status',
                        40
                    )
                    ->nullable();

                $table
                    ->string(
                        'to_status',
                        40
                    )
                    ->nullable();

                $table
                    ->json(
                        'metadata'
                    )
                    ->nullable();

                /*
                 * Optional idempotency key for event.
                 */
                $table
                    ->string(
                        'event_key',
                        190
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'occurred_at'
                    );

                $table->timestampsTz();

                $table->unique(
                    [
                        'canonical_concept_revision_id',
                        'event_key',
                    ],
                    'canonical_event_revision_key_uq'
                );

                $table->index(
                    [
                        'canonical_concept_revision_id',
                        'id',
                    ],
                    'canonical_event_revision_order_idx'
                );

                $table
                    ->foreign(
                        'canonical_concept_revision_id',
                        'canonical_event_revision_fk'
                    )
                    ->references('id')
                    ->on('canonical_concept_revisions')
                    ->cascadeOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'canonical_concept_events'
        );
    }
};
```

---

# 10.16 Revision Model

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CanonicalConceptRevision extends Model
{
    use HasUuids;

    protected $table =
        'canonical_concept_revisions';

    protected $guarded = [];

    protected $casts = [
        'revision' => 'integer',

        'status' =>
            CanonicalConceptStatus::class,

        'latest_validation_errors' =>
            'array',

        'repair_count' =>
            'integer',

        'lock_version' =>
            'integer',

        'frozen_at' =>
            'immutable_datetime',

        'failed_at' =>
            'immutable_datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(
            CanonicalConceptAttempt::class,
            'canonical_concept_revision_id'
        );
    }

    public function validations(): HasMany
    {
        return $this->hasMany(
            CanonicalValidationRun::class,
            'canonical_concept_revision_id'
        );
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(
            CanonicalDecision::class,
            'canonical_concept_revision_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            CanonicalConceptEvent::class,
            'canonical_concept_revision_id'
        );
    }

    public function isFrozen(): bool
    {
        return $this->status
            === CanonicalConceptStatus::FROZEN;
    }

    public function assertMutable(): void
    {
        if ($this->isFrozen()) {
            throw new \LogicException(
                sprintf(
                    'Canonical revision %s is frozen.',
                    $this->id
                )
            );
        }
    }
}
```

---

# 10.17 Attempt Model

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalConceptAttempt extends Model
{
    use HasUuids;

    protected $table =
        'canonical_concept_attempts';

    protected $guarded = [];

    protected $casts = [
        'attempt_number' =>
            'integer',

        'attempt_type' =>
            CanonicalAttemptType::class,

        'status' =>
            CanonicalAttemptStatus::class,

        'input_tokens' =>
            'integer',

        'output_tokens' =>
            'integer',

        'cost_usd' =>
            'decimal:8',

        'latency_ms' =>
            'integer',

        'started_at' =>
            'immutable_datetime',

        'completed_at' =>
            'immutable_datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            CanonicalConceptRevision::class,
            'canonical_concept_revision_id'
        );
    }
}
```

---

# 10.18 Validation Model

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\ValidationRunStatus;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalValidationRun extends Model
{
    use HasUuids;

    protected $table =
        'canonical_validation_runs';

    protected $guarded = [];

    protected $casts = [
        'stage' =>
            ValidationStage::class,

        'status' =>
            ValidationRunStatus::class,

        'errors' =>
            'array',

        'error_count' =>
            'integer',

        'duration_ms' =>
            'integer',

        'validated_at' =>
            'immutable_datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            CanonicalConceptRevision::class,
            'canonical_concept_revision_id'
        );
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(
            CanonicalConceptAttempt::class,
            'canonical_concept_attempt_id'
        );
    }
}
```

---

# 10.19 Decision Model

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalDecision extends Model
{
    use HasUuids;

    protected $table =
        'canonical_decisions';

    protected $guarded = [];

    protected $casts = [
        'decision_value' =>
            'array',

        'decision_origin' =>
            DecisionOrigin::class,

        'provenance_origin' =>
            ProvenanceOrigin::class,

        'source_aspects' =>
            'array',

        'invariant_ids' =>
            'array',

        'relationship_ids' =>
            'array',

        'ordinal' =>
            'integer',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            CanonicalConceptRevision::class,
            'canonical_concept_revision_id'
        );
    }
}
```

Có một nuance: scalar JSON với Eloquent `array` cast không lý tưởng.

Vì `decision_value` có thể là:

```json
120
```

hoặc:

```json
"near_plumb"
```

nên production tốt hơn dùng custom cast.

---

# 10.20 `JsonValueCast`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

final class JsonValueCast implements CastsAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): mixed {
        if ($value === null) {
            return null;
        }

        try {
            return json_decode(
                (string) $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new \RuntimeException(
                "Invalid JSON in {$key}.",
                previous: $e
            );
        }
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): string {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new \RuntimeException(
                "Unable to encode {$key}.",
                previous: $e
            );
        }
    }
}
```

Model đổi:

```php
use App\Video\Concept\Persistence\Casts\JsonValueCast;

protected $casts = [
    'decision_value' =>
        JsonValueCast::class,

    // ...
];
```

---

# 10.21 Event Model

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalConceptEvent extends Model
{
    protected $table =
        'canonical_concept_events';

    protected $guarded = [];

    protected $casts = [
        'event_type' =>
            CanonicalEventType::class,

        'from_status' =>
            CanonicalConceptStatus::class,

        'to_status' =>
            CanonicalConceptStatus::class,

        'metadata' =>
            'array',

        'occurred_at' =>
            'immutable_datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            CanonicalConceptRevision::class,
            'canonical_concept_revision_id'
        );
    }
}
```

---

# 10.22 State transition exception

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\StateMachine;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use RuntimeException;

final class InvalidCanonicalStateTransition
    extends RuntimeException
{
    public static function between(
        CanonicalConceptStatus $from,
        CanonicalConceptStatus $to,
    ): self {
        return new self(
            sprintf(
                'Invalid canonical concept state transition: %s -> %s.',
                $from->value,
                $to->value
            )
        );
    }
}
```

---

# 10.23 State Machine đầy đủ

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\StateMachine;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;

final class CanonicalConceptStateMachine
{
    /**
     * @var array<string,list<CanonicalConceptStatus>>
     */
    private const TRANSITIONS = [

        'pending' => [
            CanonicalConceptStatus::GENERATING,
            CanonicalConceptStatus::FAILED,
        ],

        'generating' => [
            CanonicalConceptStatus::GENERATED,
            CanonicalConceptStatus::FAILED,
        ],

        'generated' => [
            CanonicalConceptStatus::VALIDATING,
            CanonicalConceptStatus::FAILED,
        ],

        'validating' => [
            CanonicalConceptStatus::VALIDATION_FAILED,
            CanonicalConceptStatus::NORMALIZING,
            CanonicalConceptStatus::FAILED,
        ],

        'validation_failed' => [
            CanonicalConceptStatus::REPAIRING,
            CanonicalConceptStatus::FAILED,
        ],

        'repairing' => [
            CanonicalConceptStatus::REPAIRED,
            CanonicalConceptStatus::FAILED,
        ],

        'repaired' => [
            CanonicalConceptStatus::VALIDATING,
            CanonicalConceptStatus::FAILED,
        ],

        'normalizing' => [
            CanonicalConceptStatus::NORMALIZED,
            CanonicalConceptStatus::FAILED,
        ],

        'normalized' => [
            CanonicalConceptStatus::FREEZING,
            CanonicalConceptStatus::FAILED,
        ],

        'freezing' => [
            CanonicalConceptStatus::FROZEN,
            CanonicalConceptStatus::FAILED,
        ],

        /*
         * Terminal.
         */
        'frozen' => [],

        'failed' => [],
    ];

    public function canTransition(
        CanonicalConceptStatus $from,
        CanonicalConceptStatus $to,
    ): bool {
        return in_array(
            $to,
            self::TRANSITIONS[$from->value]
                ?? [],
            true
        );
    }

    public function assertTransition(
        CanonicalConceptStatus $from,
        CanonicalConceptStatus $to,
    ): void {
        if (!$this->canTransition($from, $to)) {
            throw InvalidCanonicalStateTransition
                ::between(
                    $from,
                    $to
                );
        }
    }
}
```

---

# 10.24 Một điểm quan trọng về `NORMALIZING`

Processor Phần 9 hiện chạy:

```text
validate
normalize
revalidate
```

trong một method.

Nếu persistence chỉ wrap bên ngoài thì state machine không nhìn thấy chính xác boundary.

Vì vậy production nên cho processor emit checkpoints.

Nhưng **không cho processor biết DB**.

Ta dùng observer/interface.

---

# 10.25 `CanonicalProcessingObserver`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

use App\Video\Concept\Validation\ValidationResult;

interface CanonicalProcessingObserver
{
    public function validationStarted(
        string $stage
    ): void;

    public function validationCompleted(
        string $stage,
        ValidationResult $result,
        string $documentHash,
        string $validatorVersion,
        int $durationMs,
    ): void;

    public function normalizationStarted(): void;

    public function normalizationCompleted(
        string $canonicalJson
    ): void;
}
```

Null implementation:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

use App\Video\Concept\Validation\ValidationResult;

final class NullCanonicalProcessingObserver
    implements CanonicalProcessingObserver
{
    public function validationStarted(
        string $stage
    ): void {
    }

    public function validationCompleted(
        string $stage,
        ValidationResult $result,
        string $documentHash,
        string $validatorVersion,
        int $durationMs,
    ): void {
    }

    public function normalizationStarted(): void
    {
    }

    public function normalizationCompleted(
        string $canonicalJson
    ): void {
    }
}
```

Như vậy:

```text
CanonicalConceptProcessor
```

vẫn pure application/domain service.

Không import Eloquent.

---

# 10.26 Revision identity DTO

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\DTO;

use InvalidArgumentException;

final class CanonicalRevisionIdentity
{
    public function __construct(
        public readonly string $projectId,
        public readonly ?string $sessionId,
        public readonly int $revision,
    ) {
        if ($projectId === '') {
            throw new InvalidArgumentException(
                'projectId must not be empty.'
            );
        }

        if ($revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }
    }
}
```

---

# 10.27 Attempt usage DTO

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\DTO;

final class AttemptUsage
{
    public function __construct(
        public readonly ?string $requestId,
        public readonly ?string $stopReason,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?string $costUsd,
        public readonly ?int $latencyMs,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            requestId: null,
            stopReason: null,
            inputTokens: null,
            outputTokens: null,
            costUsd: null,
            latencyMs: null,
        );
    }
}
```

---

# 10.28 Repository interface

Persistence service không nên query Eloquent lung tung.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Repository;

use App\Video\Concept\Persistence\DTO\CanonicalRevisionIdentity;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

interface CanonicalConceptRepository
{
    public function createRevision(
        CanonicalRevisionIdentity $identity,
        string $objectType,
        string $profileKey,
        string $profileVersion,
        string $schemaVersion,
        string $conceptModel,
        string $promptVersion,
    ): CanonicalConceptRevision;

    public function findRevision(
        string $id
    ): ?CanonicalConceptRevision;

    public function lockRevision(
        string $id
    ): CanonicalConceptRevision;

    public function latestRevisionNumber(
        string $projectId
    ): int;

    public function transition(
        CanonicalConceptRevision $revision,
        CanonicalConceptStatus $to,
        array $attributes = [],
    ): CanonicalConceptRevision;
}
```

---

# 10.29 Eloquent repository

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Repository;

use App\Video\Concept\Persistence\DTO\CanonicalRevisionIdentity;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\StateMachine\CanonicalConceptStateMachine;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class EloquentCanonicalConceptRepository
    implements CanonicalConceptRepository
{
    public function __construct(
        private readonly CanonicalConceptStateMachine $stateMachine,
    ) {
    }

    public function createRevision(
        CanonicalRevisionIdentity $identity,
        string $objectType,
        string $profileKey,
        string $profileVersion,
        string $schemaVersion,
        string $conceptModel,
        string $promptVersion,
    ): CanonicalConceptRevision {
        return CanonicalConceptRevision::query()
            ->create([
                'video_project_id' =>
                    $identity->projectId,

                'video_session_id' =>
                    $identity->sessionId,

                'revision' =>
                    $identity->revision,

                'status' =>
                    CanonicalConceptStatus::PENDING,

                'object_type' =>
                    $objectType,

                'profile_key' =>
                    $profileKey,

                'profile_version' =>
                    $profileVersion,

                'canonical_schema_version' =>
                    $schemaVersion,

                'concept_model' =>
                    $conceptModel,

                'concept_prompt_version' =>
                    $promptVersion,

                'repair_count' =>
                    0,

                'lock_version' =>
                    0,
            ]);
    }

    public function findRevision(
        string $id
    ): ?CanonicalConceptRevision {
        return CanonicalConceptRevision::query()
            ->find($id);
    }

    public function lockRevision(
        string $id
    ): CanonicalConceptRevision {
        $revision =
            CanonicalConceptRevision::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

        if ($revision === null) {
            throw (
                new ModelNotFoundException()
            )->setModel(
                CanonicalConceptRevision::class,
                [$id]
            );
        }

        return $revision;
    }

    public function latestRevisionNumber(
        string $projectId
    ): int {
        return (int)
            CanonicalConceptRevision::query()
                ->where(
                    'video_project_id',
                    $projectId
                )
                ->max('revision');
    }

    public function transition(
        CanonicalConceptRevision $revision,
        CanonicalConceptStatus $to,
        array $attributes = [],
    ): CanonicalConceptRevision {
        $from =
            $revision->status;

        $this->stateMachine
            ->assertTransition(
                $from,
                $to
            );

        if (
            $from
            === CanonicalConceptStatus::FROZEN
        ) {
            throw new \LogicException(
                'Frozen canonical revision '
                . 'cannot be mutated.'
            );
        }

        $revision->fill(
            array_merge(
                $attributes,
                [
                    'status' =>
                        $to,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ]
            )
        );

        $revision->save();

        return $revision->refresh();
    }
}
```

---

# 10.30 Race condition khi cấp revision number

Không được:

```php
$revision =
    latestRevisionNumber($projectId) + 1;
```

ngoài transaction.

Hai workers có thể cùng nhận `4`.

Production phải lock `video_projects`.

Ví dụ service:

```php
private function allocateRevision(
    string $projectId
): int {
    $project =
        VideoProject::query()
            ->whereKey($projectId)
            ->lockForUpdate()
            ->firstOrFail();

    $latest =
        CanonicalConceptRevision::query()
            ->where(
                'video_project_id',
                $project->id
            )
            ->max('revision');

    return ((int) $latest) + 1;
}
```

Nó phải chạy trong:

```php
DB::transaction(...)
```

Unique index vẫn là lớp bảo vệ cuối cùng.

---

# 10.31 Event writer

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptEvent;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Support\Clock;

final class CanonicalEventWriter
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function append(
        CanonicalConceptRevision $revision,
        CanonicalEventType $type,
        ?CanonicalConceptStatus $from = null,
        ?CanonicalConceptStatus $to = null,
        array $metadata = [],
        ?string $eventKey = null,
    ): CanonicalConceptEvent {
        if ($eventKey !== null) {
            $existing =
                CanonicalConceptEvent::query()
                    ->where(
                        'canonical_concept_revision_id',
                        $revision->id
                    )
                    ->where(
                        'event_key',
                        $eventKey
                    )
                    ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return CanonicalConceptEvent::query()
            ->create([
                'canonical_concept_revision_id' =>
                    $revision->id,

                'event_type' =>
                    $type,

                'from_status' =>
                    $from,

                'to_status' =>
                    $to,

                'metadata' =>
                    $metadata ?: null,

                'event_key' =>
                    $eventKey,

                'occurred_at' =>
                    $this->clock->now(),
            ]);
    }
}
```

---

# 10.32 Atomic transition + event

Không nên:

```text
UPDATE revision
COMMIT

... crash ...

INSERT event
```

State và event phải cùng transaction.

Tạo coordinator:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;

final class CanonicalStateTransitionService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalEventWriter $events,
    ) {
    }

    public function transition(
        string $revisionId,
        CanonicalConceptStatus $to,
        CanonicalEventType $eventType,
        array $attributes = [],
        array $metadata = [],
        ?string $eventKey = null,
    ): CanonicalConceptRevision {
        return DB::transaction(
            function () use (
                $revisionId,
                $to,
                $eventType,
                $attributes,
                $metadata,
                $eventKey,
            ): CanonicalConceptRevision {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                $from =
                    $revision->status;

                /*
                 * Idempotent replay:
                 * already at target state.
                 */
                if ($from === $to) {
                    return $revision;
                }

                $revision =
                    $this->repository
                        ->transition(
                            $revision,
                            $to,
                            $attributes
                        );

                $this->events
                    ->append(
                        revision:
                            $revision,

                        type:
                            $eventType,

                        from:
                            $from,

                        to:
                            $to,

                        metadata:
                            $metadata,

                        eventKey:
                            $eventKey,
                    );

                return $revision;
            },
            attempts: 3
        );
    }
}
```

---

# 10.33 Nhưng idempotency không được chỉ dựa vào state

Ví dụ worker retry:

```text
status = FROZEN
```

thì không được chạy Sonnet lại.

Đúng.

Nhưng:

```text
status = GENERATED
```

sau crash thì cũng không nên gọi generation lại nếu raw output đã lưu.

Do đó mỗi stage phải checkpoint artifact trước khi transition tiếp.

Flow:

```text
GENERATION_STARTED
↓
provider
↓
persist raw output
↓
GENERATION_SUCCEEDED
↓
GENERATED
```

Nếu crash sau persist output:

```text
resume
↓
raw output exists
↓
skip provider call
↓
continue validation
```

---

# 10.34 Attempt writer

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\DTO\AttemptUsage;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Support\Clock;

final class CanonicalAttemptWriter
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function start(
        CanonicalConceptRevision $revision,
        CanonicalAttemptType $type,
        int $number,
        string $provider,
        string $model,
        string $promptVersion,
        string $inputHash,
        string $schemaHash,
    ): CanonicalConceptAttempt {
        return CanonicalConceptAttempt::query()
            ->firstOrCreate(
                [
                    'canonical_concept_revision_id' =>
                        $revision->id,

                    'attempt_type' =>
                        $type->value,

                    'attempt_number' =>
                        $number,
                ],
                [
                    'status' =>
                        CanonicalAttemptStatus::STARTED->value,

                    'provider' =>
                        $provider,

                    'model' =>
                        $model,

                    'prompt_version' =>
                        $promptVersion,

                    'input_hash' =>
                        $inputHash,

                    'schema_hash' =>
                        $schemaHash,

                    'started_at' =>
                        $this->clock->now(),
                ]
            );
    }

    public function succeed(
        CanonicalConceptAttempt $attempt,
        string $rawOutput,
        AttemptUsage $usage,
    ): void {
        if (
            $attempt->status
            === CanonicalAttemptStatus::SUCCEEDED
        ) {
            /*
             * Immutable successful attempt.
             */
            return;
        }

        $attempt->forceFill([
            'status' =>
                CanonicalAttemptStatus::SUCCEEDED,

            'raw_output' =>
                $rawOutput,

            'raw_output_hash' =>
                hash(
                    'sha256',
                    $rawOutput
                ),

            'provider_request_id' =>
                $usage->requestId,

            'stop_reason' =>
                $usage->stopReason,

            'input_tokens' =>
                $usage->inputTokens,

            'output_tokens' =>
                $usage->outputTokens,

            'cost_usd' =>
                $usage->costUsd,

            'latency_ms' =>
                $usage->latencyMs,

            'completed_at' =>
                $this->clock->now(),
        ])->save();
    }

    public function fail(
        CanonicalConceptAttempt $attempt,
        string $errorCode,
        string $message,
        ?AttemptUsage $usage = null,
    ): void {
        if (
            $attempt->status
            !== CanonicalAttemptStatus::STARTED
        ) {
            return;
        }

        $usage ??=
            AttemptUsage::empty();

        $attempt->forceFill([
            'status' =>
                CanonicalAttemptStatus::FAILED,

            'provider_request_id' =>
                $usage->requestId,

            'stop_reason' =>
                $usage->stopReason,

            'input_tokens' =>
                $usage->inputTokens,

            'output_tokens' =>
                $usage->outputTokens,

            'cost_usd' =>
                $usage->costUsd,

            'latency_ms' =>
                $usage->latencyMs,

            'error_code' =>
                $errorCode,

            'error_message' =>
                $message,

            'completed_at' =>
                $this->clock->now(),
        ])->save();
    }
}
```

---

# 10.35 Validation recorder

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\ValidationRunStatus;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Models\CanonicalValidationRun;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Validation\ValidationResult;

final class CanonicalValidationRecorder
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function record(
        CanonicalConceptRevision $revision,
        ?CanonicalConceptAttempt $attempt,
        ValidationStage $stage,
        ValidationResult $result,
        string $documentHash,
        string $validatorVersion,
        ?int $durationMs = null,
    ): CanonicalValidationRun {
        $errors =
            array_map(
                static fn ($error): array =>
                    $error->toArray(),
                $result->errors
            );

        return CanonicalValidationRun::query()
            ->create([
                'canonical_concept_revision_id' =>
                    $revision->id,

                'canonical_concept_attempt_id' =>
                    $attempt?->id,

                'stage' =>
                    $stage,

                'status' =>
                    $result->passes()
                        ? ValidationRunStatus::PASSED
                        : ValidationRunStatus::FAILED,

                'document_hash' =>
                    $documentHash,

                'validator_version' =>
                    $validatorVersion,

                'errors' =>
                    $errors === []
                        ? null
                        : $errors,

                'error_count' =>
                    count($errors),

                'duration_ms' =>
                    $durationMs,

                'validated_at' =>
                    $this->clock->now(),
            ]);
    }
}
```

---

# 10.36 Decision extractor

Đây là phần rất quan trọng.

Ta extract ledger **sau normalization/revalidation**, không extract từ raw Sonnet.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Ledger;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class CanonicalDecisionExtractor
{
    /**
     * @return list<array{
     *   target_path:string,
     *   decision_type:string,
     *   value:mixed,
     *   provenance_origin:?string,
     *   source_aspects:list<string>,
     *   invariant_ids:list<string>,
     *   relationship_ids:list<string>
     * }>
     */
    public function extract(
        CanonicalDesignSpec $spec
    ): array {
        $data =
            $spec->toArray();

        $provenance =
            $this->provenanceIndex(
                $data['provenance']
            );

        $invariants =
            $this->invariantIndex(
                $data['invariants']
            );

        $relationships =
            $this->relationshipIndex(
                $data['relationships']
            );

        $result = [];

        /*
         * Identity.
         */
        $this->flatten(
            value:
                $data['identity'],

            path:
                'identity',

            type:
                'identity',

            provenance:
                $provenance,

            invariants:
                $invariants,

            relationships:
                $relationships,

            output:
                $result,
        );

        /*
         * Dimensions.
         */
        $this->flatten(
            $data['dimensions'],
            'dimensions',
            'dimension',
            $provenance,
            $invariants,
            $relationships,
            $result,
        );

        /*
         * Permanent geometry.
         */
        $this->flatten(
            $data['permanent_geometry'],
            'permanent_geometry',
            'geometry',
            $provenance,
            $invariants,
            $relationships,
            $result,
        );

        /*
         * Higher form relationships.
         */
        $this->flatten(
            $data['form_relationships'],
            'form_relationships',
            'form_relationship',
            $provenance,
            $invariants,
            $relationships,
            $result,
        );

        /*
         * Materials.
         */
        $this->flatten(
            $data['finished_materials'],
            'finished_materials',
            'material',
            $provenance,
            $invariants,
            $relationships,
            $result,
        );

        /*
         * Structured relationships themselves
         * are semantic decisions.
         */
        foreach (
            $data['relationships']
            as $relationship
        ) {
            $id =
                (string)
                $relationship['id'];

            $result[] =
                $this->makeDecision(
                    targetPath:
                        'relationships.'
                        . $id,

                    decisionType:
                        'relationship',

                    value:
                        $relationship,

                    provenance:
                        $provenance,

                    invariants:
                        $invariants,

                    relationships:
                        $relationships,
                );
        }

        foreach (
            $data['exclusions']
            as $exclusion
        ) {
            $id =
                (string)
                $exclusion['id'];

            $result[] =
                $this->makeDecision(
                    targetPath:
                        'exclusions.'
                        . $id,

                    decisionType:
                        'exclusion',

                    value:
                        $exclusion,

                    provenance:
                        $provenance,

                    invariants:
                        $invariants,

                    relationships:
                        $relationships,
                );
        }

        foreach (
            $data['invariants']
            as $invariant
        ) {
            $id =
                (string)
                $invariant['id'];

            $result[] =
                $this->makeDecision(
                    targetPath:
                        'invariants.'
                        . $id,

                    decisionType:
                        'invariant',

                    value:
                        $invariant,

                    provenance:
                        $provenance,

                    invariants:
                        $invariants,

                    relationships:
                        $relationships,
                );
        }

        usort(
            $result,
            static fn (
                array $a,
                array $b
            ): int =>
                strcmp(
                    $a['target_path'],
                    $b['target_path']
                )
        );

        return array_values(
            $result
        );
    }

    private function flatten(
        mixed $value,
        string $path,
        string $type,
        array $provenance,
        array $invariants,
        array $relationships,
        array &$output,
    ): void {
        /*
         * Associative object:
         * recurse into semantic leaves.
         */
        if (
            is_array($value)
            && !array_is_list($value)
            && $value !== []
        ) {
            foreach (
                $value
                as $key => $child
            ) {
                $this->flatten(
                    value:
                        $child,

                    path:
                        $path
                        . '.'
                        . $key,

                    type:
                        $type,

                    provenance:
                        $provenance,

                    invariants:
                        $invariants,

                    relationships:
                        $relationships,

                    output:
                        $output,
                );
            }

            return;
        }

        /*
         * Lists remain one semantic value.
         *
         * Example identity_basis.
         *
         * Do not invent index paths unless
         * list members have their own stable IDs.
         */
        $output[] =
            $this->makeDecision(
                targetPath:
                    $path,

                decisionType:
                    $type,

                value:
                    $value,

                provenance:
                    $provenance,

                invariants:
                    $invariants,

                relationships:
                    $relationships,
            );
    }

    private function makeDecision(
        string $targetPath,
        string $decisionType,
        mixed $value,
        array $provenance,
        array $invariants,
        array $relationships,
    ): array {
        $source =
            $provenance[$targetPath]
            ?? null;

        return [
            'target_path' =>
                $targetPath,

            'decision_type' =>
                $decisionType,

            'value' =>
                $value,

            'provenance_origin' =>
                $source['origin']
                ?? null,

            'source_aspects' =>
                $source['source_aspects']
                ?? [],

            'invariant_ids' =>
                $invariants[$targetPath]
                ?? [],

            'relationship_ids' =>
                $relationships[$targetPath]
                ?? [],
        ];
    }

    private function provenanceIndex(
        array $entries
    ): array {
        $index = [];

        foreach ($entries as $entry) {
            $index[
                $entry['target_path']
            ] = [
                'origin' =>
                    $entry['origin'],

                'source_aspects' =>
                    $entry['source_aspects']
                    ?? [],
            ];
        }

        return $index;
    }

    private function invariantIndex(
        array $entries
    ): array {
        $index = [];

        foreach ($entries as $entry) {
            $path =
                $entry['source_path'];

            $index[$path] ??= [];

            $index[$path][] =
                $entry['id'];
        }

        return $index;
    }

    private function relationshipIndex(
        array $relationships
    ): array {
        $index = [];

        foreach (
            $relationships
            as $relationship
        ) {
            $id =
                $relationship['id'];

            foreach (
                $this->referencedPaths(
                    $relationship
                )
                as $path
            ) {
                $index[$path] ??= [];

                $index[$path][] =
                    $id;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function referencedPaths(
        array $relationship
    ): array {
        $paths = [];

        foreach (
            [
                'subject_path',
                'source_path',
                'target_path',
                'reference_path',
                'start_path',
                'end_path',
                'container_path',
                'contained_path',
                'grouped_into_path',
            ]
            as $key
        ) {
            if (
                isset($relationship[$key])
                && is_string(
                    $relationship[$key]
                )
                && $relationship[$key] !== ''
            ) {
                $paths[] =
                    $relationship[$key];
            }
        }

        foreach (
            ['members', 'items']
            as $key
        ) {
            if (
                !isset($relationship[$key])
                || !is_array(
                    $relationship[$key]
                )
            ) {
                continue;
            }

            foreach (
                $relationship[$key]
                as $path
            ) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(
            array_unique(
                $paths
            )
        );
    }
}
```

---

# 10.37 Ledger writer

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Ledger;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalDecision;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use JsonException;

final class DecisionLedgerWriter
{
    public function __construct(
        private readonly CanonicalDecisionExtractor $extractor,
    ) {
    }

    public function write(
        CanonicalConceptRevision $revision,
        CanonicalDesignSpec $spec,
        DecisionOrigin $origin,
    ): void {
        if ($revision->isFrozen()) {
            throw new \LogicException(
                'Cannot rewrite Decision Ledger '
                . 'after revision is frozen.'
            );
        }

        $decisions =
            $this->extractor
                ->extract(
                    $spec
                );

        /*
         * Ledger for a revision is written once,
         * immediately before freeze.
         */
        if (
            CanonicalDecision::query()
                ->where(
                    'canonical_concept_revision_id',
                    $revision->id
                )
                ->exists()
        ) {
            throw new \LogicException(
                'Decision Ledger already exists '
                . 'for canonical revision '
                . $revision->id
            );
        }

        foreach (
            $decisions
            as $ordinal => $decision
        ) {
            CanonicalDecision::query()
                ->create([
                    'canonical_concept_revision_id' =>
                        $revision->id,

                    'target_path' =>
                        $decision['target_path'],

                    'decision_type' =>
                        $decision['decision_type'],

                    'decision_value' =>
                        $decision['value'],

                    'decision_origin' =>
                        $origin,

                    'provenance_origin' =>
                        $decision[
                            'provenance_origin'
                        ],

                    'source_aspects' =>
                        $decision[
                            'source_aspects'
                        ] ?: null,

                    'invariant_ids' =>
                        $decision[
                            'invariant_ids'
                        ] ?: null,

                    'relationship_ids' =>
                        $decision[
                            'relationship_ids'
                        ] ?: null,

                    'value_hash' =>
                        $this->hashValue(
                            $decision['value']
                        ),

                    'ordinal' =>
                        $ordinal,
                ]);
        }
    }

    private function hashValue(
        mixed $value
    ): string {
        try {
            $json =
                json_encode(
                    $value,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                );
        } catch (JsonException $e) {
            throw new \RuntimeException(
                'Unable to hash Decision Ledger value.',
                previous: $e
            );
        }

        return hash(
            'sha256',
            $json
        );
    }
}
```

Có một cải tiến quan trọng nữa: về sau `value_hash` nên dùng cùng canonical value serializer thay vì `json_encode()` độc lập. Nhưng vì `decision_value` là sub-value đã extract từ **frozen normalized DTO**, nó không được dùng làm canonical design hash. `canonical_hash` vẫn là source of truth.

---

# 10.38 Persisted result DTO

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;

final class PersistedCanonicalConcept
{
    public function __construct(
        public readonly string $revisionId,
        public readonly FrozenCanonicalConcept $frozen,
        public readonly bool $reused,
    ) {
    }
}
```

---

# 10.39 Freeze transaction

Đây là đoạn quan trọng nhất của toàn Phần 10.

Freeze phải atomic:

```text
lock revision
↓
verify status
↓
verify hash(canonical_json)
↓
write Decision Ledger
↓
write frozen canonical JSON/hash/metadata
↓
transition FROZEN
↓
append FROZEN event
↓
COMMIT
```

Không được freeze từng phần.

---

# 10.40 `CanonicalFreezePersistenceService`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Ledger\DecisionLedgerWriter;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CanonicalFreezePersistenceService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly DecisionLedgerWriter $ledger,
        private readonly CanonicalEventWriter $events,
        private readonly CanonicalDesignSpecHasher $hasher,
    ) {
    }

    public function freeze(
        string $revisionId,
        FrozenCanonicalConcept $frozen,
        DecisionOrigin $decisionOrigin,
    ): PersistedCanonicalConcept {
        return DB::transaction(
            function () use (
                $revisionId,
                $frozen,
                $decisionOrigin,
            ): PersistedCanonicalConcept {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                /*
                 * ----------------------------------
                 * IDEMPOTENT REPLAY
                 * ----------------------------------
                 */
                if (
                    $revision->status
                    === CanonicalConceptStatus::FROZEN
                ) {
                    if (
                        !hash_equals(
                            (string)
                            $revision->canonical_hash,

                            $frozen->hash
                        )
                    ) {
                        throw new RuntimeException(
                            'Frozen revision replay '
                            . 'contains a different hash.'
                        );
                    }

                    if (
                        !hash_equals(
                            hash(
                                'sha256',
                                (string)
                                $revision->canonical_json
                            ),
                            $frozen->hash
                        )
                    ) {
                        throw new RuntimeException(
                            'Persisted frozen canonical JSON '
                            . 'failed integrity verification.'
                        );
                    }

                    return new PersistedCanonicalConcept(
                        revisionId:
                            $revision->id,

                        frozen:
                            $frozen,

                        reused:
                            true,
                    );
                }

                if (
                    $revision->status
                    !== CanonicalConceptStatus::FREEZING
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Revision must be in freezing '
                            . 'state before final freeze; '
                            . 'current=%s.',
                            $revision->status->value
                        )
                    );
                }

                /*
                 * ----------------------------------
                 * BYTE INTEGRITY
                 * ----------------------------------
                 */
                $computed =
                    $this->hasher
                        ->hash(
                            $frozen->canonicalJson
                        );

                if (
                    !hash_equals(
                        $computed,
                        $frozen->hash
                    )
                ) {
                    throw new RuntimeException(
                        'Frozen canonical hash mismatch.'
                    );
                }

                /*
                 * ----------------------------------
                 * WRITE DECISION LEDGER
                 * ----------------------------------
                 */
                $this->ledger
                    ->write(
                        revision:
                            $revision,

                        spec:
                            $frozen->spec,

                        origin:
                            $decisionOrigin,
                    );

                /*
                 * ----------------------------------
                 * WRITE IMMUTABLE TRUTH
                 * ----------------------------------
                 */
                $revision->forceFill([
                    'canonical_json' =>
                        $frozen->canonicalJson,

                    'canonical_hash' =>
                        $frozen->hash,

                    'effective_schema_hash' =>
                        $frozen
                            ->metadata
                            ->effectiveSchemaHash,

                    'canonical_schema_version' =>
                        $frozen
                            ->metadata
                            ->canonicalSchemaVersion,

                    'profile_key' =>
                        $frozen
                            ->metadata
                            ->profileKey,

                    'profile_version' =>
                        $frozen
                            ->metadata
                            ->profileVersion,

                    'semantic_validator_version' =>
                        $frozen
                            ->metadata
                            ->semanticValidatorVersion,

                    'normalizer_version' =>
                        $frozen
                            ->metadata
                            ->normalizerVersion,

                    'canonicalizer_version' =>
                        $frozen
                            ->metadata
                            ->canonicalizerVersion,

                    'concept_model' =>
                        $frozen
                            ->metadata
                            ->conceptModel,

                    'concept_prompt_version' =>
                        $frozen
                            ->metadata
                            ->conceptPromptVersion,

                    'frozen_at' =>
                        $frozen->frozenAt,

                    'status' =>
                        CanonicalConceptStatus::FROZEN,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();

                $this->events
                    ->append(
                        revision:
                            $revision,

                        type:
                            CanonicalEventType::FROZEN,

                        from:
                            CanonicalConceptStatus::FREEZING,

                        to:
                            CanonicalConceptStatus::FROZEN,

                        metadata: [
                            'canonical_hash' =>
                                $frozen->hash,

                            'effective_schema_hash' =>
                                $frozen
                                    ->metadata
                                    ->effectiveSchemaHash,

                            'revision' =>
                                $frozen->revision,
                        ],

                        eventKey:
                            'frozen:'
                            . $frozen->hash,
                    );

                return new PersistedCanonicalConcept(
                    revisionId:
                        $revision->id,

                    frozen:
                        $frozen,

                    reused:
                        false,
                );
            },
            attempts: 3
        );
    }
}
```

---

# 10.41 DB-level immutable protection

Application protection chưa đủ.

Có thể ai đó:

```php
$revision->canonical_json = '...';
$revision->save();
```

sau khi frozen.

Model phải chặn.

Trong `CanonicalConceptRevision`:

```php
protected static function booted(): void
{
    static::updating(
        function (
            CanonicalConceptRevision $model
        ): void {
            $originalStatus =
                $model->getOriginal('status');

            if (
                $originalStatus
                === CanonicalConceptStatus::FROZEN->value
            ) {
                $protected = [
                    'canonical_json',
                    'canonical_hash',
                    'revision',
                    'video_project_id',
                    'object_type',
                    'profile_key',
                    'profile_version',
                    'canonical_schema_version',
                    'effective_schema_hash',
                    'semantic_validator_version',
                    'normalizer_version',
                    'canonicalizer_version',
                    'concept_model',
                    'concept_prompt_version',
                    'frozen_at',
                ];

                foreach ($protected as $column) {
                    if ($model->isDirty($column)) {
                        throw new \LogicException(
                            sprintf(
                                'Frozen canonical field '
                                . '"%s" is immutable.',
                                $column
                            )
                        );
                    }
                }

                if (
                    $model->isDirty('status')
                ) {
                    throw new \LogicException(
                        'Frozen canonical revision '
                        . 'cannot leave frozen state.'
                    );
                }
            }
        }
    );

    static::deleting(
        function (
            CanonicalConceptRevision $model
        ): void {
            if ($model->isFrozen()) {
                throw new \LogicException(
                    'Frozen canonical revision '
                    . 'cannot be deleted through '
                    . 'the application model.'
                );
            }
        }
    );
}
```

Đây là application-level immutability.

Nếu bạn muốn mức cực cao, sau này thêm DB trigger. Nhưng tôi chưa khuyên dùng trigger ở V1 vì làm deployment/test phức tạp hơn.

---

# 10.42 Checkpoint service

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;

final class CanonicalCheckpointService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
    ) {
    }

    /**
     * Save raw provider output before further work.
     */
    public function rawOutput(
        string $revisionId,
        string $rawJson,
    ): CanonicalConceptRevision {
        return DB::transaction(
            function () use (
                $revisionId,
                $rawJson,
            ): CanonicalConceptRevision {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                $revision->assertMutable();

                $revision->forceFill([
                    'latest_raw_json' =>
                        $rawJson,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();

                return $revision->refresh();
            }
        );
    }

    public function validationFailure(
        string $revisionId,
        array $errors,
    ): CanonicalConceptRevision {
        return DB::transaction(
            function () use (
                $revisionId,
                $errors,
            ): CanonicalConceptRevision {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                $revision->assertMutable();

                $revision->forceFill([
                    'latest_validation_errors' =>
                        $errors,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();

                return $revision->refresh();
            }
        );
    }

    public function clearValidationErrors(
        string $revisionId
    ): void {
        DB::transaction(
            function () use (
                $revisionId
            ): void {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                $revision->assertMutable();

                $revision->forceFill([
                    'latest_validation_errors' =>
                        null,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();
            }
        );
    }
}
```

---

# 10.43 Repair count phải atomic

Không làm:

```php
if ($revision->repair_count < 1) {
    $revision->repair_count++;
}
```

ngoài lock.

Service:

```php
public function claimRepair(
    string $revisionId
): void {
    DB::transaction(
        function () use ($revisionId): void {
            $revision =
                $this->repository
                    ->lockRevision(
                        $revisionId
                    );

            if ($revision->repair_count >= 1) {
                throw new \RuntimeException(
                    'Canonical semantic repair '
                    . 'limit already exhausted.'
                );
            }

            $revision->forceFill([
                'repair_count' =>
                    1,

                'lock_version' =>
                    $revision->lock_version + 1,
            ])->save();
        }
    );
}
```

Như vậy hai workers không thể cùng repair.

---

# 10.44 Failure service

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use App\Video\Concept\Support\Clock;
use Illuminate\Support\Facades\DB;

final class CanonicalFailureService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalEventWriter $events,
        private readonly Clock $clock,
    ) {
    }

    public function fail(
        string $revisionId,
        string $code,
        string $message,
    ): void {
        DB::transaction(
            function () use (
                $revisionId,
                $code,
                $message,
            ): void {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                if (
                    $revision->status
                    === CanonicalConceptStatus::FAILED
                ) {
                    return;
                }

                if (
                    $revision->status
                    === CanonicalConceptStatus::FROZEN
                ) {
                    throw new \LogicException(
                        'Cannot fail an already '
                        . 'frozen revision.'
                    );
                }

                $from =
                    $revision->status;

                $revision->forceFill([
                    'status' =>
                        CanonicalConceptStatus::FAILED,

                    'failure_code' =>
                        $code,

                    'failure_message' =>
                        $message,

                    'failed_at' =>
                        $this->clock->now(),

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();

                $this->events
                    ->append(
                        revision:
                            $revision,

                        type:
                            CanonicalEventType::FAILED,

                        from:
                            $from,

                        to:
                            CanonicalConceptStatus::FAILED,

                        metadata: [
                            'code' => $code,
                        ],

                        eventKey:
                            'failed:'
                            . $code,
                    );
            }
        );
    }
}
```

---

# 10.45 Orchestration boundary

Đến đây **không nên sửa `BuildCanonicalConcept` thành một Eloquent monster**.

Ta tạo persistent orchestrator:

```text
CanonicalConceptPersistenceService
```

nó điều khiển:

```text
revision
attempts
checkpoint
state transitions
BuildCanonicalConcept/domain services
freeze persistence
```

---

# 10.46 `CanonicalConceptPersistenceService`

Bản production:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Models\VideoProject;
use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Persistence\DTO\CanonicalRevisionIdentity;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CanonicalConceptPersistenceService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalStateTransitionService $states,
        private readonly CanonicalEventWriter $events,
        private readonly CanonicalFreezePersistenceService $freezePersistence,
        private readonly CanonicalFailureService $failures,
        private readonly BuildCanonicalConcept $builder,
        private readonly string $conceptModel,
        private readonly string $promptVersion,
    ) {
    }

    public function build(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
    ): PersistedCanonicalConcept {
        /*
         * -----------------------------------------
         * 1. Allocate revision atomically.
         * -----------------------------------------
         */
        $revision =
            $this->createRevision(
                projectId:
                    $projectId,

                sessionId:
                    $sessionId,

                input:
                    $input,
            );

        try {
            /*
             * -------------------------------------
             * 2. PENDING -> GENERATING
             * -------------------------------------
             */
            $revision =
                $this->states
                    ->transition(
                        revisionId:
                            $revision->id,

                        to:
                            CanonicalConceptStatus::GENERATING,

                        eventType:
                            CanonicalEventType::GENERATION_STARTED,

                        eventKey:
                            'generation:start',
                    );

            /*
             * BuildCanonicalConcept still owns:
             *
             * Sonnet generation
             * validation
             * exactly one repair
             * normalization
             * serialization
             * freeze object creation
             *
             * In the next integration subsection
             * we instrument it with callbacks so
             * DB gets fine-grained checkpoints.
             */
            $frozen =
                $this->builder
                    ->build(
                        input:
                            $input,

                        revision:
                            $revision->revision,
                    );

            /*
             * If builder succeeded, semantic pipeline
             * has completed.
             *
             * Transition through required state
             * boundaries.
             */
            $revision =
                $this->advanceSuccessfulBuild(
                    $revision
                );

            /*
             * -------------------------------------
             * 3. Atomic DB freeze.
             * -------------------------------------
             */
            return $this->freezePersistence
                ->freeze(
                    revisionId:
                        $revision->id,

                    frozen:
                        $frozen,

                    decisionOrigin:
                        $revision->repair_count > 0
                            ? DecisionOrigin::REPAIRED
                            : DecisionOrigin::GENERATED,
                );
        } catch (Throwable $e) {
            $this->failures
                ->fail(
                    revisionId:
                        $revision->id,

                    code:
                        $this->failureCode(
                            $e
                        ),

                    message:
                        $e->getMessage(),
                );

            throw $e;
        }
    }

    private function createRevision(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
    ): CanonicalConceptRevision {
        return DB::transaction(
            function () use (
                $projectId,
                $sessionId,
                $input,
            ): CanonicalConceptRevision {
                /*
                 * Serialize revision allocation
                 * per project.
                 */
                VideoProject::query()
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $revisionNumber =
                    $this->repository
                        ->latestRevisionNumber(
                            $projectId
                        ) + 1;

                $revision =
                    $this->repository
                        ->createRevision(
                            identity:
                                new CanonicalRevisionIdentity(
                                    projectId:
                                        $projectId,

                                    sessionId:
                                        $sessionId,

                                    revision:
                                        $revisionNumber,
                                ),

                            objectType:
                                $input->objectType,

                            profileKey:
                                $input->profile->key,

                            profileVersion:
                                $input->profile->version,

                            schemaVersion:
                                '1.0',

                            conceptModel:
                                $this->conceptModel,

                            promptVersion:
                                $this->promptVersion,
                        );

                $this->events
                    ->append(
                        revision:
                            $revision,

                        type:
                            CanonicalEventType::REVISION_CREATED,

                        from:
                            null,

                        to:
                            CanonicalConceptStatus::PENDING,

                        metadata: [
                            'revision' =>
                                $revisionNumber,

                            'profile_key' =>
                                $input->profile->key,

                            'profile_version' =>
                                $input->profile->version,
                        ],

                        eventKey:
                            'revision:created',
                    );

                return $revision;
            },
            attempts: 3
        );
    }

    private function advanceSuccessfulBuild(
        CanonicalConceptRevision $revision
    ): CanonicalConceptRevision {
        /*
         * Temporary coarse integration.
         *
         * Fine-grained observer integration below
         * should be used in final production wiring.
         */

        $current =
            $this->repository
                ->findRevision(
                    $revision->id
                )
                ?? throw new \RuntimeException(
                    'Canonical revision disappeared.'
                );

        if (
            $current->status
            === CanonicalConceptStatus::GENERATING
        ) {
            $current =
                $this->states->transition(
                    $current->id,
                    CanonicalConceptStatus::GENERATED,
                    CanonicalEventType::GENERATION_SUCCEEDED,
                    eventKey:
                        'generation:succeeded',
                );
        }

        if (
            $current->status
            === CanonicalConceptStatus::GENERATED
        ) {
            $current =
                $this->states->transition(
                    $current->id,
                    CanonicalConceptStatus::VALIDATING,
                    CanonicalEventType::VALIDATION_STARTED,
                    eventKey:
                        'validation:start',
                );
        }

        if (
            $current->status
            === CanonicalConceptStatus::VALIDATING
        ) {
            $current =
                $this->states->transition(
                    $current->id,
                    CanonicalConceptStatus::NORMALIZING,
                    CanonicalEventType::NORMALIZATION_STARTED,
                    eventKey:
                        'normalization:start',
                );
        }

        if (
            $current->status
            === CanonicalConceptStatus::NORMALIZING
        ) {
            $current =
                $this->states->transition(
                    $current->id,
                    CanonicalConceptStatus::NORMALIZED,
                    CanonicalEventType::NORMALIZATION_COMPLETED,
                    eventKey:
                        'normalization:complete',
                );
        }

        return $this->states
            ->transition(
                $current->id,
                CanonicalConceptStatus::FREEZING,
                CanonicalEventType::FREEZE_STARTED,
                eventKey:
                    'freeze:start',
            );
    }

    private function failureCode(
        Throwable $e
    ): string {
        return match (true) {
            $e instanceof
                \App\Video\Concept\Exceptions\CanonicalValidationException
                    => 'canonical_validation_failed',

            default =>
                'canonical_concept_failed',
        };
    }
}
```

Nhưng tôi **không chốt `advanceSuccessfulBuild()` kiểu coarse này cho production cuối cùng**. Nó chỉ cho thấy integration boundary.

Bản đúng phải checkpoint theo thời điểm thật.

---

# 10.47 Production integration: lifecycle observer

Ta thêm observer cấp cao hơn:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Validation\ValidationResult;

interface CanonicalConceptLifecycle
{
    public function generationStarted(): void;

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void;

    public function validationStarted(): void;

    public function validationFailed(
        string $rawJson,
        array $errors
    ): void;

    public function repairStarted(): void;

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void;

    public function normalizationStarted(): void;

    public function normalizationCompleted(
        string $canonicalJson
    ): void;

    public function freezingStarted(): void;
}
```

Null implementation dùng cho unit tests/non-persistent use.

---

# 10.48 Persistent lifecycle

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\CanonicalConceptLifecycle;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Persistence\DTO\AttemptUsage;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

final class PersistentCanonicalConceptLifecycle
    implements CanonicalConceptLifecycle
{
    private ?CanonicalConceptAttempt $attempt =
        null;

    public function __construct(
        private readonly string $revisionId,
        private readonly CanonicalStateTransitionService $states,
        private readonly CanonicalCheckpointService $checkpoints,
        private readonly CanonicalAttemptWriter $attempts,
        private readonly CanonicalRepairClaimService $repairClaims,
        private readonly string $provider,
        private readonly string $model,
        private readonly string $promptVersion,
        private readonly string $inputHash,
        private readonly string $schemaHash,
    ) {
    }

    public function generationStarted(): void
    {
        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::PENDING
        ) {
            $revision =
                $this->states
                    ->transition(
                        $revision->id,
                        CanonicalConceptStatus::GENERATING,
                        CanonicalEventType::GENERATION_STARTED,
                        eventKey:
                            'generation:start'
                    );
        }

        $this->attempt =
            $this->attempts
                ->start(
                    revision:
                        $revision,

                    type:
                        CanonicalAttemptType::GENERATION,

                    number:
                        1,

                    provider:
                        $this->provider,

                    model:
                        $this->model,

                    promptVersion:
                        $this->promptVersion,

                    inputHash:
                        $this->inputHash,

                    schemaHash:
                        $this->schemaHash,
                );
    }

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        if ($this->attempt === null) {
            throw new \LogicException(
                'Generation attempt was not started.'
            );
        }

        $this->attempts
            ->succeed(
                $this->attempt,
                $response->rawText,
                $this->usage($response)
            );

        $this->checkpoints
            ->rawOutput(
                $this->revisionId,
                $response->rawText
            );

        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::GENERATING
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::GENERATED,
                    CanonicalEventType::GENERATION_SUCCEEDED,
                    eventKey:
                        'generation:succeeded'
                );
        }
    }

    public function validationStarted(): void
    {
        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::GENERATED
            || $revision->status
            === CanonicalConceptStatus::REPAIRED
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::VALIDATING,
                    CanonicalEventType::VALIDATION_STARTED,
                    eventKey:
                        'validation:start:'
                        . $revision->repair_count
                );
        }
    }

    public function validationFailed(
        string $rawJson,
        array $errors
    ): void {
        $this->checkpoints
            ->rawOutput(
                $this->revisionId,
                $rawJson
            );

        $this->checkpoints
            ->validationFailure(
                $this->revisionId,
                array_map(
                    static fn ($e): array =>
                        $e->toArray(),
                    $errors
                )
            );

        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::VALIDATING
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::VALIDATION_FAILED,
                    CanonicalEventType::VALIDATION_FAILED,
                    metadata: [
                        'error_count' =>
                            count($errors),
                    ],
                    eventKey:
                        'validation:failed:'
                        . $revision->repair_count
                );
        }
    }

    public function repairStarted(): void
    {
        $this->repairClaims
            ->claim(
                $this->revisionId
            );

        $revision =
            $this->revision();

        $revision =
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::REPAIRING,
                    CanonicalEventType::REPAIR_STARTED,
                    eventKey:
                        'repair:start:1'
                );

        $this->attempt =
            $this->attempts
                ->start(
                    revision:
                        $revision,

                    type:
                        CanonicalAttemptType::REPAIR,

                    number:
                        1,

                    provider:
                        $this->provider,

                    model:
                        $this->model,

                    promptVersion:
                        $this->promptVersion,

                    inputHash:
                        $this->inputHash,

                    schemaHash:
                        $this->schemaHash,
                );
    }

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        if ($this->attempt === null) {
            throw new \LogicException(
                'Repair attempt was not started.'
            );
        }

        $this->attempts
            ->succeed(
                $this->attempt,
                $response->rawText,
                $this->usage($response)
            );

        $this->checkpoints
            ->rawOutput(
                $this->revisionId,
                $response->rawText
            );

        $this->checkpoints
            ->clearValidationErrors(
                $this->revisionId
            );

        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::REPAIRING
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::REPAIRED,
                    CanonicalEventType::REPAIR_SUCCEEDED,
                    eventKey:
                        'repair:succeeded:1'
                );
        }
    }

    public function normalizationStarted(): void
    {
        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::VALIDATING
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::NORMALIZING,
                    CanonicalEventType::NORMALIZATION_STARTED,
                    eventKey:
                        'normalization:start'
                );
        }
    }

    public function normalizationCompleted(
        string $canonicalJson
    ): void {
        /*
         * canonicalJson is not frozen yet.
         *
         * We intentionally do NOT write it into
         * canonical_json column here.
         *
         * canonical_json column is populated only
         * by atomic freeze transaction.
         */

        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::NORMALIZING
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::NORMALIZED,
                    CanonicalEventType::NORMALIZATION_COMPLETED,
                    metadata: [
                        'candidate_hash' =>
                            hash(
                                'sha256',
                                $canonicalJson
                            ),
                    ],
                    eventKey:
                        'normalization:complete'
                );
        }
    }

    public function freezingStarted(): void
    {
        $revision =
            $this->revision();

        if (
            $revision->status
            === CanonicalConceptStatus::NORMALIZED
        ) {
            $this->states
                ->transition(
                    $revision->id,
                    CanonicalConceptStatus::FREEZING,
                    CanonicalEventType::FREEZE_STARTED,
                    eventKey:
                        'freeze:start'
                );
        }
    }

    private function revision(): CanonicalConceptRevision
    {
        return CanonicalConceptRevision::query()
            ->findOrFail(
                $this->revisionId
            );
    }

    private function usage(
        AnthropicStructuredOutputResponse $response
    ): AttemptUsage {
        return new AttemptUsage(
            requestId:
                $response->requestId,

            stopReason:
                $response->stopReason,

            inputTokens:
                $response->inputTokens,

            outputTokens:
                $response->outputTokens,

            /*
             * Prefer actual cost returned/recorded
             * by existing LlmClient accounting.
             */
            costUsd:
                null,

            latencyMs:
                null,
        );
    }
}
```

---

# 10.49 Repair claim service

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CanonicalRepairClaimService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
    ) {
    }

    public function claim(
        string $revisionId
    ): void {
        DB::transaction(
            function () use (
                $revisionId
            ): void {
                $revision =
                    $this->repository
                        ->lockRevision(
                            $revisionId
                        );

                $revision->assertMutable();

                if (
                    $revision->repair_count >= 1
                ) {
                    throw new RuntimeException(
                        'Canonical repair limit '
                        . 'already exhausted.'
                    );
                }

                $revision->forceFill([
                    'repair_count' =>
                        1,

                    'lock_version' =>
                        $revision->lock_version + 1,
                ])->save();
            }
        );
    }
}
```

---

# 10.50 Sửa `BuildCanonicalConcept` để lifecycle-aware

Đây mới là bản nên dùng.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;

final class BuildCanonicalConcept
{
    public function __construct(
        private readonly ClaudeConceptDesigner $designer,
        private readonly ClaudeConceptRepairer $repairer,
        private readonly CanonicalConceptProcessor $processor,
        private readonly CanonicalConceptFreezer $freezer,
    ) {
    }

    public function build(
        ConceptInput $input,
        int $revision,
        CanonicalConceptLifecycle $lifecycle,
    ): FrozenCanonicalConcept {
        /*
         * -----------------------------------------
         * INITIAL GENERATION
         * -----------------------------------------
         */
        $lifecycle
            ->generationStarted();

        try {
            $initial =
                $this->designer
                    ->generate(
                        $input
                    );
        } catch (\Throwable $e) {
            /*
             * Provider failure is NOT semantic repair.
             */
            throw $e;
        }

        $lifecycle
            ->generationCompleted(
                $initial->response
            );

        /*
         * -----------------------------------------
         * FIRST VALIDATION
         * -----------------------------------------
         */
        $lifecycle
            ->validationStarted();

        try {
            $processed =
                $this->processor
                    ->process(
                        rawJson:
                            $initial
                                ->payload
                                ->rawJson,

                        input:
                            $input,

                        lifecycle:
                            $lifecycle,
                    );
        } catch (
            CanonicalValidationException $failure
        ) {
            $failedRawJson =
                $failure
                    ->failedRawJson();

            $errors =
                $failure
                    ->errors();

            if (
                $failedRawJson === null
                || $failedRawJson === ''
                || $errors === []
            ) {
                throw $failure;
            }

            $lifecycle
                ->validationFailed(
                    $failedRawJson,
                    $errors
                );

            /*
             * -------------------------------------
             * EXACTLY ONE REPAIR
             * -------------------------------------
             */
            $lifecycle
                ->repairStarted();

            $repaired =
                $this->repairer
                    ->repair(
                        input:
                            $input,

                        failedRawJson:
                            $failedRawJson,

                        errors:
                            $errors,
                    );

            $lifecycle
                ->repairCompleted(
                    $repaired->response
                );

            /*
             * -------------------------------------
             * SECOND VALIDATION
             *
             * NO SECOND REPAIR.
             * -------------------------------------
             */
            $lifecycle
                ->validationStarted();

            $processed =
                $this->processor
                    ->process(
                        rawJson:
                            $repaired
                                ->payload
                                ->rawJson,

                        input:
                            $input,

                        lifecycle:
                            $lifecycle,
                    );
        }

        /*
         * Processor has already:
         *
         * normalized
         * serialized
         * revalidated
         */
        $lifecycle
            ->freezingStarted();

        return $this->freezer
            ->freeze(
                processed:
                    $processed,

                revision:
                    $revision,
            );
    }
}
```

Ở đây tôi dùng:

```text
$initial->payload
$initial->response
```

Nếu class `ClaudeConceptDesigner` Phần 5 hiện tại chỉ return `CanonicalJsonPayload`, **không nên hack thêm metadata vào payload**.

Tạo result DTO.

---

# 10.51 `ClaudeConceptGenerationResult`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use App\Video\Concept\CanonicalJsonPayload;

final class ClaudeConceptGenerationResult
{
    public function __construct(
        public readonly CanonicalJsonPayload $payload,
        public readonly AnthropicStructuredOutputResponse $response,
    ) {
    }
}
```

Designer:

```php
public function generate(
    ConceptInput $input
): ClaudeConceptGenerationResult {
    $response =
        $this->client->generate(...);

    $payload =
        $this->payloadFactory
            ->fromRawJson(
                $response->rawText
            );

    return new ClaudeConceptGenerationResult(
        payload:
            $payload,

        response:
            $response,
    );
}
```

Repairer tương tự.

Điều này giữ:

```text
semantic payload
```

tách khỏi:

```text
provider telemetry
```

---

# 10.52 Processor lifecycle integration

Không để lifecycle tự quyết validation.

Processor vẫn quyết logic.

Ví dụ:

```php
public function process(
    string $rawJson,
    ConceptInput $input,
    CanonicalConceptLifecycle $lifecycle,
): ProcessedCanonicalConcept {
    $effective =
        $this->schemaBuilder
            ->build(
                $input->profile
            );

    /*
     * Core/effective/semantic validation...
     */

    // ...

    $lifecycle
        ->normalizationStarted();

    $normalized =
        $this->normalizer
            ->normalize(
                $spec
            );

    $canonicalJson =
        $this->serializer
            ->serialize(
                $normalized,
                $effective
            );

    /*
     * revalidation...
     */

    // ...

    $lifecycle
        ->normalizationCompleted(
            $canonicalJson
        );

    return new ProcessedCanonicalConcept(
        spec:
            $normalized,

        canonicalJson:
            $canonicalJson,

        effectiveSchema:
            $effective,
    );
}
```

Validation recorder có thể dùng một observer riêng ở từng validator nếu bạn muốn metrics chi tiết.

---

# 10.53 Một cải tiến: ValidationReport

Để persistence biết tất cả validation stages mà không couple validators với DB, `ProcessedCanonicalConcept` nên mang report.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

final class ValidationStageReport
{
    public function __construct(
        public readonly string $stage,
        public readonly bool $passed,
        public readonly string $documentHash,
        public readonly string $validatorVersion,
        public readonly array $errors,
        public readonly int $durationMs,
    ) {
    }
}
```

và:

```php
final class ProcessedCanonicalConcept
{
    /**
     * @param list<ValidationStageReport> $validationReports
     */
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly string $canonicalJson,
        public readonly EffectiveConceptSchema $effectiveSchema,
        public readonly array $validationReports,
    ) {
    }
}
```

Đây tốt hơn việc validator tự ghi DB.

---

# 10.54 Decision Ledger chỉ write lúc freeze

Không write ledger ở:

```text
generated
validation_failed
repairing
```

Vì đó chưa phải design truth.

Ledger chỉ được tạo từ:

```text
normalized
+
revalidated
+
ready-to-freeze
```

Sau đó atomic freeze.

---

# 10.55 Immutability rule

Sau:

```text
status = frozen
```

những field sau **không bao giờ update**:

```text
canonical_json
canonical_hash

revision
video_project_id

object_type

profile_key
profile_version

canonical_schema_version
effective_schema_hash

semantic_validator_version
normalizer_version
canonicalizer_version

concept_model
concept_prompt_version

frozen_at
```

Muốn thay design:

```text
revision N
     ↓
create revision N+1
```

Không:

```text
UPDATE revision N
```

---

# 10.56 Revision lineage

Tôi khuyên thêm:

```php
$table
    ->uuid('parent_revision_id')
    ->nullable();
```

vào revision migration.

Foreign:

```php
$table
    ->foreign('parent_revision_id')
    ->references('id')
    ->on('canonical_concept_revisions')
    ->nullOnDelete();
```

Vậy:

```text
Revision 1
    ↓
Revision 2
    ↓
Revision 3
```

không chỉ dựa vào số.

Model:

```php
public function parent(): BelongsTo
{
    return $this->belongsTo(
        self::class,
        'parent_revision_id'
    );
}
```

và:

```php
public function children(): HasMany
{
    return $this->hasMany(
        self::class,
        'parent_revision_id'
    );
}
```

Tôi khuyên **thêm field này ngay V1 persistence**.

---

# 10.57 Revision reason

Thêm:

```php
$table
    ->string(
        'revision_reason',
        80
    )
    ->nullable();
```

Ví dụ:

```text
initial
human_revision
vision_qa_failure
design_change
source_update
manual_regeneration
```

Không cần đưa vào canonical schema.

---

# 10.58 Không overwrite frozen revision khi Vision QA fail

Sau này:

```text
Canonical Revision 3
↓
Anchor render
↓
Vision QA
↓
FAIL
```

Nếu lỗi nằm ở renderer:

```text
repair render
```

không sửa canonical.

Nếu Vision QA phát hiện **canonical design itself contradictory/inadequate**:

```text
Revision 3 remains frozen
↓
create Revision 4
parent_revision_id = Revision 3
revision_reason = vision_qa_design_revision
```

Audit trail không mất.

---

# 10.59 Idempotency key ở Job level

`BuildConceptJob` cần stable key.

Ví dụ:

```text
canonical-concept:{projectId}:{sourceFingerprint}:{profileVersion}
```

Nhưng không nên unique vĩnh viễn vì user có thể chủ động tạo revision mới.

Tốt hơn job nhận:

```text
canonical_revision_id
```

Flow:

```text
Controller/service
↓
allocate revision
↓
commit
↓
dispatch BuildCanonicalConceptJob(revision_id)
```

Không để Job tự allocate revision mỗi lần retry.

---

# 10.60 Flow Job production

```php
final class BuildCanonicalConceptJob
    implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $revisionId,
    ) {
    }

    public function handle(
        CanonicalConceptExecutionService $service
    ): void {
        $service->execute(
            $this->revisionId
        );
    }

    public function uniqueId(): string
    {
        return 'canonical-concept:'
            . $this->revisionId;
    }
}
```

Nên implement:

```php
ShouldBeUnique
```

nếu queue topology của bạn phù hợp:

```php
final class BuildCanonicalConceptJob
    implements ShouldQueue, ShouldBeUnique
```

và:

```php
public int $uniqueFor = 3600;
```

Nhưng DB lock vẫn bắt buộc.

Queue uniqueness không phải consistency guarantee.

---

# 10.61 Tách `create()` và `execute()`

Đây là production flow tốt hơn service ở 10.46.

```text
CanonicalConceptRevisionService::create(...)
↓
commit revision
↓
dispatch(revisionId)
↓
CanonicalConceptExecutionService::execute(revisionId)
```

Như vậy retry không tạo revision mới.

---

# 10.62 `CanonicalConceptRevisionService`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Models\VideoProject;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Persistence\DTO\CanonicalRevisionIdentity;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;

final class CanonicalConceptRevisionService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalEventWriter $events,
        private readonly string $conceptModel,
        private readonly string $promptVersion,
    ) {
    }

    public function create(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $reason = 'initial',
        ?string $parentRevisionId = null,
    ): CanonicalConceptRevision {
        return DB::transaction(
            function () use (
                $projectId,
                $sessionId,
                $input,
                $reason,
                $parentRevisionId,
            ): CanonicalConceptRevision {
                VideoProject::query()
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $number =
                    $this->repository
                        ->latestRevisionNumber(
                            $projectId
                        ) + 1;

                $revision =
                    $this->repository
                        ->createRevision(
                            new CanonicalRevisionIdentity(
                                projectId:
                                    $projectId,

                                sessionId:
                                    $sessionId,

                                revision:
                                    $number,
                            ),

                            objectType:
                                $input->objectType,

                            profileKey:
                                $input->profile->key,

                            profileVersion:
                                $input->profile->version,

                            schemaVersion:
                                '1.0',

                            conceptModel:
                                $this->conceptModel,

                            promptVersion:
                                $this->promptVersion,
                        );

                $revision->forceFill([
                    'parent_revision_id' =>
                        $parentRevisionId,

                    'revision_reason' =>
                        $reason,
                ])->save();

                $this->events
                    ->append(
                        revision:
                            $revision,

                        type:
                            CanonicalEventType::REVISION_CREATED,

                        to:
                            $revision->status,

                        metadata: [
                            'revision' =>
                                $number,

                            'reason' =>
                                $reason,

                            'parent_revision_id' =>
                                $parentRevisionId,
                        ],

                        eventKey:
                            'revision:created',
                    );

                return $revision;
            },
            attempts: 3
        );
    }
}
```

---

# 10.63 Execution service resumability

Đây mới là service Job gọi.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use RuntimeException;

final class CanonicalConceptExecutionService
{
    public function __construct(
        private readonly CanonicalConceptInputLoader $inputLoader,
        private readonly CanonicalLifecycleFactory $lifecycleFactory,
        private readonly BuildCanonicalConcept $builder,
        private readonly CanonicalFreezePersistenceService $freezePersistence,
        private readonly CanonicalFailureService $failures,
    ) {
    }

    public function execute(
        string $revisionId
    ): PersistedCanonicalConcept {
        $revision =
            CanonicalConceptRevision::query()
                ->findOrFail(
                    $revisionId
                );

        /*
         * -----------------------------------------
         * Already done.
         * -----------------------------------------
         */
        if (
            $revision->status
            === CanonicalConceptStatus::FROZEN
        ) {
            return $this->loadFrozen(
                $revision
            );
        }

        if (
            $revision->status
            === CanonicalConceptStatus::FAILED
        ) {
            throw new RuntimeException(
                'Canonical revision is terminal failed: '
                . $revision->id
            );
        }

        $input =
            $this->inputLoader
                ->load(
                    $revision
                );

        $lifecycle =
            $this->lifecycleFactory
                ->make(
                    revision:
                        $revision,

                    input:
                        $input,
                );

        try {
            $frozen =
                $this->builder
                    ->build(
                        input:
                            $input,

                        revision:
                            $revision->revision,

                        lifecycle:
                            $lifecycle,
                    );

            return $this->freezePersistence
                ->freeze(
                    revisionId:
                        $revision->id,

                    frozen:
                        $frozen,

                    decisionOrigin:
                        $this->decisionOrigin(
                            $revision->refresh()
                        ),
                );
        } catch (\Throwable $e) {
            /*
             * Retryable provider/network exceptions
             * should normally bubble to Laravel Queue
             * WITHOUT marking semantic revision FAILED.
             *
             * Terminal classification belongs here.
             */
            if (
                $this->isTerminal(
                    $e
                )
            ) {
                $this->failures
                    ->fail(
                        $revision->id,
                        $this->errorCode($e),
                        $e->getMessage()
                    );
            }

            throw $e;
        }
    }

    private function decisionOrigin(
        CanonicalConceptRevision $revision
    ): DecisionOrigin {
        return $revision->repair_count > 0
            ? DecisionOrigin::REPAIRED
            : DecisionOrigin::GENERATED;
    }

    private function isTerminal(
        \Throwable $e
    ): bool {
        return $e instanceof
            \App\Video\Concept\Exceptions\CanonicalValidationException
            || $e instanceof
            \App\Video\Concept\Exceptions\CanonicalSemanticRepairExhaustedException;
    }

    private function errorCode(
        \Throwable $e
    ): string {
        return match (true) {
            $e instanceof
                \App\Video\Concept\Exceptions\CanonicalValidationException
                    => 'canonical_validation_failed',

            default =>
                'canonical_concept_failed',
        };
    }

    private function loadFrozen(
        CanonicalConceptRevision $revision
    ): PersistedCanonicalConcept {
        /*
         * Hydration implementation belongs in
         * FrozenCanonicalConceptHydrator.
         */
        $frozen =
            app(
                FrozenCanonicalConceptHydrator::class
            )->hydrate(
                $revision
            );

        return new PersistedCanonicalConcept(
            revisionId:
                $revision->id,

            frozen:
                $frozen,

            reused:
                true,
        );
    }
}
```

---

# 10.64 Một điểm phải sửa để resume thật sự production-grade

`BuildCanonicalConcept::build()` ở trên vẫn luôn gọi:

```text
generationStarted()
designer.generate()
```

Nếu worker crash sau khi raw generation đã lưu, retry có thể gọi Sonnet lần nữa.

Do đó final architecture cần `CanonicalConceptExecutionCursor`.

---

# 10.65 Execution Cursor

Không cần thêm table.

Status + attempts chính là cursor.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

final class CanonicalConceptExecutionCursor
{
    public function successfulGeneration(
        CanonicalConceptRevision $revision
    ): ?CanonicalConceptAttempt {
        return CanonicalConceptAttempt::query()
            ->where(
                'canonical_concept_revision_id',
                $revision->id
            )
            ->where(
                'attempt_type',
                CanonicalAttemptType::GENERATION
            )
            ->where(
                'status',
                CanonicalAttemptStatus::SUCCEEDED
            )
            ->whereNotNull(
                'raw_output'
            )
            ->first();
    }

    public function successfulRepair(
        CanonicalConceptRevision $revision
    ): ?CanonicalConceptAttempt {
        return CanonicalConceptAttempt::query()
            ->where(
                'canonical_concept_revision_id',
                $revision->id
            )
            ->where(
                'attempt_type',
                CanonicalAttemptType::REPAIR
            )
            ->where(
                'status',
                CanonicalAttemptStatus::SUCCEEDED
            )
            ->whereNotNull(
                'raw_output'
            )
            ->first();
    }
}
```

Execution service có thể resume từ raw checkpoint.

---

# 10.66 Không resume blindly từ status

Ví dụ:

```text
status = generating
attempt generation = succeeded
raw_output exists
```

có nghĩa crash xảy ra giữa:

```text
attempt persist
```

và:

```text
GENERATED transition
```

Khi retry:

```text
successfulGeneration exists
↓
DO NOT call Sonnet
↓
repair state/event if needed
↓
validate persisted raw_output
```

Đây mới là resumability đúng.

---

# 10.67 Input snapshot cũng phải immutable

Có một vấn đề khác.

Nếu revision tạo hôm nay nhưng Job chạy 10 phút sau và:

```text
InspirationBrief
profile
project requirements
```

đã thay đổi thì cùng revision có thể generate input khác.

Do đó revision phải snapshot input.

Thêm migration fields:

```php
$table
    ->longText(
        'concept_input_json'
    );

$table
    ->char(
        'concept_input_hash',
        64
    );
```

Đây là **bắt buộc** nếu muốn reproducibility thật.

---

# 10.68 ConceptInput canonical snapshot

Tạo serializer:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use JsonException;

final class ConceptInputSnapshotter
{
    /**
     * @return array{
     *   json:string,
     *   hash:string
     * }
     */
    public function snapshot(
        ConceptInput $input
    ): array {
        $data =
            $this->sortObjectKeys(
                $input->toArray()
            );

        try {
            $json =
                json_encode(
                    $data,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                );
        } catch (JsonException $e) {
            throw new \RuntimeException(
                'Unable to snapshot ConceptInput.',
                previous: $e
            );
        }

        return [
            'json' =>
                $json,

            'hash' =>
                hash(
                    'sha256',
                    $json
                ),
        ];
    }

    private function sortObjectKeys(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->sortObjectKeys(
                        $item
                    ),
                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        foreach (
            $value
            as $key => $child
        ) {
            $value[$key] =
                $this->sortObjectKeys(
                    $child
                );
        }

        return $value;
    }
}
```

---

# 10.69 Revision create lưu snapshot

Trong `CanonicalConceptRevisionService`:

```php
$snapshot =
    $this->snapshotter
        ->snapshot(
            $input
        );
```

Sau create:

```php
$revision->forceFill([
    'concept_input_json' =>
        $snapshot['json'],

    'concept_input_hash' =>
        $snapshot['hash'],

    'parent_revision_id' =>
        $parentRevisionId,

    'revision_reason' =>
        $reason,
])->save();
```

Từ đây Job **không reconstruct input từ current project state**.

Nó load exact snapshot.

---

# 10.70 `CanonicalConceptInputLoader`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use JsonException;
use RuntimeException;

final class CanonicalConceptInputLoader
{
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $profiles,
        private readonly ConceptInputHydrator $hydrator,
    ) {
    }

    public function load(
        CanonicalConceptRevision $revision
    ): ConceptInput {
        $json =
            (string)
            $revision->concept_input_json;

        if ($json === '') {
            throw new RuntimeException(
                'Canonical revision has no '
                . 'ConceptInput snapshot.'
            );
        }

        if (
            !hash_equals(
                (string)
                $revision->concept_input_hash,

                hash(
                    'sha256',
                    $json
                )
            )
        ) {
            throw new RuntimeException(
                'ConceptInput snapshot hash mismatch.'
            );
        }

        try {
            $data =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Invalid ConceptInput snapshot.',
                previous: $e
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                'ConceptInput snapshot root '
                . 'must be object.'
            );
        }

        $profile =
            $this->profiles
                ->get(
                    (string)
                    $revision->profile_key
                );

        if (
            $profile->version
            !== $revision->profile_version
        ) {
            throw new RuntimeException(
                sprintf(
                    'Profile version drift: '
                    . 'revision=%s runtime=%s.',
                    $revision->profile_version,
                    $profile->version
                )
            );
        }

        return $this->hydrator
            ->hydrate(
                $data,
                $profile
            );
    }
}
```

---

# 10.71 Profile version drift

Đây là điểm production rất quan trọng.

Revision lưu:

```text
marine_vessel@1.0
```

nhưng code deploy mới có:

```text
marine_vessel@1.1
```

Job cũ resume không được tự động dùng 1.1.

Registry production nên support:

```php
$registry->get(
    key: 'marine_vessel',
    version: '1.0'
);
```

thay vì chỉ:

```php
get('marine_vessel')
```

Tôi khuyên sửa Registry Phần 7 theo hướng:

```text
profiles/
marine_vessel/
    1.0.json
    1.1.json
```

Không overwrite schema cũ.

---

# 10.72 Frozen hydrator

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Freeze\FrozenCanonicalConceptMetadata;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use JsonException;
use RuntimeException;

final class FrozenCanonicalConceptHydrator
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $hasher,
    ) {
    }

    public function hydrate(
        CanonicalConceptRevision $revision
    ): FrozenCanonicalConcept {
        if (!$revision->isFrozen()) {
            throw new RuntimeException(
                'Cannot hydrate non-frozen revision '
                . 'as FrozenCanonicalConcept.'
            );
        }

        $json =
            (string)
            $revision->canonical_json;

        $storedHash =
            (string)
            $revision->canonical_hash;

        $actualHash =
            $this->hasher
                ->hash(
                    $json
                );

        if (
            !hash_equals(
                $storedHash,
                $actualHash
            )
        ) {
            throw new RuntimeException(
                'Frozen canonical integrity failure.'
            );
        }

        try {
            $data =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Frozen canonical JSON is invalid.',
                previous: $e
            );
        }

        if (
            !is_array($data)
            || array_is_list($data)
        ) {
            throw new RuntimeException(
                'Frozen canonical root invalid.'
            );
        }

        $spec =
            CanonicalDesignSpec
                ::fromArray(
                    $data
                );

        return new FrozenCanonicalConcept(
            spec:
                $spec,

            canonicalJson:
                $json,

            hash:
                $storedHash,

            revision:
                (int)
                $revision->revision,

            frozenAt:
                $revision->frozen_at
                ?? throw new RuntimeException(
                    'Frozen revision missing frozen_at.'
                ),

            metadata:
                new FrozenCanonicalConceptMetadata(
                    canonicalSchemaVersion:
                        $revision
                            ->canonical_schema_version,

                    profileKey:
                        $revision
                            ->profile_key,

                    profileVersion:
                        $revision
                            ->profile_version,

                    effectiveSchemaHash:
                        $revision
                            ->effective_schema_hash,

                    semanticValidatorVersion:
                        $revision
                            ->semantic_validator_version,

                    normalizerVersion:
                        $revision
                            ->normalizer_version,

                    canonicalizerVersion:
                        $revision
                            ->canonicalizer_version,

                    conceptModel:
                        $revision
                            ->concept_model,

                    conceptPromptVersion:
                        $revision
                            ->concept_prompt_version,
                ),
        );
    }
}
```

---

# 10.73 `canonical_json` nên LONGTEXT hay JSON?

Với yêu cầu của chúng ta:

> hash exact serialized bytes.

Tôi chọn:

```text
LONGTEXT
```

cho `canonical_json`.

Không dùng DB JSON column làm frozen byte source.

Lý do database có thể parse/reformat JSON representation.

Ta cần exact string:

```text
canonical_json
↓
SHA256 exact UTF-8 bytes
```

Còn những field cần query như:

```text
profile_key
object_type
revision
status
hash
```

đã được materialize thành columns.

Đây là lựa chọn đúng cho immutable canonical artifact.

---

# 10.74 `latest_raw_json` cũng LONGTEXT

Vì ta muốn giữ chính xác provider response:

```text
raw_output_hash =
SHA256(raw provider bytes)
```

Không để MariaDB normalize JSON.

---

# 10.75 Decision value dùng JSON được

`canonical_decisions.decision_value` không phải byte-authoritative artifact.

Nó dùng để:

```text
query
diff
inspect
QA
```

nên JSON column hợp lý.

Canonical source vẫn là:

```text
canonical_concept_revisions.canonical_json
```

---

# 10.76 Usage/cost không được double count

Bạn đã có `cost_entries` / usage architecture.

Do đó:

```text
canonical_concept_attempts.cost_usd
```

nên xem là telemetry snapshot.

Financial source-of-truth vẫn:

```text
cost_entries
```

Nếu `LlmClient` hiện đã record cost:

```text
LlmClient
↓
cost_entries
```

thì Persistence **không tạo cost_entries lần nữa**.

Attempt chỉ giữ:

```text
input_tokens
output_tokens
cost_usd snapshot
provider_request_id
```

để audit.

---

# 10.77 Link attempt với global usage ledger

Nếu `cost_entries` có ID, tốt hơn thêm:

```php
$table
    ->uuid('cost_entry_id')
    ->nullable();
```

hoặc:

```php
$table
    ->string(
        'usage_reference',
        120
    )
    ->nullable();
```

Như vậy:

```text
canonical attempt
       ↓
global cost entry
```

không double accounting.

---

# 10.78 ServiceProvider

Bindings Phần 10:

```php
$this->app->singleton(
    CanonicalConceptStateMachine::class
);

$this->app->singleton(
    CanonicalConceptRepository::class,
    EloquentCanonicalConceptRepository::class
);

$this->app->singleton(
    CanonicalEventWriter::class
);

$this->app->singleton(
    CanonicalStateTransitionService::class
);

$this->app->singleton(
    CanonicalAttemptWriter::class
);

$this->app->singleton(
    CanonicalValidationRecorder::class
);

$this->app->singleton(
    CanonicalDecisionExtractor::class
);

$this->app->singleton(
    DecisionLedgerWriter::class
);

$this->app->singleton(
    CanonicalCheckpointService::class
);

$this->app->singleton(
    CanonicalRepairClaimService::class
);

$this->app->singleton(
    CanonicalFailureService::class
);

$this->app->singleton(
    CanonicalFreezePersistenceService::class
);

$this->app->singleton(
    FrozenCanonicalConceptHydrator::class
);

$this->app->singleton(
    ConceptInputSnapshotter::class
);

$this->app->singleton(
    CanonicalConceptInputLoader::class
);

$this->app->singleton(
    CanonicalConceptExecutionCursor::class
);

$this->app->singleton(
    CanonicalConceptRevisionService::class
);

$this->app->singleton(
    CanonicalConceptExecutionService::class
);
```

---

# 10.79 Config không chứa repair attempts

Tôi **không thêm**:

```php
'repair_attempts' => 1
```

vào config.

Vì requirement của Canonical V1 là:

```text
exactly one semantic repair
```

Nó là architectural invariant, không phải tuning parameter.

Nếu config:

```dotenv
REPAIR_ATTEMPTS=3
```

thì một deploy sai có thể phá contract.

Code phải khóa:

```php
if ($revision->repair_count >= 1) {
    reject;
}
```

---

# 10.80 Tests — State Machine

```php
public function test_frozen_is_terminal(): void
{
    $machine =
        new CanonicalConceptStateMachine();

    foreach (
        CanonicalConceptStatus::cases()
        as $target
    ) {
        self::assertFalse(
            $machine->canTransition(
                CanonicalConceptStatus::FROZEN,
                $target
            )
        );
    }
}
```

---

# 10.81 Test exactly one repair

```php
public function test_revision_can_claim_only_one_repair(): void
{
    $revision =
        $this->revision([
            'repair_count' => 0,
        ]);

    $service =
        app(
            CanonicalRepairClaimService::class
        );

    $service->claim(
        $revision->id
    );

    $this->assertDatabaseHas(
        'canonical_concept_revisions',
        [
            'id' =>
                $revision->id,

            'repair_count' =>
                1,
        ]
    );

    $this->expectException(
        RuntimeException::class
    );

    $service->claim(
        $revision->id
    );
}
```

---

# 10.82 Test frozen immutability

```php
public function test_frozen_canonical_json_cannot_be_changed(): void
{
    $revision =
        $this->frozenRevision();

    $this->expectException(
        LogicException::class
    );

    $revision->canonical_json =
        '{"tampered":true}';

    $revision->save();
}
```

---

# 10.83 Test exact frozen hash

```php
public function test_persisted_hash_matches_exact_canonical_bytes(): void
{
    $revision =
        $this->frozenRevision();

    self::assertSame(
        $revision->canonical_hash,
        hash(
            'sha256',
            $revision->canonical_json
        )
    );
}
```

---

# 10.84 Test duplicate revision race protection

```php
public function test_project_revision_number_is_unique(): void
{
    $project =
        VideoProject::factory()
            ->create();

    CanonicalConceptRevision::factory()
        ->create([
            'video_project_id' =>
                $project->id,

            'revision' => 1,
        ]);

    $this->expectException(
        QueryException::class
    );

    CanonicalConceptRevision::factory()
        ->create([
            'video_project_id' =>
                $project->id,

            'revision' => 1,
        ]);
}
```

DB constraint là protection cuối.

---

# 10.85 Test Decision Ledger

```php
public function test_decision_ledger_is_derived_from_normalized_spec(): void
{
    $revision =
        $this->mutableRevision();

    $spec =
        $this->normalizedSpec();

    app(
        DecisionLedgerWriter::class
    )->write(
        $revision,
        $spec,
        DecisionOrigin::GENERATED
    );

    $this->assertDatabaseHas(
        'canonical_decisions',
        [
            'canonical_concept_revision_id' =>
                $revision->id,

            'target_path' =>
                'dimensions.length_m',

            'decision_type' =>
                'dimension',
        ]
    );

    $this->assertDatabaseHas(
        'canonical_decisions',
        [
            'canonical_concept_revision_id' =>
                $revision->id,

            'target_path' =>
                'permanent_geometry.bow.stem',

            'decision_type' =>
                'geometry',
        ]
    );
}
```

---

# 10.86 Test Decision Ledger cannot rewrite

```php
public function test_decision_ledger_is_write_once_per_revision(): void
{
    $revision =
        $this->mutableRevision();

    $writer =
        app(
            DecisionLedgerWriter::class
        );

    $writer->write(
        $revision,
        $this->normalizedSpec(),
        DecisionOrigin::GENERATED
    );

    $this->expectException(
        LogicException::class
    );

    $writer->write(
        $revision,
        $this->normalizedSpec(),
        DecisionOrigin::GENERATED
    );
}
```

---

# 10.87 Test freeze transaction rollback

Cực kỳ quan trọng.

Nếu ledger write fail:

```text
canonical_json
```

không được persist.

Test:

```php
public function test_freeze_rolls_back_if_ledger_write_fails(): void
{
    $revision =
        $this->freezingRevision();

    $ledger =
        Mockery::mock(
            DecisionLedgerWriter::class
        );

    $ledger
        ->shouldReceive('write')
        ->once()
        ->andThrow(
            new RuntimeException(
                'ledger failure'
            )
        );

    $service =
        $this->makeFreezeService(
            ledger:
                $ledger
        );

    try {
        $service->freeze(
            $revision->id,
            $this->frozenConcept(),
            DecisionOrigin::GENERATED
        );
    } catch (RuntimeException) {
    }

    $revision->refresh();

    self::assertNull(
        $revision->canonical_json
    );

    self::assertNull(
        $revision->canonical_hash
    );

    self::assertSame(
        CanonicalConceptStatus::FREEZING,
        $revision->status
    );
}
```

---

# 10.88 Test idempotent freeze

```php
public function test_same_freeze_can_be_replayed_safely(): void
{
    $revision =
        $this->freezingRevision();

    $frozen =
        $this->frozenConcept();

    $first =
        $this->service()
            ->freeze(
                $revision->id,
                $frozen,
                DecisionOrigin::GENERATED
            );

    $second =
        $this->service()
            ->freeze(
                $revision->id,
                $frozen,
                DecisionOrigin::GENERATED
            );

    self::assertFalse(
        $first->reused
    );

    self::assertTrue(
        $second->reused
    );

    self::assertSame(
        $first->frozen->hash,
        $second->frozen->hash
    );
}
```

---

# 10.89 Test frozen replay with different bytes rejected

```php
public function test_frozen_revision_rejects_different_hash(): void
{
    $revision =
        $this->frozenRevision();

    $different =
        $this->differentFrozenConcept();

    $this->expectException(
        RuntimeException::class
    );

    $this->freezeService()
        ->freeze(
            $revision->id,
            $different,
            DecisionOrigin::GENERATED
        );
}
```

---

# 10.90 Test ConceptInput snapshot

```php
public function test_concept_input_snapshot_hash_detects_tampering(): void
{
    $revision =
        $this->revisionWithInputSnapshot();

    $revision->forceFill([
        'concept_input_json' =>
            '{"tampered":true}',
    ])->saveQuietly();

    $this->expectException(
        RuntimeException::class
    );

    app(
        CanonicalConceptInputLoader::class
    )->load(
        $revision->fresh()
    );
}
```

---

# 10.91 Test provider retry không tăng repair count

Case:

```text
Sonnet request
↓
HTTP 503
↓
LlmClient retries
↓
success
```

Expected:

```text
repair_count = 0
generation semantic attempts = 1
```

Không:

```text
attempts = 2
repair_count = 1
```

Đây nên là integration test với fake LlmClient.

---

# 10.92 Test second semantic failure terminal

Flow:

```text
generation
↓
validation fail
↓
repair #1
↓
validation fail
```

Expected:

```text
repair_count = 1
status = failed
no second repair attempt
```

Test assert:

```php
self::assertSame(
    1,
    CanonicalConceptAttempt::query()
        ->where(
            'canonical_concept_revision_id',
            $revision->id
        )
        ->where(
            'attempt_type',
            CanonicalAttemptType::REPAIR
        )
        ->count()
);
```

---

# 10.93 Final DB relation

Sau Phần 10:

```text
video_projects
│
├── canonical_concept_revisions
│       │
│       ├── canonical_concept_attempts
│       │       ├── generation
│       │       └── repair
│       │
│       ├── canonical_validation_runs
│       │
│       ├── canonical_decisions
│       │
│       └── canonical_concept_events
│
└── video_sessions
        │
        └── references canonical revision
```

Tôi khuyên `video_sessions` có:

```php
$table
    ->uuid(
        'canonical_concept_revision_id'
    )
    ->nullable();
```

Foreign:

```php
$table
    ->foreign(
        'canonical_concept_revision_id'
    )
    ->references('id')
    ->on('canonical_concept_revisions')
    ->nullOnDelete();
```

Sau khi session chọn design:

```text
video_sessions.canonical_concept_revision_id
```

trỏ vào exact frozen revision.

Không copy canonical JSON vào session.

---

# 10.94 Một session không được trỏ revision chưa frozen

Đây không dễ enforce bằng FK.

Service phải:

```php
public function attachToSession(
    string $sessionId,
    string $revisionId
): void {
    DB::transaction(
        function () use (
            $sessionId,
            $revisionId
        ): void {
            $revision =
                CanonicalConceptRevision::query()
                    ->whereKey(
                        $revisionId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

            if (
                $revision->status
                !== CanonicalConceptStatus::FROZEN
            ) {
                throw new LogicException(
                    'Video session may reference '
                    . 'only a frozen canonical revision.'
                );
            }

            $session =
                VideoSession::query()
                    ->whereKey(
                        $sessionId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

            if (
                $session->video_project_id
                !== $revision->video_project_id
            ) {
                throw new LogicException(
                    'Canonical revision belongs '
                    . 'to another video project.'
                );
            }

            $session->forceFill([
                'canonical_concept_revision_id' =>
                    $revision->id,
            ])->save();
        }
    );
}
```

Đây là cross-aggregate invariant cần giữ.

---

# 10.95 State machine cuối cùng

Sau khi ghép tất cả:

```text
                    ┌───────────┐
                    │  PENDING  │
                    └─────┬─────┘
                          │
                          ▼
                   ┌────────────┐
                   │ GENERATING │
                   └─────┬──────┘
                         │
                         ▼
                    ┌───────────┐
                    │ GENERATED │
                    └─────┬─────┘
                          │
                          ▼
                   ┌────────────┐
             ┌─────│ VALIDATING │──────┐
             │     └─────┬──────┘      │
             │ FAIL      │ PASS         │ terminal error
             ▼           ▼              ▼
 ┌──────────────────┐ ┌─────────────┐ ┌────────┐
 │VALIDATION_FAILED │ │ NORMALIZING │ │ FAILED │
 └────────┬─────────┘ └──────┬──────┘ └────────┘
          │                  │
          ▼                  ▼
    ┌───────────┐       ┌────────────┐
    │ REPAIRING │       │ NORMALIZED │
    └─────┬─────┘       └──────┬─────┘
          │                    │
          ▼                    ▼
     ┌──────────┐         ┌──────────┐
     │ REPAIRED │         │ FREEZING │
     └────┬─────┘         └────┬─────┘
          │                    │
          └──→ VALIDATING      ▼
                           ┌────────┐
                           │ FROZEN │
                           └────────┘
```

Repair loop chỉ được:

```text
VALIDATION_FAILED
       ↓
REPAIRING
       ↓
REPAIRED
       ↓
VALIDATING
```

**một lần duy nhất.**

Không có:

```text
VALIDATION_FAILED
→ REPAIR
→ FAIL
→ REPAIR
→ FAIL
→ REPAIR...
```

---

# 10.96 Checkpoint semantics

DB lúc này cho phép biết chính xác:

```text
PENDING
    revision allocated

GENERATING
    Sonnet semantic generation đang chạy

GENERATED
    raw structured output đã checkpoint

VALIDATING
    validation đang chạy

VALIDATION_FAILED
    errors đã checkpoint

REPAIRING
    repair slot đã atomically claim

REPAIRED
    repaired raw output đã checkpoint

NORMALIZING
    valid semantic DTO đang normalize

NORMALIZED
    canonical candidate đã serialize + revalidate

FREEZING
    chuẩn bị atomic commit

FROZEN
    immutable canonical truth committed

FAILED
    revision terminal
```

Đây mới đúng nghĩa checkpoint state machine, không chỉ là một cột `status` trang trí.

---

# 10.97 Một thay đổi tôi chốt so với thiết kế ban đầu

Từ Phần 10, đừng để:

```text
video_sessions.render_plan JSON
```

trở thành nơi chứa Canonical Design.

Hai thứ khác nhau:

```text
CanonicalDesignSpec
= WHAT THE OBJECT IS

RenderPlan
= HOW THIS VIDEO WILL SHOW IT
```

Quan hệ:

```text
video_project
      │
      ▼
canonical_concept_revision
      │
      │ frozen identity
      ▼
video_session
      │
      ▼
creative arc
      ↓
render plan
      ↓
shots
```

RenderPlan chỉ **reference canonical revision/hash**.

Ví dụ:

```json
{
  "canonical_identity": {
    "revision_id": "uuid",
    "revision": 3,
    "hash": "..."
  },

  "scenes": [...]
}
```

Không copy toàn bộ canonical design vào RenderPlan trừ khi cần tạo immutable execution snapshot; nếu snapshot thì phải ghi rõ đó là derived snapshot và verify hash.

---

# 10.98 Production invariant cuối cùng

Sau Phần 10 chúng ta có thể khóa 10 invariant:

```text
1. One project can have many canonical revisions.

2. Revision number is allocated transactionally.

3. ConceptInput is snapshotted before execution.

4. Provider generation output is checkpointed.

5. Provider retry != semantic repair.

6. Maximum semantic repair = exactly 1.

7. Decision Ledger derives only from normalized,
   revalidated canonical design.

8. Freeze is one atomic DB transaction.

9. Frozen canonical_json/hash are immutable.

10. Downstream uses exact frozen revision + hash.
```

Và invariant mạnh nhất từ Phần 9 vẫn giữ:

```text
Canonical JSON generated by serializer
             │
             ▼
      REVALIDATED BYTES
             │
             ▼
        HASHED BYTES
             │
             ▼
        FROZEN BYTES
             │
             ▼
      DOWNSTREAM BYTES

       EXACTLY THE SAME
```

## Sau Phần 10, kiến trúc tổng thể đã đến đây

```text
ARTICLE
   ↓
Haiku Evidence Extraction
   ↓
Deterministic Evidence Verification
   ↓
InspirationBuilder
   ↓
InspirationBrief
   ↓
CategoryCreativeProfileRegistry
   ↓
ObjectTypeResolver
   ↓
ConceptInput snapshot
   ↓
EffectiveConceptSchemaBuilder
   ↓
Sonnet 5 Structured Output
   ↓
Raw output checkpoint
   ↓
Core V1 Validation
   ↓
Effective Schema Validation
   ↓
DTO Hydration
   ↓
Cross-field Validation
   ↓
Provenance Validation
   ↓
CategorySemanticValidatorRegistry
   ↓
         FAIL
           │
           ▼
    ONE REPAIR ONLY
           │
           └────────→ Re-validation
                          │
                          ▼
                      NORMALIZE
                          ↓
              Schema-aware serialization
                          ↓
                    Re-validation
                          ↓
                    Canonical JSON
                          ↓
                       SHA-256
                          ↓
                 Decision Ledger
                          ↓
                 ATOMIC DB FREEZE
                          ↓
              FROZEN CANONICAL REVISION
                          │
             ┌────────────┴─────────────┐
             │                          │
             ▼                          ▼
       Python Projection          Revision Audit
             │
             ▼
      Constraint Normalizer
             ↓
       Prompt Compiler
             ↓
       Provider Adapter
             ↓
         GPT Image
             ↓
         Vision QA
```

**Phần 10 là điểm tôi sẽ coi Canonical Concept Layer đã đủ điều kiện để trở thành source-of-truth production.**

Phần tiếp theo nên là **Phần 11 — Python `AssetProjection` + `ConstraintSet` + `ConstraintNormalizer`**, nhưng tôi sẽ giữ một nguyên tắc rất chặt: **Python không đọc DB rồi tự diễn giải lại design**. Laravel cấp cho Python `canonical_json + canonical_hash + revision metadata`; Python verify SHA-256 trước, sau đó chỉ tạo **derived projection**, không bao giờ mutate Canonical Design. Đây là bước bắt đầu chuyển từ **Semantic IR → Render IR**.
