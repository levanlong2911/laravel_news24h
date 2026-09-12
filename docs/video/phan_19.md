Được. Theo baseline **one production path** đã khóa từ Part 1–18, thì **Part 19 nên là Final Composition + Final Master Freeze**.

Part 19 **không được chọn lại shot, không được lập story mới, không được tự lấy render mới nhất**. Nó chỉ được phép lấy các `ApprovedShotRevision` đã freeze ở Part 18, đóng băng một `FinalCompositionPlan`, giao plan đó cho Python ghép deterministic bằng FFmpeg, QA file cuối, rồi freeze `FinalMasterRevision`.

# PART 19 — Final Composition Plan → Deterministic Assembly → Final QA → Final Master Freeze

Production flow:

```text id="l29oh7"
PART 18
ApprovedShotRevision[]
        │
        ▼
══════════════════════════════════════════════
PART 19
══════════════════════════════════════════════

ApprovedShotRevision[]
        +
Approved Audio Assets
        +
Approved Subtitle Assets
        +
Frozen RenderPlan
        ↓
FinalCompositionPlanBuilder
        ↓
FinalCompositionPlan DTO
        ↓
Composition Validation
        ↓
Schema-aware Serialization
        ↓
Exact FinalCompositionPlan JSON Bytes
        ↓
Re-validation exact bytes
        ↓
SHA-256 composition_plan_hash
        ↓
Freeze FinalCompositionPlan
        ↓
Laravel FinalRenderExecution
        ↓
claim / lease
        ↓
Python FinalCompositionWorker
        ↓
Artifact Integrity Verification
        ↓
FFmpeg Deterministic Assembly
        ↓
Final Video Artifact
        ↓
SHA-256 final_artifact_hash
        ↓
Technical Final QA
        ↓
Composition Lineage QA
        ↓
FinalMasterRevision
        ↓
SHA-256 exact manifest bytes
        ↓
Freeze Final Master
```

Và rule quan trọng nhất:

```text id="w60x4o"
FinalCompositionPlan
KHÔNG BAO GIỜ
SELECT latest render

FinalCompositionPlan
chỉ chứa
ApprovedShotRevision
+ exact artifact_hash
```

---

# 19.1. Authority boundary

Laravel tiếp tục là control plane.

Laravel quyết định và freeze:

```text id="2quzhi"
shot order
approved shot revision
timeline position
trim in/out
transition
audio track selection
audio timing
subtitle asset
output format
composition revision
final approval
```

Python chỉ:

```text id="3q8lvc"
verify hashes
materialize exact inputs
execute ffmpeg
generate technical metadata
hash final artifact
return execution result
```

Python **không được**:

```text id="92dr7y"
query shots table
query renders table
pick latest render
change shot order
drop failed shot
choose another take
rewrite subtitle
replace music
change timeline duration
```

Đây là continuation trực tiếp của boundary Part 1–18.

---

# 19.2. Production input

Part19 chỉ nhận:

```text id="1kwwap"
FrozenRenderPlan
+
ApprovedShotRevision[]
+
ApprovedAudioRevision[]
+
ApprovedSubtitleRevision[]
```

Trong trường hợp project chưa có audio/subtitle:

```text id="b7k5a4"
audio_tracks = []
subtitle_tracks = []
```

vẫn hợp lệ.

Không tạo fallback asset ngầm.

---

# 19.3. Laravel package

Tôi đề xuất:

```text id="8fh04n"
app/Video/FinalComposition/
├── FinalCompositionPlan.php
├── FinalCompositionShot.php
├── FinalAudioTrack.php
├── FinalSubtitleTrack.php
├── FinalOutputSpec.php
├── FinalCompositionPlanBuilder.php
├── FinalCompositionPlanValidator.php
├── FinalCompositionSerializer.php
├── FinalCompositionHasher.php
├── FinalCompositionFreezer.php
├── FinalCompositionRepository.php
│
├── Execution/
│   ├── FinalRenderExecution.php
│   ├── FinalRenderExecutionService.php
│   ├── FinalRenderClaimService.php
│   └── FinalRenderResultService.php
│
└── Approval/
    ├── FinalMasterRevision.php
    ├── FinalMasterManifest.php
    ├── FinalMasterValidator.php
    └── FinalMasterFreezer.php
```

Không tạo:

```text id="ro7k39"
SuperyachtFinalComposer.php
NewsFinalComposer.php
CarFinalComposer.php
```

Part19 vẫn universal.

---

# 19.4. `FinalCompositionShot`

```php id="yevi3l"
<?php

namespace App\Video\FinalComposition;

use InvalidArgumentException;

final class FinalCompositionShot
{
    public function __construct(
        public readonly string $shotCode,
        public readonly string $approvedShotRevisionId,
        public readonly int $revision,
        public readonly string $artifactHash,
        public readonly int $sourceDurationMs,
        public readonly int $trimInMs,
        public readonly int $trimOutMs,
        public readonly int $timelineStartMs,
        public readonly string $transitionIn,
        public readonly int $transitionInMs,
        public readonly string $transitionOut,
        public readonly int $transitionOutMs,
    ) {
        if ($this->shotCode === '') {
            throw new InvalidArgumentException(
                'shotCode cannot be empty.'
            );
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $this->artifactHash)) {
            throw new InvalidArgumentException(
                'artifactHash must be SHA-256 hex.'
            );
        }

        if ($this->revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }

        if ($this->sourceDurationMs <= 0) {
            throw new InvalidArgumentException(
                'sourceDurationMs must be positive.'
            );
        }

        if ($this->trimInMs < 0) {
            throw new InvalidArgumentException(
                'trimInMs cannot be negative.'
            );
        }

        if ($this->trimOutMs <= $this->trimInMs) {
            throw new InvalidArgumentException(
                'trimOutMs must be greater than trimInMs.'
            );
        }

        if ($this->trimOutMs > $this->sourceDurationMs) {
            throw new InvalidArgumentException(
                'trimOutMs exceeds source duration.'
            );
        }

        if ($this->timelineStartMs < 0) {
            throw new InvalidArgumentException(
                'timelineStartMs cannot be negative.'
            );
        }
    }

    public function durationMs(): int
    {
        return $this->trimOutMs - $this->trimInMs;
    }

    public function timelineEndMs(): int
    {
        return $this->timelineStartMs + $this->durationMs();
    }

    public function toArray(): array
    {
        return [
            'shot_code' => $this->shotCode,
            'approved_shot_revision_id' =>
                $this->approvedShotRevisionId,
            'revision' => $this->revision,
            'artifact_hash' => $this->artifactHash,
            'source_duration_ms' => $this->sourceDurationMs,
            'trim_in_ms' => $this->trimInMs,
            'trim_out_ms' => $this->trimOutMs,
            'timeline_start_ms' => $this->timelineStartMs,
            'transition_in' => $this->transitionIn,
            'transition_in_ms' => $this->transitionInMs,
            'transition_out' => $this->transitionOut,
            'transition_out_ms' => $this->transitionOutMs,
        ];
    }
}
```

