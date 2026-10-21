<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-LK-01  bundle_hash = SHA-256 of the sorted component hashes; every component revision exists
 */
final class LockRecordInvariants implements InvariantSet
{
    public function schema(): string
    {
        return 'LockRecord';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];
        $components = $doc['components'] ?? [];

        $expected = CanonicalJson::bundleHash(array_column($components, 'hash'));
        if ($doc['bundle_hash'] !== $expected) {
            $v[] = new Violation('INV-LK-01', "bundle_hash does not match the sorted component hashes (expected {$expected})", '/bundle_hash');
        }

        foreach ($components as $i => $c) {
            if (! $ctx->artifactRevisionExists($c['artifact_id'], (int) $c['revision'], $c['hash'])) {
                $v[] = new Violation('INV-LK-01', "component {$c['artifact_id']} rev {$c['revision']} does not exist with that hash", "/components/{$i}");
            }
        }

        return new ValidationReport($v);
    }
}
