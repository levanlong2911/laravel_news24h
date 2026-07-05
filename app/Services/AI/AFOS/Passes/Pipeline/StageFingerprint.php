<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;

/**
 * StageFingerprint — deterministic hash of a stage's inputs + metadata version.
 *
 * Used by a Cache Manager to skip re-executing a stage when its inputs and
 * version haven't changed. Two identical inputs → identical fingerprint.
 *
 * LLVM equivalent: ModuleHash / FileChecksum in the incremental compilation cache.
 *
 * Hash algorithm: SHA-1 of JSON-encoded [stage, version, {fqcn → input_hash}].
 * Input hash per IR: SHA-1 of JSON(ir->toArray()) — compact and stable.
 */
final class StageFingerprint
{
    private function __construct(
        public readonly string $stageName,
        public readonly string $stageVersion,
        public readonly string $hash,
        /** @var array<string, string> fqcn → per-input sha1 */
        public readonly array  $inputHashes,
    ) {}

    public static function of(CompilerStage $stage, PipelineState $state): self
    {
        $meta        = $stage->metadata();
        $inputHashes = [];

        foreach ($meta->reads as $fqcn) {
            $inputHashes[$fqcn] = self::hashInput($fqcn, $state);
        }

        $hash = CanonicalSerializer::hash([
            'stage'   => $meta->name,
            'version' => $meta->version,
            'inputs'  => $inputHashes,
        ]);

        return new self($meta->name, $meta->version, $hash, $inputHashes);
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    public function toArray(): array
    {
        return [
            'stage'   => $this->stageName,
            'version' => $this->stageVersion,
            'hash'    => $this->hash,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function hashInput(string $fqcn, PipelineState $state): string
    {
        $value = match ($fqcn) {
            ShotGoalIR::class            => $state->shot,
            DirectorProfile::class       => $state->director,
            CinematographyProfile::class => $state->dp,
            Intent::class                => $state->intent,
            CompositionIR::class         => $state->composition,
            CameraIR::class              => $state->camera,
            PromptIR::class              => $state->promptIR,
            DiagnosticBag::class         => null,   // bag is mutable accumulator, skip
            default                      => null,   // primitive fields ('backendId' etc.)
        };

        if ($value === null) {
            return 'null';
        }

        return CanonicalSerializer::hash(method_exists($value, 'toArray') ? $value->toArray() : (string) $value);
    }
}