---

# 19.5. Không cho arbitrary transition

Transition phải dùng closed enum/value set.

Ví dụ:

```php id="scqizu"
<?php

namespace App\Video\FinalComposition;

enum FinalTransition: string
{
    case Cut = 'cut';
    case Crossfade = 'crossfade';
    case FadeFromBlack = 'fade_from_black';
    case FadeToBlack = 'fade_to_black';
}
```

Không để LLM trả:

```text id="4lfhjs"
"cinematic beautiful smooth morphing transition"
```

rồi compiler đoán.

---

# 19.6. Output spec

```php id="y1tu0a"
<?php

namespace App\Video\FinalComposition;

use InvalidArgumentException;

final class FinalOutputSpec
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $fps,
        public readonly string $videoCodec,
        public readonly string $pixelFormat,
        public readonly string $audioCodec,
        public readonly int $audioSampleRate,
        public readonly int $audioChannels,
        public readonly string $container,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException(
                'Output dimensions must be positive.'
            );
        }

        if ($fps <= 0) {
            throw new InvalidArgumentException(
                'fps must be positive.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'fps' => $this->fps,
            'video_codec' => $this->videoCodec,
            'pixel_format' => $this->pixelFormat,
            'audio_codec' => $this->audioCodec,
            'audio_sample_rate' => $this->audioSampleRate,
            'audio_channels' => $this->audioChannels,
            'container' => $this->container,
        ];
    }
}
```

Với pipeline 9:16 của bạn, project requirement có thể freeze:

```json id="1q3jsb"
{
  "width": 1080,
  "height": 1920,
  "fps": 30,
  "video_codec": "h264",
  "pixel_format": "yuv420p",
  "audio_codec": "aac",
  "audio_sample_rate": 48000,
  "audio_channels": 2,
  "container": "mp4"
}
```

Nhưng output spec phải đến từ frozen project/render requirement, không hard-code trong Python.

---

# 19.7. Audio track

```php id="bwwy2f"
<?php

namespace App\Video\FinalComposition;

final class FinalAudioTrack
{
    public function __construct(
        public readonly string $trackId,
        public readonly string $role,
        public readonly string $artifactHash,
        public readonly int $timelineStartMs,
        public readonly int $trimInMs,
        public readonly int $trimOutMs,
        public readonly float $gainDb,
        public readonly int $fadeInMs,
        public readonly int $fadeOutMs,
    ) {}

    public function toArray(): array
    {
        return [
            'track_id' => $this->trackId,
            'role' => $this->role,
            'artifact_hash' => $this->artifactHash,
            'timeline_start_ms' => $this->timelineStartMs,
            'trim_in_ms' => $this->trimInMs,
            'trim_out_ms' => $this->trimOutMs,
            'gain_db' => $this->gainDb,
            'fade_in_ms' => $this->fadeInMs,
            'fade_out_ms' => $this->fadeOutMs,
        ];
    }
}
```

`role` nên closed list:

```text id="p2elto"
voiceover
music
dialogue
ambient
effect
```

---

# 19.8. Subtitle trong Part19 là approved input

Part19 **không viết subtitle**.

Nó chỉ burn/embed một subtitle revision đã được approve.

```php id="2i61qt"
<?php

namespace App\Video\FinalComposition;

final class FinalSubtitleTrack
{
    public function __construct(
        public readonly string $subtitleRevisionId,
        public readonly string $artifactHash,
        public readonly string $format,
        public readonly string $language,
        public readonly string $mode,
    ) {}

    public function toArray(): array
    {
        return [
            'subtitle_revision_id' =>
                $this->subtitleRevisionId,

            'artifact_hash' =>
                $this->artifactHash,

            'format' =>
                $this->format,

            'language' =>
                $this->language,

            'mode' =>
                $this->mode,
        ];
    }
}
```

`mode`:

```text id="eh1ssh"
none
burn_in
embedded
```

---

# 19.9. `FinalCompositionPlan`

```php id="bw2d3w"
<?php

namespace App\Video\FinalComposition;

final class FinalCompositionPlan
{
    /**
     * @param list<FinalCompositionShot> $shots
     * @param list<FinalAudioTrack> $audioTracks
     * @param list<FinalSubtitleTrack> $subtitleTracks
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $videoSessionId,
        public readonly int $compositionRevision,

        public readonly string $renderPlanHash,
        public readonly string $canonicalHash,
        public readonly string $identityLockHash,

        public readonly array $shots,
        public readonly array $audioTracks,
        public readonly array $subtitleTracks,

        public readonly FinalOutputSpec $output,

        public readonly int $timelineDurationMs,
    ) {}

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,

            'video_session_id' =>
                $this->videoSessionId,

            'composition_revision' =>
                $this->compositionRevision,

            'render_plan_hash' =>
                $this->renderPlanHash,

            'canonical_hash' =>
                $this->canonicalHash,

            'identity_lock_hash' =>
                $this->identityLockHash,

            'shots' => array_map(
                static fn (
                    FinalCompositionShot $shot
                ): array => $shot->toArray(),
                $this->shots,
            ),

            'audio_tracks' => array_map(
                static fn (
                    FinalAudioTrack $track
                ): array => $track->toArray(),
                $this->audioTracks,
            ),

            'subtitle_tracks' => array_map(
                static fn (
                    FinalSubtitleTrack $track
                ): array => $track->toArray(),
                $this->subtitleTracks,
            ),

            'output' =>
                $this->output->toArray(),

            'timeline_duration_ms' =>
                $this->timelineDurationMs,
        ];
    }
}
```

---

# 19.10. FinalCompositionPlanBuilder

Đây là stage authoritative duy nhất để build timeline.

