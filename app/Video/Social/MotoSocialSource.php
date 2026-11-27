<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoReviewDecision;
use App\Services\Video\DesignImageStore;
use App\Services\Video\MotoFilmApprovalService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Moto\MotoLocations;
use App\Video\Moto\MotoScript;
use RuntimeException;

/**
 * What a package of a moto project is written from: the approved film, and from that film alone — the plan it was cut
 * from, the script that plan pins, the design that script pins. The script and the design the project selects today are
 * never read: a film approved last week is still about the script it was shot from.
 *
 * A film of an older release is read the same way. What such a film does not pin is listed as missing and left out;
 * what it pins and can no longer be read as pinned stops the reading, by name.
 */
final class MotoSocialSource implements SocialSource
{
    /** @var list<string> the parts of a design a writer reads; coordinates and the model sheet stay out */
    private const DESIGN_KEYS = ['motorcycle', 'parts', 'features', 'systems', 'build_states', 'identity_rules'];

    /** @var list<string> the fields of a beat a writer reads */
    private const BEAT_KEYS = ['id', 'act', 'state', 'purpose', 'visual', 'situation', 'reveal', 'location', 'time_light', 'events'];

    public function __construct(
        private readonly MotoFilmApprovalService $approvals,
        private readonly ScriptSourceService $scripts,
    ) {}

    public function read(string $projectId): array
    {
        $approved = $this->approvals->approvedFinal($projectId);
        $final = $approved['final'];
        $film = (array) ($final->plan_json['moto_film'] ?? []);
        $plan = is_array($film['film_plan'] ?? null) ? $film['film_plan'] : throw new RuntimeException('Bản final đã duyệt không lưu kế hoạch phim nó được dựng từ — không biết phim gồm những shot nào.');
        $pin = is_array($plan['source']['script'] ?? null) ? $plan['source']['script'] : throw new RuntimeException('Kế hoạch phim của bản final đã duyệt không ghim kịch bản — không biết nội dung phim lấy từ bản nào.');
        $script = $this->scripts->scriptStage($projectId, (string) ($pin['stage_id'] ?? ''));
        [$evaluation, $problem] = $script === null ? [[], 'Bản kịch bản mà phim ghim không còn hoặc không thuộc dự án.'] : $this->scripts->inspect($projectId, $script);

        if ($problem !== null || ! hash_equals((string) ($pin['content_hash'] ?? ''), (string) ($evaluation['content_hash'] ?? ''))) {
            throw new RuntimeException('Không đọc được kịch bản của phim đúng như phim đã ghim: '.($problem ?? 'nội dung bản kịch bản không còn khớp hash phim ghim.'));
        }

        $story = (array) ($evaluation['document']['data'] ?? []);
        $missing = [];
        [$design, $designPin] = $this->design($projectId, (array) (PlanningStageStore::metadataOf(is_array($script->input_json) ? $script->input_json : null)['source'] ?? []), $missing);
        $clips = array_values((array) ($plan['clips'] ?? []));
        $assets = [...self::frames($clips), ...$this->keyframes($projectId, $clips), ...$this->designImages($projectId, $designPin)];

        if ($design === null) {
            $missing[] = 'Thiết kế: kịch bản của phim không ghim bản thiết kế nào — gói không có dữ liệu thiết kế để dẫn nguồn.';
        }

        return [
            'pins' => [
                'final_id' => (string) $final->id,
                'artifact_sha256' => $approved['sha256'],
                'decision_id' => (string) $approved['decision']->id,
                'manifest_hash' => (string) $final->manifest_hash,
                'film_plan_hash' => (string) ($film['film_plan_hash'] ?? ''),
                'film_plan_stage_id' => (string) ($film['film_plan_stage_id'] ?? ''),
                'clips_hash' => (string) ($plan['source']['clips']['hash'] ?? ''),
                'shots' => ['planning_stage_id' => (string) ($plan['source']['shots']['planning_stage_id'] ?? ''), 'revision' => (int) ($plan['source']['shots']['revision'] ?? 0)],
                'script' => ['stage_id' => (string) $script->id, 'content_hash' => (string) $evaluation['content_hash']],
                'scenes_stage_id' => (string) ($pin['scenes_stage_id'] ?? ''),
                'design' => $designPin,
            ],
            'film' => [
                'final_id' => (string) $final->id,
                'revision' => (int) $final->revision,
                'path' => $approved['path'],
                'sha256' => $approved['sha256'],
                'decision_id' => (string) $approved['decision']->id,
                'approved_at' => $approved['decision']->created_at?->toIso8601String(),
                'approved_by' => $approved['decision']->reviewer_id === null ? null : (string) $approved['decision']->reviewer_id,
                'purpose' => is_string($film['purpose'] ?? null) ? $film['purpose'] : null,
                'shots' => count($clips),
            ],
            'facts' => [
                'script' => self::story($story),
                'timeline' => array_map(static fn (array $clip): array => [
                    'ref' => 'film.shot:'.$clip['shot_id'],
                    'shot_id' => (string) $clip['shot_id'],
                    'scene_id' => (string) ($clip['scene_id'] ?? ''),
                    'start_ms' => (int) ($clip['timeline_start_ms'] ?? 0),
                    'end_ms' => (int) ($clip['timeline_end_ms'] ?? 0),
                ], $clips),
                'cards' => array_values(array_map(static fn (array $card, int $index): array => [
                    'ref' => 'film.card:'.($index + 1),
                    'type' => (string) ($card['type'] ?? ''),
                    'text' => (string) ($card['text'] ?? implode(' ', array_column((array) ($card['lines'] ?? []), 'text'))),
                    'start_ms' => (int) ($card['start_ms'] ?? 0),
                    'end_ms' => (int) ($card['end_ms'] ?? 0),
                ], array_values((array) ($plan['cards'] ?? [])), array_keys(array_values((array) ($plan['cards'] ?? []))))),
                'design' => $design,
            ],
            'refs' => self::refs($story, $clips, array_values((array) ($plan['cards'] ?? [])), $design),
            'assets' => $assets,
            'missing' => $missing,
        ];
    }

