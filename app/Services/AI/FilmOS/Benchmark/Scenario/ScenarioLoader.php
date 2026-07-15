<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;

/**
 * Parses a scenario JSON file into a validated ScenarioDocument, or throws
 * ScenarioSchemaException naming the exact rule broken.
 *
 * Per-FILE authority: enum resolvability (rule 4), rule 8 (schema_version tracks
 * fields used), and referential integrity (focus_node → scene node,
 * world_object_ref → world object, emotion/performance character → character).
 * Catalog-level invariants (uniqueness, suite/difficulty distribution) belong
 * to ScenarioCatalogTest, not here.
 */
final class ScenarioLoader
{
    private readonly string $dir;

    public function __construct(?string $scenariosDir = null)
    {
        $this->dir = rtrim(
            $scenariosDir ?? __DIR__ . '/../../../../../../resources/filmos/benchmark/scenarios',
            '/\\',
        );
    }

    public function fromId(string $id): ScenarioDocument
    {
        return $this->fromFile("{$this->dir}/{$id}.json");
    }

    public function fromFile(string $path): ScenarioDocument
    {
        $id = basename($path, '.json');

        if (!is_file($path)) {
            throw ScenarioSchemaException::for($id, "file not found at {$path}");
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ScenarioSchemaException::for($id, "invalid JSON — {$e->getMessage()}");
        }

        if (!is_array($data)) {
            throw ScenarioSchemaException::for($id, 'root must be a JSON object');
        }

        return $this->build($id, $data);
    }

    /** @param array<string, mixed> $d */
    private function build(string $id, array $d): ScenarioDocument
    {
        // Identity: id field must equal filename.
        if (($d['id'] ?? null) !== $id) {
            throw ScenarioSchemaException::for($id, "'id' field must equal the filename (got " . var_export($d['id'] ?? null, true) . ')');
        }

        $suite      = $this->enum(Suite::class, $d['suite'] ?? null, $id, 'suite');
        $difficulty = $this->enum(Difficulty::class, $d['difficulty'] ?? null, $id, 'difficulty');

        foreach (['level', 'primary_learning_dimension', 'goal'] as $key) {
            if (!is_string($d[$key] ?? null) || $d[$key] === '') {
                throw ScenarioSchemaException::for($id, "'{$key}' must be a non-empty string");
            }
        }

        $shots = $d['shots'] ?? [];
        if (!is_array($shots) || $shots === []) {
            throw ScenarioSchemaException::for($id, "'shots' must be a non-empty object keyed by beat");
        }

        // Rule 8: schema_version tracks the fields the file actually uses.
        $version = $d['schema_version'] ?? null;
        if (!is_int($version)) {
            throw ScenarioSchemaException::for($id, "'schema_version' must be an integer");
        }
        $hasV2 = array_key_exists('production', $d) || array_key_exists('performance', $d);
        $expected = $hasV2 ? 2 : 1;
        if ($version !== $expected) {
            throw ScenarioSchemaException::for(
                $id,
                "schema_version must be {$expected} (rule 8: " . ($hasV2 ? 'file uses v2 fields' : 'file uses only v1 fields') . "), got {$version}",
            );
        }

        // World objects → id set.
        $worldObjectIds = $this->validateWorldObjects($id, $d['world_objects'] ?? []);

        // Characters → id set.
        $characterIds = $this->validateCharacters($id, $d['characters'] ?? [], $worldObjectIds);

        // Scene nodes: beat → set of node ids (needed to validate focus_node).
        $sceneNodes    = $d['scene_nodes'] ?? [];
        $nodeIdsByBeat = $this->validateSceneNodes($id, $sceneNodes, $worldObjectIds);

        $this->validateShots($id, $shots, $nodeIdsByBeat);
        $this->validateEmotionArc($id, $d['emotion_arc'] ?? [], $characterIds);

        $production = null;
        if (array_key_exists('production', $d)) {
            $production = $this->validateProduction($id, $d['production'], $shots);
        }

        $performance = null;
        if (array_key_exists('performance', $d)) {
            $performance = $this->validatePerformance($id, $d['performance'], $characterIds);
        }

        return new ScenarioDocument(
            schemaVersion:               $version,
            id:                          $id,
            suite:                       $suite,
            level:                       (string) $d['level'],
            difficulty:                  $difficulty,
            durationSeconds:             (int) ($d['duration_seconds'] ?? 0),
            primaryLearningDimension:    (string) $d['primary_learning_dimension'],
            secondaryLearningDimensions: array_values((array) ($d['secondary_learning_dimensions'] ?? [])),
            stressDimensions:            array_values((array) ($d['stress_dimensions'] ?? [])),
            goal:                        (string) $d['goal'],
            facts:                       array_values((array) ($d['facts'] ?? [])),
            worldObjects:                array_values((array) ($d['world_objects'] ?? [])),
            worldFacts:                  (array) ($d['world_facts'] ?? []),
            characters:                  array_values((array) ($d['characters'] ?? [])),
            emotionArc:                  (array) ($d['emotion_arc'] ?? []),
            shots:                       $shots,
            sceneNodes:                  (array) $sceneNodes,
            production:                  $production,
            performance:                 $performance,
        );
    }