```php id="9i2fnt"
<?php

namespace App\Video\FinalComposition;

use App\Models\VideoSession;
use DomainException;

final class FinalCompositionPlanBuilder
{
    public function build(
        VideoSession $session,
        array $approvedShotRevisions,
        array $approvedAudioRevisions,
        array $approvedSubtitleRevisions,
        FinalOutputSpec $output,
        int $compositionRevision,
    ): FinalCompositionPlan {
        if ($approvedShotRevisions === []) {
            throw new DomainException(
                'Final composition requires approved shots.'
            );
        }

        $shots = [];
        $cursorMs = 0;

        foreach ($approvedShotRevisions as $revision) {
            /*
             * Mapping phải dùng approved revision đã freeze,
             * không query latest render ở đây.
             */
            $durationMs = (int) $revision->duration_ms;

            if ($durationMs <= 0) {
                throw new DomainException(
                    'Approved shot has invalid duration.'
                );
            }

            $shots[] = new FinalCompositionShot(
                shotCode: $revision->shot_code,
                approvedShotRevisionId:
                    (string) $revision->id,
                revision:
                    (int) $revision->revision,
                artifactHash:
                    $revision->artifact_hash,
                sourceDurationMs:
                    $durationMs,
                trimInMs: 0,
                trimOutMs:
                    $durationMs,
                timelineStartMs:
                    $cursorMs,
                transitionIn:
                    FinalTransition::Cut->value,
                transitionInMs: 0,
                transitionOut:
                    FinalTransition::Cut->value,
                transitionOutMs: 0,
            );

            $cursorMs += $durationMs;
        }

        return new FinalCompositionPlan(
            schemaVersion: '1.0',
            videoSessionId:
                (string) $session->id,
            compositionRevision:
                $compositionRevision,

            renderPlanHash:
                $session->render_plan_hash,

            canonicalHash:
                $session->canonical_hash,

            identityLockHash:
                $session->identity_lock_hash,

            shots:
                $shots,

            audioTracks:
                $this->mapAudio(
                    $approvedAudioRevisions
                ),

            subtitleTracks:
                $this->mapSubtitles(
                    $approvedSubtitleRevisions
                ),

            output:
                $output,

            timelineDurationMs:
                $cursorMs,
        );
    }

    private function mapAudio(
        array $revisions
    ): array {
        // Map approved immutable audio revisions only.
        return [];
    }

    private function mapSubtitles(
        array $revisions
    ): array {
        // Map approved immutable subtitle revisions only.
        return [];
    }
}
```

Ở code thật, `mapAudio()` và `mapSubtitles()` phải map đúng model hiện tại. Không nên đoán property khi chưa đọc model.

---

# 19.11. Không rebuild order từ DB

Sai:

```php id="0zbu55"
VideoShot::query()
    ->where('video_session_id', $id)
    ->orderBy('created_at')
    ->get();
```

Cũng sai:

```php id="i6e83o"
Render::where('shot_id', $shotId)
    ->latest()
    ->first();
```

Đúng:

```text id="wbjp8h"
Frozen RenderPlan
↓
shot_code order
↓
ApprovedShotRevision ID for each shot_code
↓
artifact_hash
```

RenderPlan là authority về thứ tự.

ApprovedShotRevision là authority về artifact.

---

# 19.12. Validator

```php id="kdz43c"
<?php

namespace App\Video\FinalComposition;

use DomainException;

final class FinalCompositionPlanValidator
{
    public function validate(
        FinalCompositionPlan $plan
    ): void {
        if ($plan->shots === []) {
            throw new DomainException(
                'FinalCompositionPlan has no shots.'
            );
        }

        $shotCodes = [];
        $revisionIds = [];
        $expectedStartMs = 0;

        foreach ($plan->shots as $shot) {
            if (isset($shotCodes[$shot->shotCode])) {
                throw new DomainException(
                    'Duplicate shot_code: '
                    .$shot->shotCode
                );
            }

            $shotCodes[$shot->shotCode] = true;

            if (
                isset(
                    $revisionIds[
                        $shot->approvedShotRevisionId
                    ]
                )
            ) {
                throw new DomainException(
                    'ApprovedShotRevision reused twice.'
                );
            }

            $revisionIds[
                $shot->approvedShotRevisionId
            ] = true;

            if (
                $shot->timelineStartMs
                !== $expectedStartMs
            ) {
                throw new DomainException(
                    sprintf(
                        'Timeline gap/overlap at shot %s.',
                        $shot->shotCode,
                    )
                );
            }

            $expectedStartMs =
                $shot->timelineEndMs();
        }

        if (
            $expectedStartMs
            !== $plan->timelineDurationMs
        ) {
            throw new DomainException(
                'timelineDurationMs does not match shots.'
            );
        }
    }
}
```

Khi sau này có crossfade, validator phải tính overlap explicit.

Không được tự suy luận.

---

# 19.13. Serializer exact bytes

Part19 tiếp tục rule của Canonical:

```text id="tq3qj9"
validated bytes
=
hashed bytes
=
frozen bytes
```

`FinalCompositionSerializer.php`:

```php id="yvp62h"
<?php

namespace App\Video\FinalComposition;

use JsonException;

final class FinalCompositionSerializer
{
    /**
     * @throws JsonException
     */
    public function serialize(
        FinalCompositionPlan $plan
    ): string {
        return json_encode(
            $this->sortRecursive(
                $plan->toArray()
            ),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function sortRecursive(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->sortRecursive($item),
                $value,
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->sortRecursive($item);
        }

        return $value;
    }
}
```

---

# 19.14. Hash exact bytes

```php id="cp2kbl"
<?php

namespace App\Video\FinalComposition;

final class FinalCompositionHasher
{
    public function hash(
        string $exactJsonBytes
    ): string {
        return hash(
            'sha256',
            $exactJsonBytes
        );
    }
}
```

Không:

```php id="ae9glu"
hash(json_encode(json_decode($bytes, true)))
```

vì đó là encode lại.

---

# 19.15. Freeze transaction

