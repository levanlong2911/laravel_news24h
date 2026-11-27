<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoKeyframePrompt;
use App\Video\Moto\MotoPipelineRelease;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a clip of the shot flow comes from: the approved shot plan and the keyframe approved for each shot on the Keyframes screen.
 * A keyframe is read the way that screen reads it, through the render row of its shot, and one that fails a check is reported and never replaced by a similar one.
 *
 * The contract the Keyframes screen writes to: a video_design_images row of the project with image_type scene_keyframe, render_scene_id the row ShotRenderPlanService keeps for the shot in the approved plan, status approved with approved_at and selected_artifact_id set through DesignImageStore::approve, and prompt_spec_json {shot_id, shot_stage_id, source_token, prompt, image_sha256, composer, prompt_source, sources} as MotoKeyframeService::render stores them, its prompt the one approved for the shot in Phase 9. Its selected video_artifacts row carries design_image_id, a render_id, a disk of video.veo.source_disks and the sha256 of a PNG or JPEG in the frame of the film. Every check MotoKeyframeSourceService::keyframeProblem makes holds too.
 */
final class MotoClipSourceService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, how a person asked the clip of the shot to be made and the reference images they left out */
    public const INPUTS = 'moto_clip_inputs';

    public const KEYFRAME = 'keyframe';

    public const REFERENCES = 'references';

    /** @var list<string> the kinds of image of the Keyframes screen a clip made from reference images sends; the keyframe of another shot is no reference */
    private const REFERENCE_KINDS = ['environment', 'identity', 'design', 'character'];

    /** @var list<string> what a person may change in the settings of a shot under an execution policy */
    public const SETTING_FIELDS = ['motion_method', 'input_mode', 'model', 'resolution', 'images', 'crop_plan', 'audio_policy'];

    /** @var int the code of the refusal when the settings were saved elsewhere since the page read them */
    public const CONFLICT = 409;

    public function __construct(
        private readonly ShotSourceService $shots,
        private readonly FilesystemFactory $storage,
        private readonly MotoKeyframeSourceService $frames,
        private readonly VideoModelRegistry $registry,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoArtifactRepositoryInterface $artifacts,
        private readonly VideoDesignImageRepositoryInterface $images,
    ) {}

    /**
     * @return ?array<string, mixed> the execution policy the release of the project declares (policy.clip_execution), as MotoClipPlanBuilder::executionPolicy reads it; null for a release that declares none, whose clips are made as before
     *
     * @throws RuntimeException when the release declares a policy this code cannot read
     */
    public function execution(string $projectId): ?array
    {
        $project = $this->projects->findProject($projectId);
        $declared = $project === null || ! MotoPipelineRelease::cinematic($project) ? [] : MotoPipelineRelease::policy($project, 'clip_execution');

        return $declared === [] ? null : MotoClipPlanBuilder::executionPolicy($declared, (string) config('video.provider_evidence_dir'));
    }

    /**
     * @param  array<string, mixed>  $execution  execution()
     * @return list<array<string, mixed>> the models of the registry the policy offers, as the registry describes them; none when the registry cannot be read, so every choice of a model is blocked rather than sent
     */
    public function executionModels(array $execution): array
    {
        $offered = [];

        foreach ($execution['offers'] as $byInput) {
            foreach ($byInput as $offers) {
                array_push($offered, ...array_values(array_filter(array_column($offers, 'model'), 'is_string')));
            }
        }

        try {
            return array_values(array_filter($this->registry->forTask(SceneClipDispatchService::TASK), static fn (array $entry): bool => in_array($entry['id'], $offered, true)));
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hỏng: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array{workflow: string, action: string, settings: array<string, mixed>} the clip flow of the project's workflow
     *
     * @throws RuntimeException when the project does not render clips by shot
     */
    public function workflowFor(string $projectId): array
    {
        $project = $this->projects->findProjectWithCategory($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? '');
        $entry = $workflow === '' ? null : (config('video.clips.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException('Dự án này không render clip theo shot.');
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'settings' => $entry];
    }

    public function supports(string $projectId): bool
    {
        try {
            $this->workflowFor($projectId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{stage_id: string, revision: int, token: array<string, mixed>, plan: array<string, mixed>, context: array<string, mixed>} the approved shot plan with the check context pinned with its run
     *
     * @throws RuntimeException when no shot plan is approved or the approved one is no longer valid
     */
    public function source(string $projectId): array
    {
        $approved = $this->shots->approvedSource($projectId);
        $stage = $this->shots->shotStage($projectId, $approved['stage_id']) ?? throw new RuntimeException('Kế hoạch shot đã duyệt không còn hoặc không thuộc dự án.');

        return [
            'stage_id' => $approved['stage_id'],
            'revision' => (int) $stage->planning_revision,
            'token' => $approved['token'],
            'plan' => $approved['plan'],
            'context' => (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['context'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings  the settings of the clip flow
     * @param  ?array<string, mixed>  $context  frameContext(), read again when not given
     * @return array<string, array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}> for every shot of the plan by id, its keyframe to pin or why it has none it can use
     */
    public function keyframes(string $projectId, array $source, array $settings, ?array $context = null): array
    {
        $context ??= $this->frames->context($projectId);
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $approved = array_values(array_filter((array) ($context['images'][$shotId] ?? []), static fn (VideoDesignImage $image): bool => $image->status === DesignImageStatus::APPROVED->value));
            $result[$shotId] = match (true) {
                count($approved) > 1 => self::blocked('keyframe_ambiguous', 'Shot '.$shotId.' có '.count($approved).' keyframe đã duyệt — không tự chọn.'),
                count($approved) === 1 => $this->checked($projectId, $context, $approved[0], $row, $source, $settings),
                default => self::blocked('keyframe_missing', "Shot {$shotId} chưa có keyframe đã duyệt ở màn Keyframes."),
            };
        }

        return $result;
    }

    /** @return array<string, mixed> what the Keyframes screen reads for the project, for keyframes() and inputs() to share one reading */
    public function frameContext(string $projectId): array
    {
        return $this->frames->context($projectId);
    }

    /**
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings  the settings of the clip flow
     * @param  ?array<string, mixed>  $context  frameContext(), read again when not given
     * @return array<string, array{mode: string, images: list<array<string, mixed>>, cards: list<array<string, mixed>>, limit: int, blocked: ?array{code: string, message: string}, settings?: array<string, mixed>}> for every shot of the plan by id, how its clip is made: from its approved keyframe, or from the reference images the Keyframes screen builds for the shot, in their order, without the ones a person left out; the cards are every reference image of the shot for the screen, a master of parts with how each of its parts stands in the shot; limit is how many images the model of the flow takes, and blocked says why the reference images cannot be sent. Under an execution policy every shot also carries its settings (settingsOf()), the images are the ones the settings select, and limit is the most images the chosen model of the policy takes
     */
    public function inputs(string $projectId, array $source, array $settings, ?array $context = null): array
    {
        $context ??= $this->frames->context($projectId);
        $stored = (array) ($this->projects->findMetadata($projectId)[self::INPUTS][$source['stage_id']] ?? []);
        $limit = (int) ($settings['reference_limit'] ?? 0);
        $default = ($settings['default_input'] ?? null) === self::REFERENCES ? self::REFERENCES : self::KEYFRAME;
        $execution = $this->execution($projectId);
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $choice = (array) ($stored[$shotId] ?? []);
            $mode = in_array($choice['mode'] ?? null, [self::KEYFRAME, self::REFERENCES], true) ? $choice['mode'] : $default;
            $excluded = array_map('strval', (array) ($choice['excluded'] ?? []));
            $built = $this->frames->cards($context, (array) ($context['shots'][$shotId] ?? $row), false, false);
            $own = array_values(array_filter($built['cards'], static fn (array $card): bool => in_array($card['kind'], self::REFERENCE_KINDS, true)));

            if ($execution !== null) {
                $result[$shotId] = $this->executionInput($shotId, $choice, $built, $own, $execution);

                continue;
            }

            $images = [];

            foreach ($built['sources'] as $image) {
                if (in_array($image['kind'], self::REFERENCE_KINDS, true) && ! in_array($image['artifact_id'], $excluded, true)) {
                    $images[] = ['position' => count($images), 'usage' => 'reference_only'] + array_diff_key($image, ['position' => true, 'chosen' => true, 'candidate_id' => true]);
                }
            }

            $broken = array_values(array_filter($own, static fn (array $card): bool => $card['problem'] !== null));
            $result[$shotId] = [
                'mode' => $mode,
                'images' => $mode === self::REFERENCES ? $images : [],
                'cards' => self::cardRows($own, static fn (string $artifactId): bool => ! in_array($artifactId, $excluded, true)),
                'limit' => $limit,
                'blocked' => match (true) {
                    $mode !== self::REFERENCES => null,
                    $broken !== [] => ['code' => 'reference_problem', 'message' => "Ảnh tham chiếu của shot {$shotId} chưa dùng được: ".implode(' · ', array_map(static fn (array $card): string => $card['name'].': '.$card['problem'], $broken)).'.'],
                    $images === [] => ['code' => 'references_missing', 'message' => "Shot {$shotId} chưa có ảnh tham chiếu nào để gửi — giữ ít nhất một ảnh ở cột Ảnh đầu vào."],
                    count($images) > $limit => ['code' => 'references_limit', 'message' => "Shot {$shotId} gửi ".count($images)." ảnh tham chiếu, vượt giới hạn {$limit} ảnh của model — bỏ bớt ảnh ở cột Ảnh đầu vào."],
                    default => null,
                },
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $own  the reference cards of the shot
     * @param  \Closure(string): bool  $kept  whether a person keeps the image of an artifact
     * @return list<array{name: string, kind: string, artifact_id: ?string, code: ?string, url: ?string, problem: ?string, included: bool, parts: array<string, mixed>}> the cards as the screen shows them, each included when it has a file, no problem and a person keeps it
     */
    private static function cardRows(array $own, \Closure $kept): array
    {
        return array_map(static fn (array $card): array => [
            'name' => (string) $card['name'],
            'kind' => (string) $card['kind'],
            'artifact_id' => $card['source']['artifact_id'] ?? null,
            'code' => $card['source']['code'] ?? null,
            'url' => isset($card['source']['artifact_id']) ? route('video-artifacts.show', (string) $card['source']['artifact_id']) : null,
            'problem' => $card['problem'],
            'included' => isset($card['source']['artifact_id']) && $card['problem'] === null && $kept((string) $card['source']['artifact_id']),
            'parts' => $card['kind'] === 'design' ? (array) ($card['presentations'] ?? []) : [],
        ], $own);
    }

    /**
     * @param  array<string, mixed>  $choice  what the project metadata holds for the shot
     * @param  array<string, mixed>  $built  MotoKeyframeSourceService::cards() of the shot
     * @param  list<array<string, mixed>>  $own  its reference cards
     * @param  array<string, mixed>  $execution  execution()
     * @return array<string, mixed> inputs() of one shot under an execution policy: the reference images its settings select, in the order the Keyframes screen builds them, sent only when the clip is an AI video from references, and why they cannot be sent — a card with a problem, a chosen image the shot no longer offers, none kept, or more than the chosen model of the policy takes; no image is dropped or replaced to fit
     */
    private function executionInput(string $shotId, array $choice, array $built, array $own, array $execution): array
    {
        $settings = self::settingsOf($choice, $execution, self::offered($own));
        $references = $settings['motion_method'] === MotoContract::MOTION_AI_VIDEO && $settings['input_mode'] === self::REFERENCES;
        $images = [];

        foreach ($built['sources'] as $image) {
            if (in_array($image['kind'], self::REFERENCE_KINDS, true) && in_array($image['artifact_id'], $settings['images'], true)) {
                $images[] = ['position' => count($images), 'usage' => 'reference_only'] + array_diff_key($image, ['position' => true, 'chosen' => true, 'candidate_id' => true]);
            }
        }

        $gone = array_values(array_diff($settings['images'], array_column($images, 'artifact_id')));
        $broken = array_values(array_filter($own, static fn (array $card): bool => $card['problem'] !== null));
        $limit = self::referenceLimit($execution, $settings);

        return [
            'mode' => $settings['input_mode'],
            'images' => $references ? $images : [],
            'cards' => self::cardRows($own, static fn (string $artifactId): bool => in_array($artifactId, $settings['images'], true)),
            'limit' => $limit,
            'blocked' => match (true) {
                ! $references => null,
                $broken !== [] => ['code' => 'reference_problem', 'message' => "Ảnh tham chiếu của shot {$shotId} chưa dùng được: ".implode(' · ', array_map(static fn (array $card): string => $card['name'].': '.$card['problem'], $broken)).'.'],
                $gone !== [] => ['code' => 'reference_selection_changed', 'message' => "Shot {$shotId} đã chọn ảnh tham chiếu không còn trong bộ ảnh của shot (".implode(', ', $gone).') — chọn lại ở cột Ảnh đầu vào; không tự dùng ảnh khác.'],
                $images === [] => ['code' => 'references_missing', 'message' => "Shot {$shotId} chưa có ảnh tham chiếu nào để gửi — giữ ít nhất một ảnh ở cột Ảnh đầu vào."],
                $limit > 0 && count($images) > $limit => ['code' => 'references_limit', 'message' => "Shot {$shotId} gửi ".count($images)." ảnh tham chiếu, vượt giới hạn {$limit} ảnh của model đã chọn — bỏ bớt ảnh hoặc chọn model khác."],
                default => null,
            },
            'settings' => $settings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $own  the reference cards of the shot
     * @return list<string> the artifacts of the reference images a person can keep now: every card with a file and no problem, in their order
     */
    private static function offered(array $own): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $card): ?string => $card['problem'] === null && is_string($card['source']['artifact_id'] ?? null) ? (string) $card['source']['artifact_id'] : null, $own), 'is_string')));
    }

    /**
     * @param  array<string, mixed>  $execution
     * @param  array<string, mixed>  $settings  settingsOf()
     * @return int the most reference images the model the settings choose for a clip from references takes, as the policy offers it; 0 when the policy offers none
     */
    private static function referenceLimit(array $execution, array $settings): int
    {
        $offers = array_values(array_filter((array) ($execution['offers'][MotoContract::MOTION_AI_VIDEO][self::REFERENCES] ?? []), static fn (array $offer): bool => $offer['unavailable'] === null));
        $offer = $settings['model'] === null ? ($offers[0] ?? null) : (array_values(array_filter($offers, static fn (array $row): bool => $row['model'] === $settings['model']))[0] ?? null);

        return (int) ($offer['max_images'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $choice  what the project metadata holds for the shot: settings of the structure MotoContract::CLIP_SETTINGS, an entry written before it (mode and excluded), or nothing
     * @param  array<string, mixed>  $execution  execution()
     * @param  list<string>  $offered  the reference images the shot offers now, in their order
     * @return array{version: string, revision: int, saved: bool, motion_method: string, input_mode: string, model: ?string, resolution: ?string, images: list<string>, crop_plan: ?array<string, mixed>, audio_policy: ?string, updated_at: ?string, updated_by: ?string, problem: ?string} the settings of the shot: what a person saved, else the defaults of the policy (an AI video from the keyframe set, every offered image kept, model, resolution and audio rule null for what the policy gives); an entry written before this structure is read as an AI video with its mode and with the images it did not leave out, at revision 0; a stored entry this code cannot read keeps its revision and says why in problem, so the shot is blocked until it is saved again
     */
    public static function settingsOf(array $choice, array $execution, array $offered): array
    {
        $settings = ['version' => MotoContract::CLIP_SETTINGS, 'revision' => 0, 'saved' => false, 'motion_method' => (string) $execution['default_motion_method'], 'input_mode' => (string) $execution['default_input_mode'], 'model' => null, 'resolution' => null, 'images' => $offered, 'crop_plan' => null, 'audio_policy' => null, 'updated_at' => null, 'updated_by' => null, 'problem' => null];

        if ($choice === []) {
            return $settings;
        }

        if (! array_key_exists('version', $choice)) {
            $mode = $choice['mode'] ?? null;
            $known = in_array($mode, [self::KEYFRAME, self::REFERENCES], true);

            return array_replace($settings, [
                'saved' => true,
                'motion_method' => MotoContract::MOTION_AI_VIDEO,
                'input_mode' => $known ? (string) $mode : $settings['input_mode'],
                'images' => array_values(array_diff($offered, array_map('strval', (array) ($choice['excluded'] ?? [])))),
                'problem' => $known ? null : 'cách render đã lưu '.json_encode($mode, JSON_UNESCAPED_UNICODE).' không hợp lệ — lưu lại thiết lập của shot.',
            ]);
        }

        $revision = is_int($choice['revision'] ?? null) && $choice['revision'] > 0 ? $choice['revision'] : 0;
        $problem = ($choice['version'] ?? null) !== MotoContract::CLIP_SETTINGS
            ? 'thiết lập đã lưu theo cấu trúc '.json_encode($choice['version'], JSON_UNESCAPED_UNICODE).', code đọc '.MotoContract::CLIP_SETTINGS.' — lưu lại thiết lập của shot.'
            : ($revision === 0 ? 'thiết lập đã lưu không có revision hợp lệ — lưu lại thiết lập của shot.' : self::settingsProblem($choice, $execution, null));

        if ($problem !== null) {
            return array_replace($settings, ['revision' => $revision, 'saved' => true, 'problem' => $problem]);
        }

        return array_replace($settings, array_intersect_key($choice, array_flip([...self::SETTING_FIELDS, 'updated_at', 'updated_by'])), ['revision' => $revision, 'saved' => true]);
    }

    /**
     * @param  array<string, mixed>  $values  settings of the structure MotoContract::CLIP_SETTINGS
     * @param  array<string, mixed>  $execution  execution()
     * @param  ?list<string>  $offered  the reference images the shot offers now when a person saves: the choice must then be one the policy offers and can send now and its images ones the shot offers; null when stored settings are read again, whose choice the plan judges
     * @param  bool  $held  when a person saves, whether the images are held to the ones the shot offers now: settingsAfter() says when
     * @return ?string why the settings cannot be taken: a value of the wrong kind, a way of making the clip the policy does not offer, a model or resolution it does not offer for that way, an image the shot does not offer, a crop path that is not one, or an audio rule the way cannot keep; null when they can
     */
    private static function settingsProblem(array $values, array $execution, ?array $offered, bool $held = true): ?string
    {
        $motion = $values['motion_method'] ?? null;
        $mode = $values['input_mode'] ?? null;
        $model = $values['model'] ?? null;
        $resolution = $values['resolution'] ?? null;
        $images = $values['images'] ?? null;
        $audio = $values['audio_policy'] ?? null;
        $saving = $offered !== null;

        if (! in_array($motion, MotoClipPlanBuilder::MOTIONS, true) || ! in_array($mode, MotoClipPlanBuilder::INPUTS, true)) {
            return 'cách làm clip '.json_encode([$motion, $mode], JSON_UNESCAPED_UNICODE).' không phải cách code biết.';
        }

        if (($model !== null && ! is_string($model)) || ($resolution !== null && (! is_string($resolution) || preg_match('/^[0-9a-z]+$/', $resolution) !== 1))) {
            return 'model và resolution phải là mã hoặc null.';
        }

        if (! is_array($images) || ! array_is_list($images) || array_filter($images, static fn (mixed $id): bool => ! is_string($id) || $id === '') !== [] || count(array_unique($images)) !== count($images)) {
            return 'ảnh tham chiếu đã chọn phải là danh sách mã artifact không trùng.';
        }

        if ($audio !== null && ! in_array($audio, MotoClipPlanBuilder::AUDIO_POLICIES, true)) {
            return 'chính sách âm thanh phải là '.implode(' hoặc ', MotoClipPlanBuilder::AUDIO_POLICIES).' hoặc null.';
        }

        if (($values['crop_plan'] ?? null) !== null) {
            $checked = MotoClipPlanBuilder::cropPath($values['crop_plan'], null, $execution, 'Thiết lập');

            if ($checked['problem'] !== null) {
                return $checked['problem']['message'];
            }
        }

        if (! $saving) {
            return null;
        }

        $offers = (array) ($execution['offers'][$motion][$mode] ?? []);
        $available = array_values(array_filter($offers, static fn (array $offer): bool => $offer['unavailable'] === null));
        $offer = $model === null ? ($available[0] ?? null) : (array_values(array_filter($available, static fn (array $row): bool => $row['model'] === $model))[0] ?? null);
        $outside = $held ? array_values(array_diff($images, $offered)) : [];

        return match (true) {
            $offers === [] => $motion === MotoContract::MOTION_DYNAMIC_CROP ? 'Dynamic Crop chỉ làm từ bộ keyframe đã duyệt, không dùng ảnh tham chiếu.' : "chính sách thực thi không có cách làm {$motion} từ {$mode}.",
            $motion === MotoContract::MOTION_DYNAMIC_CROP && $model !== null => 'Dynamic Crop không gọi model video.',
            $offer === null => $model === null ? 'chưa có model nào dùng được cho cách làm này.' : "model {$model} không phải lựa chọn dùng được của chính sách cho cách làm này.",
            $resolution !== null && ! in_array($resolution, $offer['resolutions'], true) => "resolution {$resolution} không phải lựa chọn dùng được của ".($offer['model'] ?? $offer['processor']).'.',
            $outside !== [] => 'ảnh '.implode(', ', $outside).' không phải ảnh tham chiếu shot đang có.',
            $motion === MotoContract::MOTION_DYNAMIC_CROP && $audio === MotoContract::AUDIO_REQUIRE => 'Dynamic Crop không tạo âm thanh — chọn allow_silent hoặc làm clip bằng AI Video.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $stored  settingsOf() of the shot as stored now
     * @param  array<string, mixed>  $changes  the fields a person changed (SETTING_FIELDS)
     * @param  array<string, mixed>  $execution  execution()
     * @param  list<string>  $offered  the reference images the shot offers now, in their order
     * @return array{0: array<string, mixed>, 1: ?string} the fields of the settings after the changes, nothing dropped from them, and why they cannot be saved (settingsProblem()), null when they can. The images are held to the ones the shot offers now only when this save chooses them: an image kept earlier that the shot no longer offers never stops a save that does not choose images, the turn to references included, whose plan then asks for the images to be chosen again before anything is sent
     */
    public static function settingsAfter(array $stored, array $changes, array $execution, array $offered): array
    {
        $next = array_replace(array_intersect_key($stored, array_flip(self::SETTING_FIELDS)), $changes);
        $held = array_key_exists('images', $changes);

        return [$next, self::settingsProblem($next, $execution, $offered, $held)];
    }

    /**
     * @return array<string, mixed> the settings of the shot now (settingsOf()), with their revision, for a page that reads one shot again
     *
     * @throws RuntimeException when the release has no execution policy or the shot is not in the approved plan
     */
    public function settings(string $projectId, string $shotId): array
    {
        return $this->executionEntry($projectId, $shotId)[1]['settings'];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} the approved shot plan, inputs() of the shot and the execution policy
     *
     * @throws RuntimeException when the release has no execution policy or the shot is not in the approved plan
     */
    private function executionEntry(string $projectId, string $shotId): array
    {
        $execution = $this->execution($projectId) ?? throw new RuntimeException('Bản phát hành của dự án không khai chính sách thực thi clip — thiết lập theo shot không áp dụng.');
        $source = $this->source($projectId);
        $entry = $this->inputs($projectId, $source, $this->workflowFor($projectId)['settings'])[$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");

        return [$source, $entry, $execution];
    }

    /**
     * @param  array<string, mixed>  $changes  the fields a person changed (SETTING_FIELDS): motion_method, input_mode, model and resolution (null takes what the policy gives), images (the reference images kept, by artifact id), crop_plan (null, or a path of {at_ms, x, y, width, height} with optionally protected, the region {x, y, width, height} of the subject a person confirmed, which every window must hold whole), audio_policy (null takes the rule of the policy); a field left out keeps its value
     * @param  int  $expectedRevision  the revision of the settings the page holds: 0 when none was saved under this structure
     * @param  ?string  $actor  who saves
     * @param  ?array{stage_id: string, content_hash: string}  $expectedSource  the approved shot plan the page was drawn from, by its stage and content hash; every save a page sends gives it. Null only for code that holds no page and saves for the plan approved now
     * @return array<string, mixed> the settings of the shot as stored now, settingsOf(); nothing is sent and no prompt is written. The clip in use is not touched: it is judged by what its own request pinned
     *
     * @throws RuntimeException when the release has no execution policy, the shot is not in the approved plan, a field is unknown or the choice is not one the policy and the shot offer now; with code CONFLICT when the approved shot plan is no longer the one the page was drawn from, and then the whole page is loaded again, or when the settings were saved elsewhere since the page read them, and then the page reads the shot again. Both are judged after the project is locked, against the plan approved then
     */
    public function saveSettings(string $projectId, string $shotId, array $changes, int $expectedRevision, ?string $actor = null, ?array $expectedSource = null): array
    {
        $unknown = array_values(array_diff(array_keys($changes), self::SETTING_FIELDS));

        if ($unknown !== []) {
            throw new RuntimeException('Thiết lập clip không có trường '.implode(', ', $unknown).'.');
        }

        if (array_key_exists('crop_plan', $changes) && is_array($changes['crop_plan'])) {
            $changes['crop_plan'] = ['version' => MotoContract::DYNAMIC_CROP_PROCESSOR, 'path' => $changes['crop_plan']['path'] ?? null] + array_diff_key($changes['crop_plan'], ['version' => true, 'path' => true]);
        }

        return $this->storeSettings($projectId, $shotId, static fn (): array => $changes, $expectedRevision, $expectedSource, $actor);
    }

    /**
     * @param  array<string, mixed>  $source  source(): the shot plan approved now
     * @param  ?array<string, mixed>  $expected  the shot plan a page was drawn from, by stage_id and content_hash; null when the caller holds no page
     * @return ?string why a save of that page cannot be taken: the approved shot plan is another one, or the same stage with other content; null when it is the plan the page shows, or no page is held
     */
    public static function sourceProblem(array $source, ?array $expected): ?string
    {
        if ($expected === null) {
            return null;
        }

        $stage = (string) ($source['stage_id'] ?? '');
        $hash = (string) ($source['token']['content_hash'] ?? '');
        $held = is_string($expected['stage_id'] ?? null) ? $expected['stage_id'] : '';

        return $stage !== '' && $hash !== '' && $held === $stage && is_string($expected['content_hash'] ?? null) && hash_equals($hash, $expected['content_hash'])
            ? null
            : 'Kế hoạch shot đã duyệt của dự án không còn là bản trang đang hiển thị (trang giữ bản '.($held === '' ? '—' : substr($held, 0, 8)).', bản đang duyệt là '.($stage === '' ? '—' : substr($stage, 0, 8)).') — tải lại cả trang Render video rồi chọn lại; chưa có gì được ghi.';
    }

    /**
     * @param  \Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $changesOf  the fields to change (SETTING_FIELDS), given the settings of the shot as stored and inputs() of the shot, both read under the lock
     * @param  ?array<string, mixed>  $expectedSource  sourceProblem()
     * @return array<string, mixed> the settings of the shot as stored now, settingsOf()
     *
     * @throws RuntimeException saveSettings()
     */
    private function storeSettings(string $projectId, string $shotId, \Closure $changesOf, int $expectedRevision, ?array $expectedSource, ?string $actor): array
    {
        return DB::transaction(function () use ($projectId, $shotId, $changesOf, $expectedRevision, $expectedSource, $actor): array {
            $project = $this->projects->lockProject($projectId);
            $execution = $this->execution($projectId) ?? throw new RuntimeException('Bản phát hành của dự án không khai chính sách thực thi clip — thiết lập theo shot không áp dụng.');
            $source = $this->source($projectId);

            if (($changed = self::sourceProblem($source, $expectedSource)) !== null) {
                throw new RuntimeException($changed, self::CONFLICT);
            }

            $entry = $this->inputs($projectId, $source, $this->workflowFor($projectId)['settings'])[$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
            $offered = array_values(array_filter(array_map(static fn (array $card): ?string => $card['problem'] === null && is_string($card['artifact_id']) ? $card['artifact_id'] : null, $entry['cards']), 'is_string'));
            $metadata = (array) ($project->metadata_json ?? []);
            $stored = self::settingsOf((array) ($metadata[self::INPUTS][$source['stage_id']][$shotId] ?? []), $execution, $offered);

            if ($stored['revision'] !== $expectedRevision) {
                throw new RuntimeException("Thiết lập clip của shot {$shotId} đã được lưu ở nơi khác (revision {$stored['revision']}, trang đang giữ revision {$expectedRevision}) — tải lại dữ liệu của shot rồi chọn lại; chưa có gì được ghi.", self::CONFLICT);
            }

            $changes = $changesOf($stored, $entry);
            [$next, $problem] = self::settingsAfter($stored, $changes, $execution, $offered);

            if ($problem !== null) {
                throw new RuntimeException("Thiết lập clip của shot {$shotId} không lưu được: {$problem}");
            }

            $metadata[self::INPUTS][$source['stage_id']][$shotId] = ['version' => MotoContract::CLIP_SETTINGS, 'revision' => $stored['revision'] + 1]
                + array_intersect_key($next, array_flip(self::SETTING_FIELDS))
                + ['updated_at' => now()->toIso8601String(), 'updated_by' => $actor];
            $this->projects->saveLockedMetadata($project, $metadata);

            return self::settingsOf($metadata[self::INPUTS][$source['stage_id']][$shotId], $execution, $offered);
        });
    }

    /**
     * @return list<array{shot_id: string, mode: string, added: list<string>, removed: list<string>, missing: list<string>, images: int, expected: int, limit: int, over: bool, image_prompt: ?string, video_prompt: ?string}> for every shot of the approved plan, what the Reference plan built now changes against the one saved last: the part masters the shot sends now and no longer sends, by image code, with the masters a person chose or left out for the way its clip is made; the parts in its frame with no approved image; how many images it sends now and at least how many once every source is there; the limit of that way; and, for the approved keyframe prompt and the approved video prompt of the shot, whether the check of its own screen still takes it, null when the shot has none; reading it writes nothing
     *
     * @throws RuntimeException when no shot plan is approved
     */
    public function impact(string $projectId): array
    {
        $source = $this->source($projectId);
        $settings = $this->workflowFor($projectId)['settings'];
        $context = $this->frames->context($projectId);
        $inputs = $this->inputs($projectId, $source, $settings, $context);
        $before = app(PartsDetailService::class)->savedPartsByShot($projectId);
        $video = app(ClipPromptWriterService::class)->current($projectId, $source, $this->keyframes($projectId, $source, $settings, $context), $inputs);
        $frames = app(MotoKeyframeService::class);
        $imageWriter = app(KeyframePromptWriterService::class);
        $codes = array_column((array) $context['masters'], 'code', 'artifact_id');
        $named = static fn (array $ids): array => array_values(array_map(static fn (string $id): string => (string) ($codes[$id] ?? $id), $ids));
        $mastersOn = static fn (array $built): array => array_values(array_unique(array_filter(array_map(static fn (array $card): ?string => $card['kind'] === 'design' && ! ($card['left_out'] ?? false) ? ($card['source']['artifact_id'] ?? null) : null, $built['cards']))));
        $rows = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $shot = (array) ($context['shots'][$shotId] ?? $row);
            $byReferences = $inputs[$shotId]['mode'] === self::REFERENCES;
            $built = $this->frames->cards($context, $shot, false, ! $byReferences);
            $earlier = $this->frames->cards($context, ['id' => $shotId, 'reference_needs' => [], 'parts' => array_map(static fn (string $part): array => ['part' => $part, 'presentation' => ''], (array) ($before[$shotId] ?? []))], false, ! $byReferences);
            $left = $byReferences ? array_column(array_filter($inputs[$shotId]['cards'], static fn (array $card): bool => $card['kind'] === 'design' && $card['artifact_id'] !== null && ! $card['included']), 'artifact_id') : [];
            $now = array_values(array_diff($mastersOn($built), $left));
            $was = array_values(array_diff($mastersOn($earlier), $left));
            $cards = array_values(array_filter($built['cards'], static fn (array $card): bool => ! ($card['left_out'] ?? false) && (! $byReferences || in_array($card['kind'], self::REFERENCE_KINDS, true))));
            $images = $byReferences ? count($inputs[$shotId]['images']) : count($built['sources']);
            $waiting = count(array_filter($cards, static fn (array $card): bool => $card['source'] === null));
            $limit = $byReferences ? (int) $inputs[$shotId]['limit'] : (int) $context['limit'];
            $added = array_values(array_diff($now, $was));
            $removed = array_values(array_diff($was, $now));
            $missing = array_merge([], ...array_map(static fn (array $card): array => $card['kind'] === 'design' && $card['source'] === null ? (array) $card['parts'] : [], $built['cards']));
            $imagePrompt = ($context['prompts'][$shotId] ?? null) === null ? null
                : ($imageWriter->screen($projectId, $context, $shot, $this->frames->cards($context, $shot), $frames->settingsFor($context, $shot))['stale'] ? 'nguồn đã đổi — cần viết hoặc duyệt lại' : 'dùng được');
            $videoPrompt = match ($video[$shotId]['blocked']['code'] ?? null) {
                null => 'dùng được',
                'prompt_missing' => null,
                default => 'nguồn đã đổi — cần viết hoặc duyệt lại',
            };
            $rows[] = [
                'shot_id' => $shotId,
                'mode' => (string) $inputs[$shotId]['mode'],
                'added' => $named($added),
                'removed' => $named($removed),
                'missing' => $missing,
                'images' => $images,
                'expected' => $images + $waiting,
                'limit' => $limit,
                'over' => $images + $waiting > $limit,
                'image_prompt' => $imagePrompt,
                'video_prompt' => $videoPrompt,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $included  the artifacts of the reference images the person keeps
     * @param  ?int  $expectedRevision  under an execution policy, the revision of the settings of the shot the form holds
     * @param  ?array{stage_id: string, content_hash: string}  $expectedSource  under an execution policy, the approved shot plan the form was drawn from (saveSettings())
     * @return void the way the clip of the shot is made and the reference images left out are stored for the approved shot plan; nothing is sent and no prompt is written. Under an execution policy the choice is stored as settings of an AI video from that input, under the two conditions of saveSettings(): the form gives the revision and the shot plan it holds, and the latest stored is never taken in their place
     *
     * @throws RuntimeException when the shot is not in the approved plan or the mode is not one this flow has; under an execution policy also when the form gives no revision or no shot plan, and with code CONFLICT when either is no longer the one stored
     */
    public function saveInput(string $projectId, string $shotId, string $mode, array $included, ?int $expectedRevision = null, ?array $expectedSource = null): void
    {
        if (! in_array($mode, [self::KEYFRAME, self::REFERENCES], true)) {
            throw new RuntimeException('Cách render không hợp lệ.');
        }

        if ($this->execution($projectId) !== null) {
            if ($expectedRevision === null || ! is_string($expectedSource['stage_id'] ?? null) || ! is_string($expectedSource['content_hash'] ?? null)) {
                throw new RuntimeException("Cách render của shot {$shotId} chưa lưu: form không gửi revision thiết lập và kế hoạch shot mà trang đang hiển thị — tải lại trang Render video rồi chọn lại; chưa có gì được ghi.");
            }

            $kept = array_map('strval', $included);
            $this->storeSettings($projectId, $shotId, static function (array $stored, array $entry) use ($mode, $kept): array {
                $offered = array_values(array_filter(array_column($entry['cards'], 'artifact_id'), 'is_string'));
                $moved = $stored['motion_method'] !== MotoContract::MOTION_AI_VIDEO || $stored['input_mode'] !== $mode;

                return ['motion_method' => MotoContract::MOTION_AI_VIDEO, 'input_mode' => $mode, 'images' => array_values(array_intersect($offered, $kept))] + ($moved ? ['model' => null, 'resolution' => null] : []);
            }, $expectedRevision, $expectedSource, null);

            return;
        }

        $source = $this->source($projectId);
        $inputs = $this->inputs($projectId, $source, $this->workflowFor($projectId)['settings']);

        if (! isset($inputs[$shotId])) {
            throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        }

        $offered = array_values(array_filter(array_column($inputs[$shotId]['cards'], 'artifact_id'), 'is_string'));
        $project = $this->projects->lockProject($projectId);
        $metadata = (array) ($project->metadata_json ?? []);
        $metadata[self::INPUTS][$source['stage_id']][$shotId] = [
            'mode' => $mode,
            'excluded' => array_values(array_diff($offered, array_map('strval', $included))),
        ];
        $this->projects->saveLockedMetadata($project, $metadata);
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $row  the shot
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings
     * @return array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}
     */
    private function checked(string $projectId, array $context, VideoDesignImage $image, array $row, array $source, array $settings): array
    {
        $shotId = (string) $row['id'];
        $spec = (array) $image->prompt_spec_json;
        $token = (array) ($spec['source_token'] ?? []);

        if (($spec['shot_id'] ?? null) !== $shotId || ! is_string($spec['composer'] ?? null) || ! is_string($spec['image_sha256'] ?? null)) {
            return self::blocked('keyframe_link_invalid', "Keyframe của shot {$shotId} không theo hợp đồng của màn Keyframes.");
        }

        if (($spec['shot_stage_id'] ?? null) !== $source['stage_id'] || ($token['stage_id'] ?? null) !== $source['token']['stage_id'] || ($token['content_hash'] ?? null) !== $source['token']['content_hash']) {
            return self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId} được làm từ nguồn khác bản kế hoạch đã duyệt.");
        }

        $pinned = ($spec['spec_version'] ?? null) === MotoKeyframeSourceService::SPEC_V2;

        if (! $pinned && ($spec['image_sha256'] !== ($context['prompts'][$shotId]['sha256'] ?? null) || ($spec['prompt_source']['stage_id'] ?? null) !== ($context['prompts'][$shotId]['stage_id'] ?? null))) {
            return self::blocked('keyframe_prompt_changed', "Prompt của keyframe shot {$shotId} khác prompt shot hiện hành — render và duyệt lại keyframe.");
        }

        if ($image->approved_at === null || $image->selected_artifact_id === null) {
            return self::blocked('keyframe_unapproved', "Keyframe của shot {$shotId} chưa có quyết định duyệt cho một file.");
        }

        $frame = $this->frames->approvedFrame($context, $shotId);
        $held = $frame !== null && (string) $frame['image']->id === (string) $image->id;

        if ($held && $frame['framed'] && $frame['handoff'] === null) {
            return self::blocked('keyframe_delivery_missing', "Bộ keyframe đã duyệt của shot {$shotId} không ghi ảnh video — áp dụng khung và duyệt lại bộ ảnh.");
        }

        $artifact = $held && $frame['framed'] ? $frame['handoff']
            : $this->artifacts->findKeyframeArtifact($projectId, $image->selected_artifact_id, $image->id);

        if ($artifact === null || $artifact->render_id === null) {
            return self::blocked('keyframe_artifact_missing', "File keyframe đã duyệt của shot {$shotId} không còn hoặc không gắn lượt render nào.");
        }

        $problem = $this->fileProblem($artifact, (string) ($source['context']['aspect_ratio'] ?? ''), (float) ($settings['aspect_tolerance'] ?? 0));

        if ($problem !== null) {
            return self::blocked($problem[0], "Keyframe của shot {$shotId}: {$problem[1]}");
        }

        $stale = $this->frames->keyframeProblem($context, $image);

        if ($stale !== null) {
            return self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId}: {$stale}.");
        }

        return [
            'keyframe' => [
                'image_id' => (string) $image->id,
                'artifact_id' => (string) $artifact->id,
                'sha256' => (string) $artifact->sha256,
                'source_token' => ['stage_id' => (string) $token['stage_id'], 'content_hash' => (string) $token['content_hash']],
                'prompt' => ['version' => $spec['composer'], 'surface_rule' => MotoKeyframePrompt::SURFACE_RULE, 'sha256' => $spec['image_sha256']],
            ],
            'url' => route('video-artifacts.show', (string) $artifact->id),
            'blocked' => null,
            'set' => $held ? self::setOf($image, $frame, $artifact) : null,
            'plate' => $held ? $this->plateOf($projectId, $image) : null,
        ];
    }

    /**
     * @return ?array{background: string, artifact_id: string, sha256: string, image_id: string} the plate the approved keyframe stands on, as its own render pinned it: the background of the image of the place that render sent, with the artifact and the hash of that image and the candidate whose render sent it; for a keyframe edited from another candidate without an image of a place, that of the candidate it was edited from; null when no render of that chain sent one
     */
    public function plateOf(string $projectId, VideoDesignImage $image): ?array
    {
        $seen = [];
        $at = $image;

        while ($at !== null && ! isset($seen[(string) $at->id])) {
            $seen[(string) $at->id] = true;
            $spec = (array) $at->prompt_spec_json;

            foreach ((array) ($spec['sources'] ?? []) as $source) {
                if (is_array($source) && ($source['kind'] ?? null) === 'environment' && is_string($source['target'] ?? null) && $source['target'] !== '') {
                    return ['background' => $source['target'], 'artifact_id' => (string) ($source['artifact_id'] ?? ''), 'sha256' => (string) ($source['sha256'] ?? ''), 'image_id' => (string) $at->id];
                }
            }

            $base = $spec['edit']['base_image_id'] ?? null;
            $at = is_string($base) && $base !== '' ? $this->images->findForProject($projectId, $base) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $frame  MotoKeyframeSourceService::approvedFrame() of the shot
     * @param  VideoArtifact  $handoff  the image of the set handed to the video, checked by checked()
     * @return array{image_id: string, decision_id: ?string, framed: bool, master: array{artifact_id: string, sha256: string, width: int, height: int}, delivery: array{artifact_id: string, sha256: string, width: int, height: int}, crop: ?array{x: int, y: int, width: int, height: int}, crop_hash: ?string} the approved keyframe set as a clip under an execution policy pins it: the decision that approved it, the master the image model rendered, the delivery image handed to the video with the crop it was cut by (none for a set rendered at the frame of the film, whose master is its delivery); the plan of the earlier formats never reads it
     */
    private static function setOf(VideoDesignImage $image, array $frame, VideoArtifact $handoff): array
    {
        $pin = static fn (VideoArtifact $artifact): array => ['artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'width' => (int) $artifact->width, 'height' => (int) $artifact->height];
        $meta = (array) $handoff->metadata_json;
        $crop = (array) ($meta['crop'] ?? []);
        $whole = $frame['framed'] && array_keys($crop) === ['x', 'y', 'width', 'height'] && array_filter($crop, static fn (mixed $value): bool => ! is_int($value)) === [];

        return [
            'image_id' => (string) $image->id,
            'decision_id' => $frame['approval']['decision_id'] ?? null,
            'framed' => $whole,
            'master' => $pin($frame['master']),
            'delivery' => $pin($handoff),
            'crop' => $whole ? $crop : null,
            'crop_hash' => $whole && is_string($meta['crop_hash'] ?? null) ? (string) $meta['crop_hash'] : ($whole ? CanonicalJson::hash($crop) : null),
        ];
    }

    /** @return ?array{0: string, 1: string} why the file of a keyframe cannot be sent: where it is stored, its bytes against the hash the artifact records, its format or its shape against the frame of the film */
    private function fileProblem(VideoArtifact $artifact, string $aspect, float $tolerance): ?array
    {
        $disk = (string) $artifact->storage_disk;
        $path = (string) $artifact->storage_path;

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            return ['keyframe_disk_refused', 'file nằm trên disk không được phép: '.$disk.'.'];
        }

        $filesystem = $this->storage->disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return ['keyframe_file_missing', 'không còn file trên disk.'];
        }

        $size = (int) $filesystem->size($path);

        if ($size <= 0 || $size > (int) config('video.veo.max_source_bytes', 33554432)) {
            return ['keyframe_file_size', 'kích thước file không hợp lệ: '.$size.'.'];
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
            return ['keyframe_hash_mismatch', 'file khác hash đã duyệt.'];
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $width = is_array($info) ? (int) $info[0] : 0;
        $height = is_array($info) ? (int) $info[1] : 0;

        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || $width <= 0 || $height <= 0) {
            return ['keyframe_format', 'file không đọc được như PNG hoặc JPEG.'];
        }

        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $aspect, $ratio) !== 1) {
            return ['keyframe_ratio', 'kế hoạch không ghi tỉ lệ khung hình để so.'];
        }

        $wanted = (int) $ratio[1] / (int) $ratio[2];

        return abs($width / $height - $wanted) / $wanted > $tolerance
            ? ['keyframe_ratio', "ảnh {$width}×{$height} lệch khung {$aspect}."]
            : null;
    }

    /** @return array{keyframe: null, url: null, blocked: array{code: string, message: string}, set: null} */
    private static function blocked(string $code, string $message): array
    {
        return ['keyframe' => null, 'url' => null, 'blocked' => ['code' => $code, 'message' => $message], 'set' => null];
    }
}
