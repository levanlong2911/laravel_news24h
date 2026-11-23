<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Schema\JsonStructureSchema;
use App\Video\Schema\JsonStructureSchemaException;
use Opis\JsonSchema\Exceptions\SchemaException;

final class MotoContract
{
    public const WORKFLOW = 'harley_custom_v1';

    public const ARTICLE_INPUT = 'phase1-article-v1';

    public const EVIDENCE_FORMAT = 'moto-evidence-v1';

    public const DNA_INPUT = 'moto-dna-input-v1';

    public const DNA_FORMAT = 'moto-dna-v1';

    public const DNA_INPUT_V2 = 'moto-dna-input-v2';

    public const DNA_FORMAT_V2 = 'moto-dna-v2';

    public const DESIGN_INPUT = 'moto-design-input-v1';

    public const DESIGN_FORMAT = 'moto-design-v1';

    public const DESIGN_INPUT_V2 = 'moto-design-input-v2';

    public const DESIGN_FORMAT_V2 = 'moto-design-v2';

    /** @var string the answer of a design of seven stages whose physical stages pass through checkpoints (MotoDesign 3.x, MotoDesignStates) */
    public const DESIGN_FORMAT_V3 = 'moto-design-v3';

    public const SCRIPT_INPUT = 'moto-script-input-v3';

    public const SCRIPT_FORMAT = 'moto-script-v1';

    public const SCRIPT_INPUT_V4 = 'moto-script-input-v4';

    public const SCRIPT_FORMAT_V2 = 'moto-script-v2';

    /** @var string the input of a script written from a design of moto-design-v3: the design with its seven stages and their checkpoints, and film_facts by checkpoint */
    public const SCRIPT_INPUT_V5 = 'moto-script-input-v5';

    /** @var string the input of a script written within the film limits of its release (MotoPipelineRelease::filmLimits): the fifth input with variables.film in place of shot_duration_ms and target_film_duration_ms; its answer is a MotoScript 3.1 document */
    public const SCRIPT_INPUT_V6 = 'moto-script-input-v6';

    /** @var string the answer of such a script: the beats of moto-script-v2, each also naming the checkpoint it opens on and the one it ends on (MotoDesignStates) */
    public const SCRIPT_FORMAT_V3 = 'moto-script-v3';

    public const LOCATIONS_INPUT = 'moto-locations-input-v1';

    public const LOCATIONS_FORMAT = 'moto-locations-v1';

    public const LOCATIONS_INPUT_V2 = 'moto-locations-input-v2';

    public const LOCATIONS_FORMAT_V2 = 'moto-locations-v2';

    /** @var string the input of locations made from a script of moto-script-v3: each beat with the checkpoints it runs through and the temporary equipment they hold, each build state a checkpoint with its stage and label; the answer stays moto-locations-v2 */
    public const LOCATIONS_INPUT_V3 = 'moto-locations-input-v3';

    /** @var string the input of a location dossier (MotoLocations 3.x): the third input with the look code gives every plate, the theme and emotional goal of the story, the characters by role, each beat's act, time jump and sound effects, each event's vehicle motion, the size of the motorcycle and the flags the script left open */
    public const LOCATIONS_INPUT_V4 = 'moto-locations-input-v4';

    /** @var string the answer of such a dossier: places with their function, zones, access routes and coordinate system, the connections between places, and plates that carry the conditions of their use */
    public const LOCATIONS_FORMAT_V3 = 'moto-locations-v3';

    /** @var string the input of a set design dossier (MotoLocations 4.x): the fourth input with temporary_equipment, every temporary item the checkpoints of the design allow, which an object that holds the subject names as its support_kind */
    public const LOCATIONS_INPUT_V5 = 'moto-locations-input-v5';

    /** @var string the answer of such a dossier: each place states the one design of its set — architecture, materials and objects with their base state — and each plate states only its use: a camera, a lighting plan, the state of the set as changes from the base state, and a continuity that says what plates of one place keep and what may differ */
    public const LOCATIONS_FORMAT_V4 = 'moto-locations-v4';

    public const SCENES_INPUT = 'moto-scenes-input-v2';

    public const SCENES_FORMAT = 'moto-scenes-v1';

    public const SCENES_INPUT_V3 = 'moto-scenes-input-v3';

    public const SCENES_FORMAT_V2 = 'moto-scenes-v2';

    /** @var string the input of a scene plan whose work is followed under MotoWork::LAW_V2: the third input with work_tracking_version */
    public const SCENES_INPUT_V4 = 'moto-scenes-input-v4';