```php id="t6744h"
<?php

namespace App\Video\FinalComposition;

use Illuminate\Support\Facades\DB;

final class FinalCompositionFreezer
{
    public function __construct(
        private readonly FinalCompositionPlanValidator $validator,
        private readonly FinalCompositionSerializer $serializer,
        private readonly FinalCompositionHasher $hasher,
    ) {}

    public function freeze(
        FinalCompositionPlan $plan
    ): FrozenFinalComposition {
        $this->validator->validate(
            $plan
        );

        $bytes = $this->serializer->serialize(
            $plan
        );

        /*
         * Re-validation MUST use decoded data
         * from these exact serialized bytes.
         */
        $decoded = json_decode(
            $bytes,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $rehydrated =
            FinalCompositionPlanFactory::fromArray(
                $decoded
            );

        $this->validator->validate(
            $rehydrated
        );

        $hash = $this->hasher->hash(
            $bytes
        );

        return DB::transaction(
            function () use (
                $plan,
                $bytes,
                $hash,
            ): FrozenFinalComposition {
                return FrozenFinalComposition::create([
                    'video_session_id' =>
                        $plan->videoSessionId,

                    'revision' =>
                        $plan->compositionRevision,

                    'composition_plan_hash' =>
                        $hash,

                    'composition_plan_json' =>
                        $bytes,

                    'status' =>
                        'FROZEN',

                    'frozen_at' =>
                        now(),
                ]);
            }
        );
    }
}
```

`FinalCompositionPlanFactory` phải strict.

Không silently default missing fields.

---

# 19.16. Database

Tôi sẽ tách:

```text id="rl8qp3"
final_compositions
final_render_executions
final_master_revisions
```

Migration:

```php id="y00142"
Schema::create('final_compositions', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->uuid(
        'video_session_id'
    );

    $table->unsignedInteger(
        'revision'
    );

    $table->string(
        'canonical_hash',
        64
    );

    $table->string(
        'identity_lock_hash',
        64
    );

    $table->string(
        'render_plan_hash',
        64
    );

    $table->string(
        'composition_plan_hash',
        64
    )->unique();

    $table->longText(
        'composition_plan_json'
    );

    $table->string(
        'status',
        32
    );

    $table->timestamp(
        'frozen_at'
    )->nullable();

    $table->timestamps();

    $table->unique([
        'video_session_id',
        'revision',
    ]);
});
```

Tôi dùng `longText` cho exact JSON bytes thay vì Eloquent JSON cast nếu mục tiêu là bảo toàn exact serialized representation.

Đây rất quan trọng.

Nếu dùng:

```php id="z8keo2"
$table->json(...)
```

rồi DB/ORM decode/re-encode thì không nên lấy byte representation đọc lại từ JSON column làm authoritative hash source.

---

# 19.17. Final render execution

Giống Part14:

```text id="bk1plt"
PENDING
↓
CLAIMED
↓
ASSEMBLING
↓
ARTIFACT_STORED
↓
QA_PENDING
↓
SUCCEEDED
```

Có thể fail:

```text id="1gu50n"
FAILED_RETRYABLE
FAILED_FATAL
NEEDS_RECONCILIATION
```

Model:

```php id="ca8f1g"
final class FinalRenderExecution extends Model
{
    protected $table = 'final_render_executions';

    protected $casts = [
        'attempt' => 'integer',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
```

Migration:

```php id="e3pzd4"
Schema::create('final_render_executions', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->uuid(
        'final_composition_id'
    );

    $table->string(
        'composition_plan_hash',
        64
    );

    $table->string(
        'execution_key',
        64
    )->unique();

    $table->string(
        'status',
        32
    );

    $table->unsignedInteger(
        'attempt'
    )->default(0);

    $table->uuid(
        'claim_token'
    )->nullable();

    $table->timestamp(
        'lease_expires_at'
    )->nullable();

    $table->string(
        'final_artifact_hash',
        64
    )->nullable();

    $table->text(
        'artifact_path'
    )->nullable();

    $table->json(
        'technical_metadata'
    )->nullable();

    $table->text(
        'last_error'
    )->nullable();

    $table->timestamp(
        'started_at'
    )->nullable();

    $table->timestamp(
        'completed_at'
    )->nullable();

    $table->timestamps();

    $table->index([
        'status',
        'lease_expires_at',
    ]);
});
```

---

# 19.18. execution key

Final rendering cũng phải idempotent.

```php id="9fzg69"
$executionKey = hash(
    'sha256',
    implode('|', [
        'final-render-v1',
        $compositionPlanHash,
    ])
);
```

Một frozen plan:

```text id="rduyaq"
composition_plan_hash = X
```

chỉ có một logical final render execution identity.

Retry technical không thay composition plan.

---

# 19.19. Laravel → Python payload

Laravel gửi exact frozen package:

```json id="kznse4"
{
  "execution_id": "...",
  "claim_token": "...",

  "composition_plan_hash": "...",

  "composition_plan_json": {
    "schema_version": "1.0",
    "...": "..."
  },

  "assets": [
    {
      "artifact_hash": "...",
      "role": "video_shot",
      "local_or_signed_source": "..."
    }
  ]
}
```

Python không được:

```text id="9v5wjq"
GET /shots
GET /renders
GET /audio
```

để tự hoàn thiện dữ liệu thiếu.

Thiếu asset:

```text id="zbc9im"
FAIL
```

không fallback.

---

# 19.20. Python structure

```text id="rl748r"
media_runtime/
└── final_composition/
    ├── __init__.py
    ├── models.py
    ├── parser.py
    ├── integrity.py
    ├── timeline.py
    ├── ffmpeg_graph.py
    ├── assembler.py
    ├── probe.py
    ├── qa.py
    ├── manifest.py
    └── worker.py
```

---

# 19.21. Python models

```python id="cuxm8r"
from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class CompositionShot:
    shot_code: str
    approved_shot_revision_id: str
    revision: int
    artifact_hash: str

    source_duration_ms: int
    trim_in_ms: int
    trim_out_ms: int

    timeline_start_ms: int

    transition_in: str
    transition_in_ms: int

    transition_out: str
    transition_out_ms: int


@dataclass(frozen=True, slots=True)
class OutputSpec:
    width: int
    height: int
    fps: int

    video_codec: str
    pixel_format: str

    audio_codec: str
    audio_sample_rate: int
    audio_channels: int

    container: str


@dataclass(frozen=True, slots=True)
class FinalCompositionPlan:
    schema_version: str
    video_session_id: str
    composition_revision: int

    render_plan_hash: str
    canonical_hash: str
    identity_lock_hash: str

    shots: tuple[CompositionShot, ...]

    timeline_duration_ms: int

    output: OutputSpec
```

