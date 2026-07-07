# Phase A.5 — First Real Video

**Status:** READY TO START  
**Priority:** P0 — blocks all other phases (proves the pipeline works end-to-end)  
**Estimate:** 3–4 days  
**Depends on:** Phase A (DONE)

---

## Context

`GraphAssembler` already calls:
```
ShotGoalIRAdapter → AfosPassManager::compile() → KlingBackend::serialize()
```
The pipeline returns a **prompt string**. The only missing piece is sending that string to the Kling API and storing the video URL.

Phase A.5 does not touch FilmOS. It does not add business logic. It ships the first video.

---

## Dependency graph

```
A5-001 (KlingApiClient)
    ↓
A5-002 (KlingVideoRequest + KlingVideoResponse)   [parallel with A5-001]
    ↓
A5-003 (RenderShotVideoJob)
    ↓
A5-004 (PollKlingVideoJob)
    ↓
A5-005 (wire into controller)
    ↓
A5-006 (manual end-to-end test)
```

---

## Tasks

### A5-001 — Kling API client

**Estimate:** 1 day  
**Depends on:** none

**Files to create:**
```
app/Services/AI/Providers/Kling/KlingApiClient.php
app/Services/AI/Providers/Kling/Exceptions/KlingApiException.php
app/Services/AI/Providers/Kling/Exceptions/KlingTimeoutException.php
config/kling.php
```

**KlingApiClient contract:**
```php
final class KlingApiClient
{
    public function __construct(private readonly string $apiKey, private readonly string $baseUrl) {}

    public function submitVideo(KlingVideoRequest $request): string;
    // Returns taskId. Throws KlingApiException on HTTP error.

    public function pollStatus(string $taskId): KlingVideoResponse;
    // Returns current status. Throws KlingApiException on HTTP error.

    public function waitForCompletion(string $taskId, int $timeoutSec = 300): KlingVideoResponse;
    // Polls every 15s. Throws KlingTimeoutException if timeoutSec exceeded.
}
```

**config/kling.php:**
```php
return [
    'api_key'  => env('KLING_API_KEY'),
    'base_url' => env('KLING_BASE_URL', 'https://api.klingai.com'),
    'timeout'  => env('KLING_TIMEOUT_SEC', 300),
    'mode'     => env('KLING_MODE', 'std'),  // std | pro
];
```

**Done:** `KlingApiClient` can be instantiated. Methods are defined. Exception hierarchy exists.

---

### A5-002 — Request and Response DTOs

**Estimate:** 0.5 day  
**Depends on:** none (parallel with A5-001)

**Files to create:**
```
app/Services/AI/Providers/Kling/KlingVideoRequest.php
app/Services/AI/Providers/Kling/KlingVideoResponse.php
app/Services/AI/Providers/Kling/KlingVideoStatus.php   (enum: PENDING, PROCESSING, COMPLETED, FAILED)
```

**KlingVideoRequest fields:**
```php
final readonly class KlingVideoRequest
{
    public function __construct(
        public readonly string  $prompt,
        public readonly string  $negativePrompt = '',
        public readonly float   $durationSec    = 5.0,
        public readonly string  $aspectRatio    = '16:9',
        public readonly string  $mode           = 'std',    // std | pro
        public readonly ?string $referenceImageUrl = null,
    ) {}
}
```

**KlingVideoResponse fields:** `taskId`, `status` (KlingVideoStatus enum), `videoUrl`, `thumbnailUrl`, `errorMessage`, `durationSec`, `costCredits`

**Done:** Both DTOs exist. Round-trip `fromArray()` / `toArray()` tested.

---

### A5-003 — Render shot video job

**Estimate:** 0.5 day  
**Depends on:** A5-001, A5-002

**Files to create:**
```
app/Jobs/RenderShotVideoJob.php
```

**Logic:**
1. Accept `shotId`, `prompt`, `negativePrompt`, `durationSec`, `pipelineRunId`
2. Build `KlingVideoRequest`
3. Call `KlingApiClient::submitVideo()` → get `taskId`
4. Update `pipeline_runs.kling_task_id = $taskId`, `kling_status = PENDING`
5. Dispatch `PollKlingVideoJob::dispatch($taskId, $shotId)->delay(now()->addSeconds(30))`

**Migration:**
```
database/migrations/xxxx_add_kling_fields_to_pipeline_runs.php
Adds: kling_task_id (string, nullable), kling_status (string, default PENDING),
      video_url (text, nullable), thumbnail_url (text, nullable), render_error (text, nullable)
```

**Done:** Dispatching the job stores a `kling_task_id` in the DB.

---

### A5-004 — Poll Kling video job

**Estimate:** 0.5 day  
**Depends on:** A5-001, A5-002, A5-003

**Files to create:**
```
app/Jobs/PollKlingVideoJob.php
```

**Logic:**
1. Accept `taskId`, `shotId`, `attemptNumber` (default 0)
2. Call `KlingApiClient::pollStatus($taskId)`
3. `COMPLETED` → store `video_url`, update `kling_status = COMPLETED`, fire `VideoRendered` event
4. `FAILED` → store `render_error`, update `kling_status = FAILED`, fire `RenderFailed` event
5. `PENDING | PROCESSING` → if `$attemptNumber < 20`: re-dispatch with 15s delay, increment attempt. Else: mark TIMEOUT.

**Retry limit:** 20 × 15s = 5 minutes max poll time.

**Done:** After ~2 minutes, `pipeline_runs.video_url` is populated for a completed shot.

---

### A5-005 — Wire into existing controller or command

**Estimate:** 0.5 day  
**Depends on:** A5-003, A5-004

Find where `GraphAssembler::assemble()` is called. After it returns the shot graph:

```php
foreach ($shots as $shot) {
    RenderShotVideoJob::dispatch(
        shotId:         $shot['id'],
        prompt:         $shot['prompt'],
        negativePrompt: $shot['negativePrompt'] ?? '',
        durationSec:    (float) ($shot['duration'] ?? 5.0),
        pipelineRunId:  $pipelineRunId,
    );
}
```

**Done:** Triggering the pipeline via existing UI/command dispatches `RenderShotVideoJob` for each shot.

---

### A5-006 — End-to-end test (manual)

**Estimate:** 0.5 day  
**Depends on:** A5-005

**Checklist:**
- [ ] Submit one article via existing UI
- [ ] `pipeline_runs` shows `kling_task_id` within 10 seconds
- [ ] After 2–3 minutes, `video_url` is populated
- [ ] Video is playable in browser
- [ ] If Kling returns an error, `render_error` is stored (not a silent failure)

**Done Definition for Phase A.5:**
- [ ] One real article produces one real video
- [ ] Video URL is stored in DB
- [ ] Failure case is handled (stored, not lost)
- [ ] No changes to AFOS compiler (AFOS is frozen)

---

## Files created by Phase A.5

```
app/Services/AI/Providers/Kling/
├── KlingApiClient.php
├── KlingVideoRequest.php
├── KlingVideoResponse.php
├── KlingVideoStatus.php          (enum)
└── Exceptions/
    ├── KlingApiException.php
    └── KlingTimeoutException.php
app/Jobs/
├── RenderShotVideoJob.php
└── PollKlingVideoJob.php
config/kling.php
database/migrations/xxxx_add_kling_fields_to_pipeline_runs.php
```

**Not created in Phase A.5 (Phase B+):**
- FilmOS namespace (ProductionBible, WorldModule, CharacterModule...)
- CapabilitySpec / CapabilityResolver
- Event Bus (ProductionEventBus)
- Any new stage in AFOS
