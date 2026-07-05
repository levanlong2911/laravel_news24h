<?php

namespace App\Services\AI\AFOS\Benchmark;

use App\Services\AI\AFOS\Compiler\CanonicalField;
use App\Services\AI\AFOS\Ir\SemanticState;

/**
 * SemanticHashPolicy — defines what constitutes semantic identity for a shot.
 *
 * DESIGN
 * ------
 * The policy holds a list of CanonicalFields. Each field knows how to extract
 * and normalise one semantic dimension from a SemanticState. The hasher iterates
 * fields, builds a key-sorted canonical map, and sha256s the JSON.
 *
 * Compiler calls policy->hash(state) — it never knows which fields go in.
 *
 * Adding a new semantic dimension (e.g. "weather"):
 *   $policy->withField(new CanonicalField('weather', fn($s) => $s->weather))
 *   Bump SCHEMA_VERSION so baselines built without "weather" are flagged.
 *
 * SHA256 (not md5): semantic identity, not security. sha256 is the standard for
 * content-addressable storage and has no practical collision risk.
 */
final class SemanticHashPolicy
{
    public const SCHEMA_VERSION = '1.0';

    /** @var CanonicalField[] */
    private readonly array $fields;

    public function __construct(?array $fields = null)
    {
        $this->fields = $fields ?? self::defaultFields();
    }

    /** @return CanonicalField[] */
    private static function defaultFields(): array
    {
        return [
            new CanonicalField('entity_id',           fn(SemanticState $s) => $s->entityId),
            new CanonicalField('goal_type',           fn(SemanticState $s) => $s->goalType),
            new CanonicalField('emotion',             fn(SemanticState $s) => $s->emotion),
            new CanonicalField('narrative_function',  fn(SemanticState $s) => $s->narrativeFunction),
            new CanonicalField('camera_movement',     fn(SemanticState $s) => $s->cameraMovement),
            new CanonicalField('camera_start_height', fn(SemanticState $s) => $s->cameraStartHeight),
            new CanonicalField('focal_length_mm',     fn(SemanticState $s) => $s->focalLengthMm),
            new CanonicalField('framing',             fn(SemanticState $s) => $s->framing),
            new CanonicalField('composition_rule',    fn(SemanticState $s) => $s->compositionRule),
            new CanonicalField('negative_space_dir',  fn(SemanticState $s) => $s->negativeSpaceDir),
            new CanonicalField('tempo',               fn(SemanticState $s) => $s->tempo),
        ];
    }

    public function hash(SemanticState $state): string
    {
        $canonical = [];
        foreach ($this->fields as $field) {
            $canonical[$field->key] = $field->extract($state);
        }
        ksort($canonical);
        return hash('sha256', json_encode($canonical));
    }

    /**
     * Return a new policy with an additional canonical field.
     * Used to extend the schema without mutating the default policy.
     *
     * @example
     *   $policy = (new SemanticHashPolicy())->withField(
     *       new CanonicalField('weather', fn($s) => $s->weather)
     *   );
     */
    public function withField(CanonicalField $field): self
    {
        return new self([...$this->fields, $field]);
    }

    /** @return string[] canonical key names */
    public function fields(): array
    {
        return array_map(fn(CanonicalField $f) => $f->key, $this->fields);
    }

    public function isCompatible(string $otherSchemaVersion): bool
    {
        return $otherSchemaVersion === self::SCHEMA_VERSION;
    }
}