Audio/subtitle DTO tương tự.

---

# 19.22. Verify frozen plan hash

Python phải verify Laravel gửi đúng bytes/hash.

```python id="vz17qm"
from __future__ import annotations

import hashlib


class CompositionIntegrityError(
    RuntimeError
):
    pass


def verify_plan_hash(
    *,
    exact_plan_bytes: bytes,
    expected_hash: str,
) -> None:
    actual = hashlib.sha256(
        exact_plan_bytes
    ).hexdigest()

    if actual != expected_hash:
        raise CompositionIntegrityError(
            "FinalCompositionPlan hash mismatch."
        )
```

Tốt nhất Laravel gửi exact JSON bytes/file chứ không gửi object đã reserialize qua HTTP framework nếu bạn muốn verify byte-perfect across boundary.

Nếu transport bắt buộc JSON object, thì gửi thêm:

```text id="d4ccfp"
composition_plan_base64
```

hoặc artifact/file reference.

---

# 19.23. Verify every input artifact

```python id="yk87y8"
from pathlib import Path
import hashlib


def sha256_file(
    path: Path
) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as stream:
        for chunk in iter(
            lambda: stream.read(
                1024 * 1024
            ),
            b"",
        ):
            digest.update(chunk)

    return digest.hexdigest()


def verify_asset(
    *,
    path: Path,
    expected_hash: str,
) -> None:
    actual = sha256_file(path)

    if actual != expected_hash:
        raise CompositionIntegrityError(
            f"Asset hash mismatch: {path.name}"
        )
```

Không ghép video nếu chỉ một shot hash sai.

---

# 19.24. Deterministic timeline validation trong Python

Laravel đã validate rồi.

Python validate lần nữa vì đây là trust boundary.

```python id="kwbukz"
def validate_timeline(
    plan: FinalCompositionPlan,
) -> None:
    if not plan.shots:
        raise ValueError(
            "Composition contains no shots."
        )

    cursor_ms = 0
    seen_codes: set[str] = set()
    seen_revisions: set[str] = set()

    for shot in plan.shots:
        if shot.shot_code in seen_codes:
            raise ValueError(
                f"Duplicate shot code: "
                f"{shot.shot_code}"
            )

        seen_codes.add(
            shot.shot_code
        )

        if (
            shot.approved_shot_revision_id
            in seen_revisions
        ):
            raise ValueError(
                "Approved shot revision reused."
            )

        seen_revisions.add(
            shot.approved_shot_revision_id
        )

        if shot.timeline_start_ms != cursor_ms:
            raise ValueError(
                f"Timeline discontinuity at "
                f"{shot.shot_code}."
            )

        duration_ms = (
            shot.trim_out_ms
            - shot.trim_in_ms
        )

        if duration_ms <= 0:
            raise ValueError(
                "Invalid shot trim."
            )

        cursor_ms += duration_ms

    if cursor_ms != plan.timeline_duration_ms:
        raise ValueError(
            "Timeline duration mismatch."
        )
```

---

# 19.25. FFmpeg graph compiler

Không để FFmpeg command nằm rải rác.

```python id="xkv99e"
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True, slots=True)
class InputAsset:
    path: Path
    artifact_hash: str


@dataclass(frozen=True, slots=True)
class CompiledFFmpegGraph:
    command: tuple[str, ...]
```

Compiler:

```python id="mnbjqk"
class FFmpegGraphCompiler:
    def compile_cut_only(
        self,
        *,
        plan: FinalCompositionPlan,
        inputs: tuple[InputAsset, ...],
        output_path: Path,
    ) -> CompiledFFmpegGraph:
        if len(plan.shots) != len(inputs):
            raise ValueError(
                "Shot/input cardinality mismatch."
            )

        command: list[str] = [
            "ffmpeg",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
        ]

        for asset in inputs:
            command.extend([
                "-i",
                str(asset.path),
            ])

        filters: list[str] = []

        concat_inputs: list[str] = []

        for index, shot in enumerate(plan.shots):
            start = (
                shot.trim_in_ms / 1000.0
            )

            end = (
                shot.trim_out_ms / 1000.0
            )

            filters.append(
                f"[{index}:v]"
                f"trim=start={start:.3f}:"
                f"end={end:.3f},"
                f"setpts=PTS-STARTPTS,"
                f"scale="
                f"{plan.output.width}:"
                f"{plan.output.height}:"
                f"force_original_aspect_ratio=decrease,"
                f"pad="
                f"{plan.output.width}:"
                f"{plan.output.height}:"
                f"(ow-iw)/2:(oh-ih)/2,"
                f"fps={plan.output.fps},"
                f"format={plan.output.pixel_format}"
                f"[v{index}]"
            )

            concat_inputs.append(
                f"[v{index}]"
            )

        filters.append(
            "".join(concat_inputs)
            + f"concat=n={len(plan.shots)}:"
              "v=1:a=0[vout]"
        )

        command.extend([
            "-filter_complex",
            ";".join(filters),

            "-map",
            "[vout]",

            "-c:v",
            plan.output.video_codec,

            "-pix_fmt",
            plan.output.pixel_format,

            "-r",
            str(plan.output.fps),

            "-movflags",
            "+faststart",

            str(output_path),
        ])

        return CompiledFFmpegGraph(
            command=tuple(command)
        )
```

Đây là cut-only baseline.

Crossfade/audio sẽ compiler riêng nhưng cùng authoritative plan.

---

# 19.26. Không dùng shell string

Không:

```python id="ylc5x1"
os.system(
    "ffmpeg " + user_value
)
```

Luôn:

```python id="cd0cmw"
subprocess.run(
    list(graph.command),
    shell=False,
)
```

---

# 19.27. Assembler

```python id="yt2vmo"
from __future__ import annotations

import subprocess
from pathlib import Path


class FinalAssemblyError(
    RuntimeError
):
    pass


class FinalAssembler:
    def assemble(
        self,
        graph: CompiledFFmpegGraph,
        output_path: Path,
    ) -> Path:
        result = subprocess.run(
            list(graph.command),
            capture_output=True,
            text=True,
            check=False,
        )

        if result.returncode != 0:
            raise FinalAssemblyError(
                result.stderr.strip()
                or "FFmpeg final assembly failed."
            )

        if not output_path.is_file():
            raise FinalAssemblyError(
                "FFmpeg returned success but "
                "output file is missing."
            )

        if output_path.stat().st_size <= 0:
            raise FinalAssemblyError(
                "Final video is empty."
            )

        return output_path
```

