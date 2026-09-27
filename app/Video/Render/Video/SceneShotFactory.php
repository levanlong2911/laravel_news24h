<?php

namespace App\Video\Render\Video;

use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Video\Scene\Services\ShotIntentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shot cua mot scene duoc sinh LUOI — dung luc nguoi dung bam Render video, khong
 * phai luc lap ke hoach.
 *
 * Ly do: mot shot ton tai la de mang mot lan render. Sinh san hang loat shot ma
 * khong ai bam thi chung chi lam ban bang va lam sai moi phep dem "dang dung".
 *
 * Moi hang VideoRenderScene da la mot shot production. Cung shot code duoc phep
 * xuat hien o revision ke hoach khac, nhung khong duoc lap trong mot revision.
 */
final class SceneShotFactory
{
    public const KIND = 'motion';

    public function __construct(private readonly ShotIntentService $intents) {}

    /** @param array<string, mixed> $plan hang scene trong ke hoach dang hien */
    public function forScene(VideoRenderScene $scene, array $plan): VideoShot
    {
        $prompt = trim((string) ($plan['video_prompt'] ?? ''));

        if ($prompt === '') {
            throw new RuntimeException('Scene chua co prompt clip — sinh o man hinh Scenes truoc.');
        }

        return DB::transaction(function () use ($scene, $plan, $prompt): VideoShot {
            // The session table has no project-level unique key. Serialize the
            // first session and shot creation so concurrent clicks cannot create
            // two independent owners for the same production shot.
            VideoProject::query()->whereKey($scene->project_id)->lockForUpdate()->firstOrFail();
            $session = $this->sessionFor((string) $scene->project_id);
            $code = (string) ($scene->scene_code ?? $scene->id);

            $shot = VideoShot::query()
                ->where('session_id', $session->id)
                ->where('plan_revision', (int) $scene->revision)
                ->where('shot_code', $code)
                ->where('kind', self::KIND)
                ->lockForUpdate()
                ->first();

            if ($shot !== null) {
                return $this->intents->replaceInputs($shot, [
                    'compiled_prompt' => $prompt,
                    'scene_id' => $scene->id,
                    'plan_revision' => (int) $scene->revision,
                    'shot_index' => (int) ($scene->shot_index ?? 1),
                    'scene_sequence_index' => (int) $scene->scene_index,
                    'spec_json' => $this->spec($plan),
                    'to_state_id' => $plan['scene_state'] ?? null,
                    'from_state_id' => $plan['state_before'] ?? null,
                ]);
            }

            return VideoShot::create([
                'session_id' => $session->id,
                'scene_id' => $scene->id,
                'beat' => mb_substr($code, 0, 40),
                'shot_code' => $code,
                'shot_type' => mb_substr((string) ($plan['phase'] ?? 'scene'), 0, 20),
                'kind' => self::KIND,
                'plan_revision' => (int) $scene->revision,
                'shot_index' => (int) ($scene->shot_index ?? 1),
                'spec_json' => $this->spec($plan),
                'compiled_prompt' => $prompt,
                'scene_status' => 'keyframe_ready',
                'scene_sequence_index' => $scene->scene_index,
                'to_state_id' => $plan['scene_state'] ?? null,
                'from_state_id' => $plan['state_before'] ?? null,
            ]);
        }, attempts: 3);
    }

    /**
     * Du an chua tung co session nao van phai render duoc clip. Session o day chi la
     * cai mang shot, khong phai mot luot chay pipeline.
     */
    private function sessionFor(string $projectId): VideoSession
    {
        $session = VideoSession::query()
            ->where('project_id', $projectId)
            ->orderBy('created_at')
            ->first();

        return $session ?? VideoSession::create([
            'project_id' => $projectId,
            'code' => 'clips-'.substr($projectId, 0, 8).'-'.substr(bin2hex(random_bytes(4)), 0, 8),
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function spec(array $plan): array
    {
        return array_filter([
            'title' => $plan['title'] ?? null,
            'visual_goal' => $plan['purpose'] ?? null,
            'motion' => $plan['motion'] ?? null,
            'camera' => $plan['camera'] ?? null,
            'duration_seconds' => $plan['duration_seconds'] ?? null,
            'scene_state' => $plan['scene_state'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }
}