    /** @var string the answer of such a scene plan: no detached parts written by the model, work evidenced by event id, connection changes */
    public const SCENES_FORMAT_V3 = 'moto-scenes-v3';

    /** @var string the input of such a scene plan made from a script of moto-script-v3: each beat with the checkpoints it runs through, and design_states by checkpoint with its stage and label; the answer stays moto-scenes-v3 */
    public const SCENES_INPUT_V5 = 'moto-scenes-input-v5';

    /** @var string the input of such a scene plan made from a location dossier of moto-locations-v3: the fifth input whose places carry their setting, function, zones and access routes, with the connections between places and the conditions of each plate; the answer stays moto-scenes-v3 */
    public const SCENES_INPUT_V6 = 'moto-scenes-input-v6';

    /** @var string the input of such a scene plan made from a set design dossier of moto-locations-v4: the sixth input whose places carry the objects of their set, whose plates carry the state of the set as it stands for them and the conditions of their use, and whose continuity says what plates of one place keep; the answer stays moto-scenes-v3 */
    public const SCENES_INPUT_V7 = 'moto-scenes-input-v7';

    /** @var string the input of a scene plan that states the pace of each scene and the emotion it opens and ends on (MotoScenes 3.1.0): the seventh input whose story also carries its theme, spine and emotional goal, and whose places also carry their size, their coordinate system, the footprint of each zone, the clear width of each access route and the footprint and facing of each object of the set; the answer stays moto-scenes-v3 */
    public const SCENES_INPUT_V8 = 'moto-scenes-input-v8';

    public const SHOTS_INPUT = 'moto-shots-input-v4';

    public const SHOTS_FORMAT = 'moto-shots-v1';

    public const SHOTS_INPUT_V5 = 'moto-shots-input-v5';

    public const SHOTS_FORMAT_V2 = 'moto-shots-v2';

    /** @var string the input of a shot plan made within a shot budget: the fifth input with film.shot_budget */
    public const SHOTS_INPUT_V6 = 'moto-shots-input-v6';

    /** @var string the input of a shot plan made from a scene plan followed under MotoWork::LAW_V2: the sixth input with work_tracking_version, the detached parts code derived and the connection changes of the work */
    public const SHOTS_INPUT_V7 = 'moto-shots-input-v7';

    /** @var string the input of a shot plan that also carries the signature moments of the design: the seventh input with motorcycle.signature_moments, each record as the design holds it, and no confirmation of procedures */
    public const SHOTS_INPUT_V8 = 'moto-shots-input-v8';

    /** @var string the input of a shot plan that plays every event of the source within the shot budget (MotoShotsCheck::COVERAGE): the eighth input with film.coverage_policy; its answer is a MotoShots 2.3.0 document */
    public const SHOTS_INPUT_V9 = 'moto-shots-input-v9';

    /** @var string the input of a shot plan made from a design of moto-design-v3: the ninth input with each beat's checkpoints and design_states by checkpoint; its answer is a MotoShots 3.0 document */
    public const SHOTS_INPUT_V10 = 'moto-shots-input-v10';

    /** @var string the input of a shot plan made within the film limits of its release (MotoPipelineRelease::filmLimits): the tenth input with film.shot_limits, the most events one shot plays and the most people it shows; its answer stays a MotoShots 3.0 document */
    public const SHOTS_INPUT_V11 = 'moto-shots-input-v11';

    /** @var string the input of such a shot plan made from a location dossier of moto-locations-v3: the eleventh input whose places carry their setting, coordinate system, zones and access routes, with the connections between places and the conditions of each plate; its answer stays a MotoShots 3.0 document */
    public const SHOTS_INPUT_V12 = 'moto-shots-input-v12';

    /** @var string the input of such a shot plan made from a set design dossier of moto-locations-v4: the twelfth input whose places carry their architecture and objects with where they stand, and whose plates carry their camera, lighting plan, the state of the set and the continuity they keep; its answer stays a MotoShots 3.0 document */
    public const SHOTS_INPUT_V13 = 'moto-shots-input-v13';

    /** @var string the input of such a shot plan made from a scene plan that states pace and emotion: the thirteenth input whose scenes also carry their pace and the emotion they open and end on; its answer stays a MotoShots 3.0 document */
    public const SHOTS_INPUT_V14 = 'moto-shots-input-v14';

    /** @var string the answer of such a shot plan: every shot of moto-shots-v2 also pins the checkpoint its first frame shows and the one its last frame shows */
    public const SHOTS_FORMAT_V3 = 'moto-shots-v3';

    /** @var string the way of making the clip of a shot from reference images, with no keyframe */
    public const REFERENCE_TO_VIDEO = 'reference_to_video';

