<?php

namespace App\Video\Superyacht\Validation;

use App\Video\Superyacht\Support\BuildStates;
use App\Video\Superyacht\Validation\Invariants\BuildStateInvariants;
use App\Video\Superyacht\Validation\Invariants\CanonicalDesignInvariants;
use App\Video\Superyacht\Validation\Invariants\CompiledShotInvariants;
use App\Video\Superyacht\Validation\Invariants\ConstraintInvariants;
use App\Video\Superyacht\Validation\Invariants\InvariantContext;
use App\Video\Superyacht\Validation\Invariants\InvariantSet;
use App\Video\Superyacht\Validation\Invariants\LockRecordInvariants;
use App\Video\Superyacht\Validation\Invariants\RenderRequestInvariants;
use App\Video\Superyacht\Validation\Invariants\ReviewDecisionInvariants;
use App\Video\Superyacht\Validation\Invariants\ShotSpecInvariants;
use InvalidArgumentException;

/**
 * Two-step validation of one document:
 *  1. JSON Schema (structure, types, enums, simple if/then rules);
 *  2. the 22 cross-field invariants (only when step 1 passes).
 */
final class DocumentValidator
{
    /** @var array<string,InvariantSet> */
    private array $sets = [];

    public function __construct(private readonly SchemaValidator $schemas, InvariantSet ...$sets)
    {
        foreach ($sets as $set) {
            $this->sets[$set->schema()] = $set;
        }
    }

    public static function fromConfig(array $config): self
    {
        $states = BuildStates::fromConfig($config);
        $compiler = StateGeometryCompiler::fromConfig($config);
        $bs = new BuildStateInvariants($compiler, $config['removable_parts']);

        return new self(
            SchemaValidator::fromConfig($config),
            $bs,
            new CanonicalDesignInvariants($bs, $config['features']),
            new ConstraintInvariants($states),
            new ShotSpecInvariants($config['hero_beat'], (float) $config['cut_grid_tolerance']),
            new CompiledShotInvariants($states, $compiler),
            new RenderRequestInvariants(),
            new LockRecordInvariants(),
            new ReviewDecisionInvariants($config['review_decisions'], $config['human_only_review_reasons']),
        );
    }

    /** @param string $json raw JSON document */
    public function validate(string $schemaName, string $json, InvariantContext $ctx): ValidationReport
    {
        $report = $this->schemas->validate($schemaName, $json);
        if ($report->fails()) {
            return $report;
        }

        $set = $this->sets[$schemaName] ?? throw new InvalidArgumentException("No invariant set for {$schemaName}");

        return $set->check(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $ctx);
    }

    public function schemas(): SchemaValidator
    {
        return $this->schemas;
    }
}