    /** @return array<string, true> world object ids */
    private function validateWorldObjects(string $id, mixed $objects): array
    {
        if (!is_array($objects)) {
            throw ScenarioSchemaException::for($id, "'world_objects' must be an array");
        }
        $ids = [];
        foreach ($objects as $obj) {
            if (!is_array($obj) || !is_string($obj['id'] ?? null)) {
                throw ScenarioSchemaException::for($id, 'each world object needs a string id');
            }
            $this->enum(WorldObjectType::class, $obj['type'] ?? null, $id, "world_objects.{$obj['id']}.type");
            $ids[$obj['id']] = true;
        }
        return $ids;
    }

    /**
     * @param array<string, true> $worldObjectIds
     * @return array<string, true> character ids
     */
    private function validateCharacters(string $id, mixed $characters, array $worldObjectIds): array
    {
        if (!is_array($characters)) {
            throw ScenarioSchemaException::for($id, "'characters' must be an array");
        }
        $ids = [];
        foreach ($characters as $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null)) {
                throw ScenarioSchemaException::for($id, 'each character needs a string id');
            }
            $ref = $c['world_object_ref'] ?? null;
            if ($ref !== null && !isset($worldObjectIds[$ref])) {
                throw ScenarioSchemaException::for($id, "character '{$c['id']}' references unknown world_object_ref '{$ref}'");
            }
            $ids[$c['id']] = true;
        }
        return $ids;
    }

    /**
     * @param array<string, true> $worldObjectIds
     * @return array<string, array<string, true>> beat => node id set
     */
    private function validateSceneNodes(string $id, mixed $sceneNodes, array $worldObjectIds): array
    {
        if (!is_array($sceneNodes)) {
            throw ScenarioSchemaException::for($id, "'scene_nodes' must be an object keyed by beat");
        }
        $byBeat = [];
        foreach ($sceneNodes as $beat => $nodes) {
            $this->beat($id, (string) $beat, 'scene_nodes');
            if (!is_array($nodes)) {
                throw ScenarioSchemaException::for($id, "scene_nodes.{$beat} must be an array");
            }
            $set = [];
            foreach ($nodes as $node) {
                if (!is_array($node) || !is_string($node['id'] ?? null)) {
                    throw ScenarioSchemaException::for($id, "each scene node in '{$beat}' needs a string id");
                }
                $this->enum(SceneNodeType::class, $node['type'] ?? null, $id, "scene_nodes.{$beat}.{$node['id']}.type");
                $ref = $node['world_object_ref'] ?? null;
                if ($ref !== null && !isset($worldObjectIds[$ref])) {
                    throw ScenarioSchemaException::for($id, "scene node '{$node['id']}' references unknown world_object_ref '{$ref}'");
                }
                $set[$node['id']] = true;
            }
            $byBeat[(string) $beat] = $set;
        }
        return $byBeat;
    }

    /**
     * @param array<string, mixed>              $shots
     * @param array<string, array<string,true>> $nodeIdsByBeat
     */
    private function validateShots(string $id, array $shots, array $nodeIdsByBeat): void
    {
        foreach ($shots as $beat => $shot) {
            $this->beat($id, (string) $beat, 'shots');
            if (!is_array($shot)) {
                throw ScenarioSchemaException::for($id, "shots.{$beat} must be an object");
            }
            $importance = $shot['importance'] ?? null;
            if (!in_array($importance, ['required', 'optional'], true)) {
                throw ScenarioSchemaException::for($id, "shots.{$beat}.importance must be 'required' or 'optional'");
            }
            if (!is_string($shot['action'] ?? null) || $shot['action'] === '') {
                throw ScenarioSchemaException::for($id, "shots.{$beat}.action must be a non-empty string");
            }
            $cam = $shot['camera'] ?? null;
            if (!is_array($cam)) {
                throw ScenarioSchemaException::for($id, "shots.{$beat}.camera is required");
            }
            $this->enum(ShotType::class,       $cam['shot_type'] ?? null, $id, "shots.{$beat}.camera.shot_type");
            $this->enum(CameraAngle::class,    $cam['angle'] ?? null,     $id, "shots.{$beat}.camera.angle");
            $this->enum(CameraMovement::class, $cam['movement'] ?? null,  $id, "shots.{$beat}.camera.movement");
            $this->enum(LensType::class,       $cam['lens'] ?? null,      $id, "shots.{$beat}.camera.lens");

            $focus = $cam['focus_node'] ?? null;
            if ($focus !== null && !isset($nodeIdsByBeat[(string) $beat][$focus])) {
                throw ScenarioSchemaException::for($id, "shots.{$beat}.camera.focus_node '{$focus}' is not a scene node of beat '{$beat}'");
            }
        }
    }

    /** @param array<string, true> $characterIds */
    private function validateEmotionArc(string $id, mixed $arc, array $characterIds): void
    {
        if (!is_array($arc)) {
            throw ScenarioSchemaException::for($id, "'emotion_arc' must be an object keyed by character id");
        }
        foreach ($arc as $charId => $entries) {
            if (!isset($characterIds[$charId])) {
                throw ScenarioSchemaException::for($id, "emotion_arc references unknown character '{$charId}'");
            }
            if (!is_array($entries)) {
                throw ScenarioSchemaException::for($id, "emotion_arc.{$charId} must be an array");
            }
            foreach ($entries as $e) {
                $at = $e['at'] ?? null;
                if ($at !== 'baseline') {
                    $this->beat($id, (string) $at, "emotion_arc.{$charId}.at");
                }
                $this->enum(EmotionalState::class,   $e['state'] ?? null,     $id, "emotion_arc.{$charId}.state");
                $this->enum(EmotionIntensity::class, $e['intensity'] ?? null, $id, "emotion_arc.{$charId}.intensity");
            }
        }
    }

    /**
     * @param array<string, mixed> $shots
     * @return array<string, mixed> validated production section
     */
    private function validateProduction(string $id, mixed $production, array $shots): array
    {
        if (!is_array($production)) {
            throw ScenarioSchemaException::for($id, "'production' must be an object");
        }
        foreach ((array) ($production['conflicts'] ?? []) as $c) {
            $this->enum(ConflictType::class, $c['type'] ?? null, $id, 'production.conflicts[].type');
        }
        foreach ((array) ($production['motifs'] ?? []) as $m) {
            $this->enum(MotifImportance::class, $m['importance'] ?? null, $id, 'production.motifs[].importance');
        }
        foreach ((array) ($production['constraints'] ?? []) as $con) {
            $this->enum(ConstraintMode::class, $con['mode'] ?? null, $id, 'production.constraints[].mode');
        }
        if (isset($production['hero_moment'])) {
            $at = $production['hero_moment']['at'] ?? null;
            $this->beat($id, (string) $at, 'production.hero_moment.at');
            if (!array_key_exists((string) $at, $shots)) {
                throw ScenarioSchemaException::for($id, "production.hero_moment.at '{$at}' is not a beat present in shots");
            }
        }
        foreach ((array) ($production['energy_curve'] ?? []) as $p) {
            $this->beat($id, (string) ($p['at'] ?? null), 'production.energy_curve[].at');
            $v = $p['value'] ?? null;
            if (!is_int($v) || $v < 0 || $v > 100) {
                throw ScenarioSchemaException::for($id, 'production.energy_curve[].value must be an integer 0–100');
            }
        }
        foreach ((array) ($production['timings'] ?? []) as $t) {
            $this->beat($id, (string) ($t['at'] ?? null), 'production.timings[].at');
        }
        return $production;
    }

    /**
     * @param array<string, true> $characterIds
     * @return array<string, mixed> validated performance section
     */
    private function validatePerformance(string $id, mixed $performance, array $characterIds): array
    {
        if (!is_array($performance)) {
            throw ScenarioSchemaException::for($id, "'performance' must be an object keyed by beat");
        }
        foreach ($performance as $beat => $byCharacter) {
            $this->beat($id, (string) $beat, 'performance');
            if (!is_array($byCharacter)) {
                throw ScenarioSchemaException::for($id, "performance.{$beat} must be an object keyed by character id");
            }
            foreach ($byCharacter as $charId => $dir) {
                if (!isset($characterIds[$charId])) {
                    throw ScenarioSchemaException::for($id, "performance.{$beat} references unknown character '{$charId}'");
                }
                foreach ((array) ($dir['cues'] ?? []) as $cue) {
                    $channel = $cue['channel'] ?? null;
                    if ($channel !== null) {
                        $this->enum(PerformanceChannel::class, $channel, $id, "performance.{$beat}.{$charId}.cues[].channel");
                    }
                }
            }
        }
        return $performance;
    }

    private function beat(string $id, string $value, string $field): void
    {
        if (StoryBeat::tryFrom($value) === null) {
            throw ScenarioSchemaException::for($id, "{$field} '{$value}' is not a StoryBeat (hook|escalation|reveal|payoff)");
        }
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     */
    private function enum(string $enumClass, mixed $value, string $id, string $field): \BackedEnum
    {
        if (!is_string($value) || ($case = $enumClass::tryFrom($value)) === null) {
            $allowed = implode('|', array_map(fn(\BackedEnum $c) => $c->value, $enumClass::cases()));
            throw ScenarioSchemaException::for($id, "{$field} " . var_export($value, true) . " is not valid ({$allowed})");
        }
        return $case;
    }
}
