<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-RR-01  compiled_shot_hash equals the hash of a FROZEN CompiledShot
 *            (+ request_hash equals the hash of the request body, see requestHash())
 * INV-RR-02  every input hash matches the stored file; model_id is in the verified tool profile
 * INV-RR-03  duration_sec is an allowed clip length for the model; aspect_ratio and resolution are supported
 */
final class RenderRequestInvariants implements InvariantSet
{
    /** Fields that are not part of the request body and so not part of request_hash. */
    public const NON_BODY_FIELDS = ['id', 'request_hash', 'created_at', 'response_id', 'status'];

    public function schema(): string
    {
        return 'RenderRequest';
    }

    public static function requestHash(array|object $doc): string
    {
        return CanonicalJson::hash($doc, self::NON_BODY_FIELDS);
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];

        $frozen = $ctx->frozenCompiledShotHash($doc['compiled_shot_id'], (int) $doc['compiled_shot_revision']);
        if ($frozen === null) {
            $v[] = new Violation('INV-RR-01', "CompiledShot {$doc['compiled_shot_id']} rev {$doc['compiled_shot_revision']} is not FROZEN", '/compiled_shot_hash');
        } elseif ($frozen !== $doc['compiled_shot_hash']) {
            $v[] = new Violation('INV-RR-01', 'compiled_shot_hash does not match the frozen CompiledShot', '/compiled_shot_hash');
        }
        $expected = self::requestHash($doc);
        if ($doc['request_hash'] !== $expected) {
            $v[] = new Violation('INV-RR-01', "request_hash does not match the request body (expected {$expected})", '/request_hash');
        }

        foreach ($doc['inputs'] ?? [] as $i => $input) {
            $stored = $ctx->storedFileHash($input['path']);
            if ($stored === null) {
                $v[] = new Violation('INV-RR-02', "input file not found: {$input['path']}", "/inputs/{$i}/path");
            } elseif ($stored !== $input['hash']) {
                $v[] = new Violation('INV-RR-02', "input hash differs from stored file {$input['path']}", "/inputs/{$i}/hash");
            }
        }

        $profile = $ctx->toolProfile($doc['model_id']);
        if ($profile === null) {
            $v[] = new Violation('INV-RR-02', "model_id {$doc['model_id']} has no tool profile", '/model_id');

            return new ValidationReport($v);
        }
        if (empty($profile['verified'])) {
            $v[] = new Violation('INV-RR-02', "tool profile for {$doc['model_id']} is not verified (verified_at is empty)", '/model_id');
        }
        if (($profile['provider'] ?? null) !== $doc['provider']) {
            $v[] = new Violation('INV-RR-02', "provider {$doc['provider']} ≠ profile provider " . ($profile['provider'] ?? 'null'), '/provider');
        }
        if (! empty($profile['kinds']) && ! in_array($doc['kind'], $profile['kinds'], true)) {
            $v[] = new Violation('INV-RR-03', "{$doc['model_id']} does not support kind {$doc['kind']}", '/kind');
        }

        $p = $doc['parameters'];
        $durations = $profile['durations_sec'] ?? null;
        if (isset($p['duration_sec'])) {
            if ($durations === null || ! in_array((float) $p['duration_sec'], array_map('floatval', $durations), true)) {
                $v[] = new Violation('INV-RR-03', "duration_sec {$p['duration_sec']} not allowed for {$doc['model_id']}", '/parameters/duration_sec');
            }
        } elseif ($durations !== null && in_array($doc['kind'], ['video', 'video_extend'], true)) {
            $v[] = new Violation('INV-RR-03', 'duration_sec must be set explicitly for video requests', '/parameters/duration_sec');
        }
        if (! in_array($p['aspect_ratio'], $profile['aspect_ratios'] ?? [], true)) {
            $v[] = new Violation('INV-RR-03', "aspect_ratio {$p['aspect_ratio']} not supported by {$doc['model_id']}", '/parameters/aspect_ratio');
        }
        if (! in_array($p['resolution'], $profile['resolutions'] ?? [], true)) {
            $v[] = new Violation('INV-RR-03', "resolution {$p['resolution']} not supported by {$doc['model_id']}", '/parameters/resolution');
        }

        return new ValidationReport($v);
    }
}