    /** @var int the milliseconds every shot of an eventful plan renders and keeps */
    public const SHOT_MS = 3000;

    public const REFERENCE_INPUT = 'moto-reference-input-v2';

    public const REFERENCE_FORMAT = 'moto-reference-prompt-v3';

    public const REFERENCE_PLAN_EXECUTION = 'moto-reference-plan-v2';

    public const REFERENCE_EXECUTION = 'db-moto-phase-8-v3';

    /** @var string the input a new keyframe prompt is written from: the fourth with the composition of the shot and the description of every character the script gives, whichever shape its characters have */
    public const KEYFRAME_PROMPT_INPUT = 'moto-keyframe-prompt-input-v5';

    /** @var string the input the keyframe prompts before Phase 9 revision 7 were written from; a stored run that pinned it is prepared again exactly as it was */
    public const KEYFRAME_PROMPT_INPUT_V4 = 'moto-keyframe-prompt-input-v4';

    public const KEYFRAME_PROMPT_FORMAT = 'moto-keyframe-prompt-v1';

    public const KEYFRAME_PROMPT_EXECUTION = 'db-moto-phase-9-v1';

    public const CLIP_PROMPT_INPUT = 'moto-clip-prompt-input-v3';

    public const CLIP_PROMPT_INPUT_V4 = 'moto-clip-prompt-input-v4';

    /** @var string the input of a clip prompt of the cinematic releases from phase-10 revision 16: as v4, with what the shot plan assumed for the shot, the ids the prompt must not hold, and for a person either the cell of a look or the whole identity master with the cells it holds */
    public const CLIP_PROMPT_INPUT_V5 = 'moto-clip-prompt-input-v5';

    /** @var string the input of a video prompt for a project whose shots are planned under MotoShotsCheck::COVERAGE: the fifth input with the shot's composition and event progress, the scene the shot belongs to and the events it plays as the approved script wrote them */
    public const CLIP_PROMPT_INPUT_V7 = 'moto-clip-prompt-input-v7';

    /** @var string the input of a video prompt a release names in its clip_prompt policy: the shot, its composition and event progress as the plan stored them or null, the scene it belongs to, every event of its coverage once as the approved script wrote it, and the source map as ids and paths with no copy of the values; a shot plan of any eventful schema is read by it */
    public const CLIP_PROMPT_INPUT_V8 = 'moto-clip-prompt-input-v8';

    /** @var string the input of a video prompt of a release whose design tells seven stages with checkpoints: the eighth input with design_context.build_state_end, the checkpoint the shot's end frame shows, beside build_state, the one its first frame shows */
    public const CLIP_PROMPT_INPUT_V9 = 'moto-clip-prompt-input-v9';

    /** @var string the input of a video prompt of a release that plans its places as a location dossier: the ninth input with environment, the conditions the dossier gives the plate the shot stands on — time of day, weather, atmosphere and the ambient sound of the place — as the shot run pinned them */
    public const CLIP_PROMPT_INPUT_V10 = 'moto-clip-prompt-input-v10';

    /** @var string the input of a video prompt of a release that plans its places as a set design dossier: the tenth input whose environment also carries the light of the plate in the picture — its sources, where the frame is bright and dark — and the state of the set for that plate, as the shot run pinned them */
    public const CLIP_PROMPT_INPUT_V11 = 'moto-clip-prompt-input-v11';

    public const CLIP_PROMPT_FORMAT = 'moto-clip-prompt-v1';

    public const CLIP_PROMPT_EXECUTION = 'db-moto-phase-10-v1';

    public const EVIDENCE_EXECUTION = 'db-moto-phase-1-v1';

    public const DNA_EXECUTION = 'db-moto-phase-2-v1';

    public const DESIGN_EXECUTION = 'db-moto-phase-3-v1';

    public const SCRIPT_EXECUTION = 'db-moto-phase-4-v1';

    public const LOCATIONS_EXECUTION = 'db-moto-phase-5-v1';

    public const SCENES_EXECUTION = 'db-moto-phase-6-v1';

    public const SHOTS_EXECUTION = 'db-moto-phase-7-v1';

    private const MAKE_PREFIX = '/^(?:Harley-Davidson|Harley Davidson|Harley|H-D)\s+/iu';

    public static function is(?string $workflow): bool
    {
        return $workflow === self::WORKFLOW;
    }