    public function selected(string $projectId, array $pins): ?string
    {
        // The selection lives on the row of the project; a share lock makes an approval of another film wait for this transaction, and reads the one committed before it.
        $project = VideoProject::query()->whereKey($projectId)->sharedLock()->first(['id', 'metadata_json']);
        $selection = $project?->metadata_json[MotoFilmApprovalService::SELECTION_KEY] ?? null;

        if (! is_array($selection)) {
            return 'Dự án không còn bản final được duyệt.';
        }

        foreach (['final_id', 'decision_id', 'artifact_sha256'] as $key) {
            if (! hash_equals((string) ($pins[$key] ?? ''), (string) ($selection[$key] ?? ''))) {
                return 'Bản final được duyệt của dự án đã đổi sang bản khác.';
            }
        }

        $approves = VideoReviewDecision::query()->whereKey($selection['decision_id'])->where('entity_type', MotoFilmApprovalService::ENTITY_TYPE)->where('entity_id', $selection['final_id'])->where('decision', 'approved')->exists();

        return $approves ? null : 'Quyết định duyệt của bản final không còn.';
    }

    /**
     * @param  array<string, mixed>  $source  the source the script run pinned
     * @param  list<string>  $missing
     * @return array{0: ?array<string, mixed>, 1: ?array{stage_id: string, raw_sha256: string}} the design the script was written from, read as pinned, with its pin; both null for a script that pins none
     *
     * @throws RuntimeException when the script pins a design that is gone or no longer has the pinned hash
     */
    private function design(string $projectId, array $source, array &$missing): array
    {
        if (! is_string($source['design_stage_id'] ?? null) || $source['design_stage_id'] === '') {
            return [null, null];
        }

        try {
            $data = MotoLocations::pinnedDesign($projectId, $source);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Không đọc được thiết kế của phim đúng như kịch bản đã ghim: '.$e->getMessage());
        }

        return [
            array_intersect_key(MotoScript::select($data), array_flip(self::DESIGN_KEYS)),
            ['stage_id' => (string) $source['design_stage_id'], 'raw_sha256' => (string) $source['design_raw_sha256']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  the data of the script
     * @return array<string, mixed> what the script says the film is about: every field of its own that is a plain text, and its beats, cards and characters
     */
    private static function story(array $data): array
    {
        $story = array_filter($data, static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        foreach (['theme', 'client', 'motorcycle', 'premise', 'story'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $story[$key] = $data[$key];
            }
        }

        return $story + [
            'beats' => array_values(array_map(static fn (array $beat): array => ['ref' => 'script.beat:'.($beat['id'] ?? '')] + array_intersect_key($beat, array_flip(self::BEAT_KEYS)), array_filter((array) ($data['beats'] ?? []), 'is_array'))),
            'characters' => (array) ($data['characters'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  list<array<string, mixed>>  $clips
     * @param  list<array<string, mixed>>  $cards
     * @param  ?array<string, mixed>  $design
     * @return list<array{ref: string, what: string}> every source a claim may cite
     */
    private static function refs(array $story, array $clips, array $cards, ?array $design): array
    {
        $refs = [
            ['ref' => 'film', 'what' => 'The approved film, by what was measured on its file.'],
            ['ref' => 'script', 'what' => 'The script the film was shot from, as a whole.'],
        ];

        foreach ((array) ($story['beats'] ?? []) as $beat) {
            if (is_array($beat) && is_string($beat['id'] ?? null)) {
                $refs[] = ['ref' => 'script.beat:'.$beat['id'], 'what' => 'Beat '.$beat['id'].' of the script.'];
            }
        }

        foreach ($clips as $clip) {
            $refs[] = ['ref' => 'film.shot:'.$clip['shot_id'], 'what' => 'Shot '.$clip['shot_id'].' as it sits in the film.'];
        }

        foreach (array_keys($cards) as $index) {
            $refs[] = ['ref' => 'film.card:'.($index + 1), 'what' => 'Text card '.($index + 1).' of the film.'];
        }

        if ($design === null) {
            return $refs;
        }

        $refs[] = ['ref' => 'design', 'what' => 'The design the script was written from, as a whole.'];
        $refs[] = ['ref' => 'design.motorcycle', 'what' => 'The motorcycle as a whole: silhouette, palette, materials, markings.'];

        foreach ([['parts', 'design.part:', 'Part '], ['features', 'design.feature:', 'Feature '], ['systems', 'design.system:', 'System '], ['build_states', 'design.state:', 'Build state ']] as [$key, $prefix, $name]) {
            foreach ((array) ($design[$key] ?? []) as $record) {
                if (is_array($record) && (is_string($record['id'] ?? null) || is_int($record['id'] ?? null))) {
                    $refs[] = ['ref' => $prefix.$record['id'], 'what' => $name.$record['id'].(is_string($record['name'] ?? null) ? ' — '.$record['name'] : '').'.'];
                }
            }
        }

        return $refs;
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     * @return list<array<string, mixed>> one frame of the film for every shot, at the middle of the shot; a person may take a frame at any other time
     */
    private static function frames(array $clips): array
    {
        return array_map(static function (array $clip): array {
            $at = intdiv((int) ($clip['timeline_start_ms'] ?? 0) + (int) ($clip['timeline_end_ms'] ?? 0), 2);

            return [
                'id' => 'frame:'.$clip['shot_id'],
                'kind' => 'film_frame',
                'at_ms' => $at,
                'shot_id' => (string) $clip['shot_id'],
                'scene_id' => (string) ($clip['scene_id'] ?? ''),
                'what' => 'A frame of the approved film at '.number_format($at / 1000, 3, '.', '').' s, the middle of shot '.$clip['shot_id'].'.',
            ];
        }, $clips);
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     * @return list<array<string, mixed>> the approved keyframe each clip of the film was made from, where a clip had one and its file is still the one the clip pinned; a shot with none is simply not listed
     */
    private function keyframes(string $projectId, array $clips): array
    {
        $shots = array_column($clips, 'shot_id', 'render_id');
        $pinned = [];

        foreach (VideoRender::query()->whereIn('id', array_keys($shots))->get(['id', 'render_request_json']) as $render) {
            $keyframe = json_decode((string) $render->render_request_json, true)['moto_clip']['keyframe'] ?? null;

            if (is_array($keyframe) && is_string($keyframe['artifact_id'] ?? null) && is_string($keyframe['sha256'] ?? null)) {
                $pinned[$keyframe['artifact_id']] = ['shot_id' => (string) $shots[(string) $render->id], 'sha256' => $keyframe['sha256']];
            }
        }

        $assets = [];

        foreach ($pinned === [] ? [] : VideoArtifact::query()->whereIn('id', array_keys($pinned))->where('project_id', $projectId)->where('artifact_type', 'image')->get() as $artifact) {
            $pin = $pinned[(string) $artifact->id];

            if (hash_equals($pin['sha256'], (string) $artifact->sha256)) {
                $assets[$pin['shot_id']] = self::image('keyframe:'.$pin['shot_id'], 'keyframe', $artifact, 'The approved keyframe shot '.$pin['shot_id'].' of the film was made from.') + ['shot_id' => $pin['shot_id']];
            }
        }

        return array_values(array_filter(array_map(static fn (array $clip): ?array => $assets[(string) $clip['shot_id']] ?? null, $clips)));
    }

    /**
     * @param  ?array{stage_id: string, raw_sha256: string}  $design
     * @return list<array<string, mixed>> the approved master image of the design the film pins, when there is one
     */
    private function designImages(string $projectId, ?array $design): array
    {
        if ($design === null) {
            return [];
        }

        $image = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->where('prompt_spec_json->design_stage_id', $design['stage_id'])
            ->whereNotNull('selected_artifact_id')
            ->orderByDesc('approved_at')
            ->first(['id', 'selected_artifact_id']);
        $artifact = $image === null ? null : VideoArtifact::query()->whereKey($image->selected_artifact_id)->where('project_id', $projectId)->where('artifact_type', 'image')->first();

        return $artifact === null ? [] : [self::image('design:master', 'design_image', $artifact, 'The approved master sheet of the design the film was made from.')];
    }

    /** @return array<string, mixed> */
    private static function image(string $id, string $kind, VideoArtifact $artifact, string $what): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'artifact_id' => (string) $artifact->id,
            'sha256' => (string) $artifact->sha256,
            'width' => $artifact->width === null ? null : (int) $artifact->width,
            'height' => $artifact->height === null ? null : (int) $artifact->height,
            'what' => $what,
        ];
    }
}
