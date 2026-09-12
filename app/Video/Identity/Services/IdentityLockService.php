<?php

declare(strict_types=1);

namespace App\Video\Identity\Services;

use App\Video\Identity\Enums\IdentityLockStatus;
use App\Video\Identity\Enums\ReferencePackStatus;
use App\Video\Identity\Models\IdentityLock;
use App\Video\Identity\Models\ReferencePack;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IdentityLockService
{
    public function freeze(
        ReferencePack $pack,
        string $manifestJson,
        string $hashPayloadJson,
        string $identityLockHash,
        string $frozenBy,
    ): IdentityLock {
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('Identity lock manifest must be object.');
        }

        if (($manifest['identity_lock_hash'] ?? null) !== $identityLockHash) {
            throw new RuntimeException('Identity lock hash does not match manifest.');
        }

        if (! hash_equals(hash('sha256', $hashPayloadJson), $identityLockHash)) {
            throw new RuntimeException('Identity lock hash does not match hash payload.');
        }

        return DB::transaction(function () use (
            $pack,
            $manifest,
            $manifestJson,
            $hashPayloadJson,
            $identityLockHash,
            $frozenBy,
        ): IdentityLock {
            $pack = ReferencePack::query()
                ->with('approvedAnchor')
                ->whereKey($pack->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pack->status !== ReferencePackStatus::APPROVED) {
                throw new RuntimeException('Reference pack must be approved before identity lock.');
            }

            $version = ((int) IdentityLock::query()
                ->where('video_project_id', $pack->video_project_id)
                ->max('identity_lock_version')) + 1;

            IdentityLock::query()
                ->where('video_project_id', $pack->video_project_id)
                ->where('status', IdentityLockStatus::FROZEN)
                ->update([
                    'status' => IdentityLockStatus::SUPERSEDED->value,
                    'superseded_at' => now(),
                ]);

            return IdentityLock::query()->create([
                'video_project_id' => $pack->video_project_id,
                'canonical_concept_revision_id' => $pack->canonical_concept_revision_id,
                'canonical_hash' => $pack->canonical_hash,
                'approved_anchor_id' => $pack->approved_anchor_id,
                'reference_pack_id' => $pack->id,
                'identity_lock_version' => $version,
                'manifest_json' => $manifest,
                'hash_payload_json' => $hashPayloadJson,
                'identity_lock_hash' => $identityLockHash,
                'status' => IdentityLockStatus::FROZEN,
                'frozen_by' => $frozenBy,
                'frozen_at' => now(),
                'metadata_json' => null,
            ]);
        }, attempts: 3);
    }
}