    /**
     * @param  array<string, mixed>  $manifest  the manifest a run pinned
     * @param  string  $schema  the key of one of its schemas
     * @return int the major version of that schema in the manifest, 1 when the manifest does not carry it
     */
    public static function generation(array $manifest, string $schema): int
    {
        foreach ((array) ($manifest['schemas'] ?? []) as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $schema) {
                return max(1, (int) strtok((string) ($row['version'] ?? '1'), '.'));
            }
        }

        return 1;
    }

    /**
     * @param  string  $content  an output schema as the pinned bundle stores it
     * @return string the schema re-encoded without whitespace between tokens
     *
     * @throws \RuntimeException when the schema is not valid JSON
     */
    public static function compactSchema(string $content): string
    {
        try {
            $schema = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Schema đầu ra không phải JSON hợp lệ: '.$e->getMessage());
        }

        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @var string the check of a moto AI answer against its pinned schema: structure only (JsonStructureSchema); v1 left out the counts of creative content alone */
    public const TECHNICAL_SCHEMA_VERSION = 'moto-technical-schema-v2';

    /**
     * The one check every moto AI answer (Phase 1 to 10) is held to: its JSON structure — types,
     * fields, required fields, extra fields, nested shapes — never what a value says. Plans code builds
     * (clip plan, film plan) are not checked through here.
     *
     * @param  list<array{json_schema_id: string, content: string, label?: string}>  $schemas  the output schemas a run pinned, as released
     * @return list<array{json_schema_id: string, content: string}> the same set, each reduced to its structure in memory; the released schemas, their hashes and the manifest are not touched
     *
     * @throws JsonStructureSchemaException when a pinned schema holds a form the structure check does not support: a fault of the check, never of the answer
     */
    public static function technicalSchemas(array $schemas): array
    {
        return array_map(
            static fn (array $schema): array => ['content' => JsonStructureSchema::content((string) $schema['content'], (string) ($schema['label'] ?? basename((string) ($schema['json_schema_id'] ?? '?'))))] + $schema,
            $schemas,
        );
    }

    /**
     * The structure errors of an answer: building the structure and running the validator both read
     * the pinned schemas, so a fault in either is a fault of the check (thrown, see structureFault()).
     *
     * @param  list<array{json_schema_id: string, content: string, label?: string}>  $schemas  the pinned schemas the answer is held to, its own first
     * @return list<string> the violations of the answer, by path
     *
     * @throws \Throwable a configuration fault of the check (structureFault() names it) or any other error
     */
    public static function structureErrors(object $decoded, array $schemas): array
    {
        return PhaseOneEvidence::schemaErrors($decoded, self::technicalSchemas($schemas));
    }

    /**
     * @param  list<array{json_schema_id?: string, label?: string}>  $schemas  the schemas the check read
     * @return ?string why the check could not run, when the error is a fault of its configuration — a schema form it does not support, a reference it cannot resolve, a schema it cannot parse — never a verdict on the answer; null for any other error
     */
    public static function structureFault(\Throwable $e, array $schemas = []): ?string
    {
        if ($e instanceof JsonStructureSchemaException) {
            return $e->getMessage();
        }

        if (! $e instanceof SchemaException) {
            return null;
        }

        $labels = implode(', ', array_map(static fn (array $schema): string => (string) ($schema['label'] ?? basename((string) ($schema['json_schema_id'] ?? '?'))), $schemas));

        return 'Bộ kiểm cấu trúc không chạy được trên schema đã ghim'.($labels === '' ? '' : " ({$labels})").': '.$e->getMessage().' — lỗi cấu hình bộ kiểm, không phải lỗi câu trả lời AI.';
    }

    /** @return list<string> the name with its spaces collapsed and, when it starts with the make, the same name without it */
    public static function sourceNameForms(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $rest = trim((string) preg_replace(self::MAKE_PREFIX, '', $name));

        return array_values(array_unique(array_filter([$name, $rest], static fn (string $form): bool => mb_strlen($form) >= 3)));
    }

    public static function mentions(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    /**
     * @param  list<string>  $names
     * @param  list<?string>  $allowed  the base model names a field may use; the make is always allowed
     * @return list<string> every form of the names, apart from a name that is the make or a base model, with or without the make before it
     */
    public static function blockedForms(array $names, array $allowed): array
    {
        $allowed = array_map(static fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name))), array_filter([...$allowed, 'Harley-Davidson', 'Harley Davidson'], 'is_string'));
        $forms = [];

        foreach ($names as $name) {
            $nameForms = self::sourceNameForms($name);

            if (array_intersect(array_map('mb_strtolower', $nameForms), $allowed) !== []) {
                continue;
            }

            foreach ($nameForms as $form) {
                $forms[$form] = true;
            }
        }

        return array_map('strval', array_keys($forms));
    }
}