---

# 19.28. Final artifact hash

```python id="ue8v1b"
final_artifact_hash = sha256_file(
    final_video_path
)
```

Hash exact `.mp4` bytes.

Không hash metadata object.

---

# 19.29. Final Technical QA

Part19 không cần Vision để kiểm những gì ffprobe biết.

Kiểm:

```text id="iydl90"
container
video stream exists
width
height
fps
duration
codec
pixel format
audio stream requirement
sample rate
audio channels
file readability
```

DTO:

```python id="d2j3ie"
@dataclass(frozen=True, slots=True)
class FinalTechnicalMetadata:
    width: int
    height: int
    duration_ms: int
    fps: float

    video_codec: str
    pixel_format: str

    has_audio: bool
    audio_codec: str | None
    audio_sample_rate: int | None
    audio_channels: int | None
```

---

# 19.30. Duration tolerance

Nếu cut-only, expected:

```python id="2lj3kr"
expected_duration_ms = (
    plan.timeline_duration_ms
)
```

Cho tolerance theo frame:

```python id="t0bg95"
frame_duration_ms = (
    1000.0 / plan.output.fps
)

allowed_tolerance_ms = (
    frame_duration_ms * 2
)
```

Không dùng arbitrary:

```text id="3ir20l"
±1 second
```

cho mọi video.

---

# 19.31. Final lineage manifest

Sau render thành công, Python trả:

```json id="ca7inq"
{
  "composition_plan_hash": "...",
  "final_artifact_hash": "...",

  "input_artifacts": [
    {
      "shot_code": "SC01_SH01",
      "approved_shot_revision_id": "...",
      "artifact_hash": "..."
    }
  ],

  "technical_metadata": {
    "width": 1080,
    "height": 1920,
    "duration_ms": 45000,
    "fps": 30.0,
    "video_codec": "h264",
    "pixel_format": "yuv420p"
  }
}
```

Laravel kiểm tra response này với frozen plan.

---

# 19.32. Final Master Manifest

Khi QA pass, Laravel tạo:

```php id="6297vq"
<?php

namespace App\Video\FinalComposition\Approval;

final class FinalMasterManifest
{
    /**
     * @param list<array<string,mixed>> $shots
     */
    public function __construct(
        public readonly string $manifestVersion,

        public readonly string $videoSessionId,

        public readonly int $masterRevision,

        public readonly string $canonicalHash,
        public readonly string $identityLockHash,
        public readonly string $renderPlanHash,

        public readonly string $compositionPlanHash,

        public readonly array $shots,

        public readonly string $finalArtifactHash,

        public readonly array $technicalMetadata,
    ) {}

    public function toArray(): array
    {
        return [
            'manifest_version' =>
                $this->manifestVersion,

            'video_session_id' =>
                $this->videoSessionId,

            'master_revision' =>
                $this->masterRevision,

            'canonical_hash' =>
                $this->canonicalHash,

            'identity_lock_hash' =>
                $this->identityLockHash,

            'render_plan_hash' =>
                $this->renderPlanHash,

            'composition_plan_hash' =>
                $this->compositionPlanHash,

            'shots' =>
                $this->shots,

            'final_artifact_hash' =>
                $this->finalArtifactHash,

            'technical_metadata' =>
                $this->technicalMetadata,
        ];
    }
}
```

---

# 19.33. Manifest phải chứa exact shot lineage

Không chỉ:

```json id="q9ks7b"
{
  "shot_code": "SC03"
}
```

mà:

```json id="jp9qsc"
{
  "shot_code": "SC03",
  "approved_shot_revision_id": "...",
  "revision": 2,
  "render_request_hash": "...",
  "artifact_hash": "...",
  "qa_report_hash": "..."
}
```

Nhờ vậy bạn có thể audit ngược:

```text id="abkpxz"
Final MP4
↓
FinalMasterManifest
↓
FinalCompositionPlan
↓
ApprovedShotRevision
↓
ShotQAReport
↓
Video Artifact
↓
RenderRequest
↓
MotionSpec
↓
SceneStateGraph
↓
SceneProjection
↓
IdentityLock
↓
Canonical Revision
```

Đây là lineage end-to-end.

---

# 19.34. Exact-byte hash FinalMasterManifest

```php id="1aw7cc"
$manifestBytes =
    $serializer->serialize(
        $manifest->toArray()
    );

$masterManifestHash =
    hash(
        'sha256',
        $manifestBytes
    );
```

Rule giống toàn hệ thống:

```text id="byqnkr"
frozen manifest bytes
=
validated manifest bytes
=
hashed manifest bytes
```

---

# 19.35. `FinalMasterRevision`

Migration:

```php id="93z4ym"
Schema::create('final_master_revisions', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->uuid(
        'video_session_id'
    );

    $table->uuid(
        'final_composition_id'
    );

    $table->uuid(
        'final_render_execution_id'
    );

    $table->unsignedInteger(
        'revision'
    );

    $table->string(
        'canonical_hash',
        64
    );

    $table->string(
        'identity_lock_hash',
        64
    );

    $table->string(
        'render_plan_hash',
        64
    );

    $table->string(
        'composition_plan_hash',
        64
    );

    $table->string(
        'final_artifact_hash',
        64
    );

    $table->string(
        'master_manifest_hash',
        64
    )->unique();

    $table->longText(
        'master_manifest_json'
    );

    $table->string(
        'status',
        32
    );

    $table->unsignedBigInteger(
        'approved_by_admin_id'
    )->nullable();

    $table->timestamp(
        'approved_at'
    )->nullable();

    $table->timestamp(
        'frozen_at'
    )->nullable();

    $table->timestamps();

    $table->unique([
        'video_session_id',
        'revision',
    ]);
});
```

---

# 19.36. Final Master approval

Không auto-publish khi render xong.

States:

```text id="qhqwyo"
RENDERED
↓
QA_PASSED
↓
AWAITING_APPROVAL
↓
APPROVED
↓
FROZEN
```

Có thể cho project policy auto-approve sau technical QA, nhưng đó phải là explicit policy.

Không implicit.

---

# 19.37. Không overwrite Final Master

Giả sử:

```text id="fqwqto"
Master Revision 1
hash = ABC
```

Sau đó user đổi shot 5.

Không:

```text id="1x56ds"
UPDATE revision 1
```

Mà:

```text id="hl5y3u"
ApprovedShotRevision SC05 v3
↓
FinalCompositionPlan revision 2
↓
composition_plan_hash mới
↓
FinalRenderExecution mới
↓
FinalMasterRevision 2
```

Revision 1 vẫn audit được.

---

# 19.38. Final master không được tự đổi khi shot mới xuất hiện

Đây là lý do Part18 phải freeze `ApprovedShotRevision`.

Giả sử DB có:

```text id="1uzd4u"
SC03 render 1 → approved
SC03 render 2 → generated later
```

Final Master đã chọn:

```text id="wvsgzw"
ApprovedShotRevision SC03 v1
artifact_hash=A
```

render 2 xuất hiện:

```text id="apj45k"
artifact_hash=B
```

**không ảnh hưởng Final Master.**

Muốn dùng B phải:

```text id="symo48"
approve revision mới
↓
new composition revision
↓
new master revision
```

---

# 19.39. Final composition không sửa continuity

Part18 đã verify:

```text id="020snu"
shot-to-shot continuity
```

Part19 không được:

```text id="w2lpp5"
shot mismatch
→ pick another shot
```

Part19 chỉ kiểm:

```text id="ds2596"
expected approved revision actually assembled?
timeline đúng?
artifact đúng?
technical output đúng?
```

Nếu FinalCompositionPlan chứa shot không hợp lệ:

```text id="t90rb5"
FAIL
```

quay lại lifecycle thích hợp.

Không sửa ngầm.

---

# 19.40. Final QA categories

Part19 QA nên tách:

```text id="6yly1b"
INTEGRITY
├── composition plan hash
├── input artifact hashes
└── final artifact hash

LINEAGE
├── canonical hash
├── identity lock hash
├── render plan hash
└── approved shot revision IDs

TIMELINE
├── shot order
├── trim boundaries
├── timeline start/end
└── final duration

TECHNICAL
├── resolution
├── fps
├── codec
├── pixel format
├── audio
└── container

CONTENT
└── không cần re-judge semantic shot content
    vì Part18 đã approve từng shot
```

Đây là điểm quan trọng.

Không gọi Vision model lại cho toàn phim chỉ để hỏi:

> “Video có đẹp và nhất quán không?”

Nếu muốn thêm editorial final-review sau này, đó phải là QA stage riêng, không thay Part18 truth verification.

---

# 19.41. Final composition service orchestration

Laravel:

```php id="soowze"
final class FinalCompositionService
{
    public function __construct(
        private readonly FinalCompositionPlanBuilder $builder,
        private readonly FinalCompositionPlanValidator $validator,
        private readonly FinalCompositionFreezer $freezer,
        private readonly FinalRenderExecutionService $execution,
    ) {}

    public function create(
        VideoSession $session,
        array $approvedShots,
        array $approvedAudio,
        array $approvedSubtitles,
        FinalOutputSpec $output,
        int $revision,
    ): FrozenFinalComposition {
        $plan = $this->builder->build(
            $session,
            $approvedShots,
            $approvedAudio,
            $approvedSubtitles,
            $output,
            $revision,
        );

        $this->validator->validate(
            $plan
        );

        $frozen = $this->freezer->freeze(
            $plan
        );

        $this->execution->createFor(
            $frozen
        );

        return $frozen;
    }
}
```

---

# 19.42. Durable job

```php id="42e55o"
final class BuildFinalCompositionJob
    implements ShouldQueue
{
    public function __construct(
        public readonly string $videoSessionId,
    ) {}

    public function handle(
        FinalCompositionApplicationService $service,
    ): void {
        $service->buildForSession(
            $this->videoSessionId
        );
    }
}
```

Nhưng job này không nên rebuild cùng revision nhiều lần.

Application service phải idempotent theo:

```text id="hrmqnq"
video_session_id
+
composition_revision
```

---

# 19.43. Python worker contract

```python id="xiwijn"
@dataclass(frozen=True, slots=True)
class FinalCompositionWorkItem:
    execution_id: str
    claim_token: str

    composition_plan_hash: str

    exact_plan_bytes: bytes

    assets: tuple[
        FinalCompositionAsset,
        ...
    ]
```

Worker:

```python id="et1jfx"
class FinalCompositionWorker:
    def run(
        self,
        item: FinalCompositionWorkItem,
    ) -> FinalCompositionResult:
        verify_plan_hash(
            exact_plan_bytes=(
                item.exact_plan_bytes
            ),
            expected_hash=(
                item.composition_plan_hash
            ),
        )

        plan = self._parser.parse(
            item.exact_plan_bytes
        )

        validate_timeline(
            plan
        )

        resolved = (
            self._resolve_and_verify_assets(
                plan=plan,
                assets=item.assets,
            )
        )

        graph = self._graph_compiler.compile(
            plan=plan,
            assets=resolved,
        )

        output_path = (
            self._assembler.assemble(
                graph
            )
        )

        artifact_hash = sha256_file(
            output_path
        )

        metadata = self._probe.probe(
            output_path
        )

        qa = self._qa.verify(
            plan=plan,
            metadata=metadata,
        )

        if not qa.passed:
            raise FinalAssemblyError(
                "Final technical QA failed."
            )

        return FinalCompositionResult(
            execution_id=
                item.execution_id,

            composition_plan_hash=
                item.composition_plan_hash,

            final_artifact_hash=
                artifact_hash,

            artifact_path=
                str(output_path),

            technical_metadata=
                metadata,
        )
```

---

# 19.44. Python không freeze master

Python trả:

```text id="dkxj12"
assembly result
```

Laravel mới:

```text id="41hu6c"
verify callback
↓
persist artifact ledger
↓
QA state
↓
human/policy approval
↓
FinalMasterManifest
↓
hash
↓
freeze
```

Không đảo ownership.

---

# 19.45. Artifact directory

Phù hợp Artifact Ledger trước:

```text id="j9u3lq"
work/artifacts/
└── <session_code>/
    └── final/
        └── composition_<revision>/
            ├── final_composition_plan.json
            ├── ffmpeg_command.json
            ├── technical_metadata.json
            ├── final_assembly.mp4
            ├── final_qa.json
            └── final_master_manifest.json
```

`ffmpeg_command.json` chỉ audit execution.

Nó không phải semantic truth.

---

# 19.46. Artifact Ledger

Cần record tối thiểu:

```text id="cqpcdc"
artifact_type = final_master_video

composition_plan_hash
final_artifact_hash
master_manifest_hash

input artifact hashes
ffmpeg version
execution ID
attempt
started_at
completed_at
file size
duration
resolution
fps
codec
```

Nhờ vậy cùng một composition plan nếu FFmpeg/version khác tạo bytes khác nhau vẫn audit được.

---

# 19.47. Reproducibility

Cần phân biệt:

```text id="fzc8lv"
semantic reproducibility
```

và:

```text id="31gxm9"
byte-identical reproducibility
```

`FinalCompositionPlan` đảm bảo semantic composition cố định.

Nhưng FFmpeg khác version/encoder có thể sinh file MP4 khác byte.

Do đó manifest phải lưu:

```text id="w9w2bt"
ffmpeg_version
encoder
command/compiler_version
```

Không tuyên bố final artifact reproducible byte-for-byte nếu runtime chưa được container/version-lock.

---

# 19.48. Compiler version

Ví dụ:

```python id="dk7qta"
FINAL_COMPOSITION_COMPILER_VERSION = (
    "final-composition-v1"
)
```

Và manifest:

```json id="a99x7d"
{
  "compiler_version": "final-composition-v1",
  "ffmpeg_version": "..."
}
```

Nếu compiler logic đổi:

```text id="xxkyrz"
v1 → v2
```

phải trace được.

---

# 19.49. One production path

Part19 cũng chỉ có **một** đường:

```text id="6m326k"
ApprovedShotRevision[]
↓
FinalCompositionPlanBuilder
↓
FinalCompositionPlan
↓
Validation
↓
Freeze
↓
FinalRenderExecution
↓
Python FinalCompositionWorker
↓
FFmpeg
↓
Final Artifact
↓
Final QA
↓
FinalMasterRevision
↓
Freeze
```

Không có:

```text id="zbgob8"
FinalComposerA
FinalComposerB
```

Không có:

```text id="doyczi"
MoviePy production path
FFmpeg production path
```

Nếu cần thử MoviePy thì experimental thôi.

Production chỉ một compiler/executor.

---

# 19.50. Part 1 → Part 19 sau khi nối

Toàn bộ chain giờ trở thành:

```text id="m4mrmq"
RawArticle
↓
ArticleNormalizer
↓
EvidenceIndex
↓
ClaudeExtractor / Haiku
↓
CandidateWorldGraph
↓
EvidenceGatekeeper
↓
VerifiedWorldGraph
↓
ClaudeInspirationAnalyst
    + CategoryCreativeProfile
↓
Draft InspirationBrief
↓
InspirationBriefParser
↓
InspirationBriefValidator
↓
InspirationBuilder
    + VerifiedWorldGraph
    + CategoryCreativeProfile
↓
Final InspirationBrief
↓
ConceptInput
↓
CanonicalStructuredConceptDesigner
↓
Structured Concept Candidate
↓
Core Schema + Effective Profile Schema
↓
JSON Schema Validation
↓
CanonicalDesignSpec DTO
↓
Semantic Validation
↓
Cross-field Validation
↓
Provenance Validation
↓
1 Semantic Repair nếu fail
↓
Full Re-validation
↓
Normalization
↓
SchemaAwareCanonicalSerializer
↓
Exact Canonical JSON Bytes
↓
Re-validation exact bytes
↓
canonical_design_spec_v1.json
↓
SHA-256 exact bytes
↓
Freeze Canonical Revision

══════════════════════════════════

AssetProjection
↓
ConstraintSet
↓
ConstraintCoalescer
↓
PromptSpec
↓
ProviderCapabilityProjection
↓
PromptCompiler
↓
CompiledPrompt
↓
RenderRequest
↓
Durable RenderExecution
↓
Image Provider
↓
Artifact
↓
Vision QA
↓
Targeted Repair ≤ 2
↓
Approved Anchor
↓
Reference Pack
↓
Multi-view QA
↓
IdentityLockManifest
↓
Freeze Identity Lock

══════════════════════════════════

Frozen RenderPlan
↓
SceneProjection
↓
SceneStateGraph
↓
ReferenceSelection
↓
Scene Image
↓
Scene Image QA
↓
Video Keyframe
↓
MotionSpec
↓
Video RenderRequest
↓
Durable RenderExecution
↓
Veo / Kling
↓
Video Artifact
↓
Shot QA
↓
Continuity Verification
↓
Targeted Shot Repair ≤ 2
↓
Shot Approval
↓
ApprovedShotRevision
↓
Freeze Shot Revision

══════════════════════════════════

ApprovedShotRevision[]
↓
FinalCompositionPlanBuilder
↓
FinalCompositionPlan
↓
Composition Validation
↓
Schema-aware Serialization
↓
Exact Composition JSON Bytes
↓
Re-validation exact bytes
↓
SHA-256 composition_plan_hash
↓
Freeze FinalCompositionPlan
↓
FinalRenderExecution
↓
Python FinalCompositionWorker
↓
Input Artifact Hash Verification
↓
FFmpeg Deterministic Assembly
↓
Final Video Artifact
↓
SHA-256 final_artifact_hash
↓
Technical Final QA
↓
Lineage Verification
↓
FinalMasterManifest
↓
SHA-256 master_manifest_hash
↓
Final Approval
↓
FinalMasterRevision
↓
FREEZE
```

## Chốt Part 19

Part 19 kết thúc ở:

```text id="tqh0ez"
FinalMasterRevision
↓
immutable freeze
```

Từ thời điểm đó, hệ thống có một **final master cụ thể, audit được ngược toàn bộ về Canonical Revision**.

Điểm quan trọng nhất Part19 là:

```text id="bt3u4g"
Final video
≠ "ghép những clip mới nhất"

Final video
=
Frozen FinalCompositionPlan
+
exact ApprovedShotRevision IDs
+
exact artifact hashes
+
deterministic assembly
+
final QA
+
immutable FinalMasterRevision
```

Như vậy retry, render mới, repair mới hoặc shot revision mới **không bao giờ âm thầm thay đổi video cuối đã freeze**. Đây là boundary cần thiết trước khi sang **Part 20 — Publication Package + Distribution/Publishing + Publication Ledger + Metrics lineage**.
