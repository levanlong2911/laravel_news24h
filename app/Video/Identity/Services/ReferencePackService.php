<?php

declare(strict_types=1);

namespace App\Video\Identity\Services;

use App\Video\Identity\Enums\ReferenceAssetStatus;
use App\Video\Identity\Enums\ReferencePackStatus;
use App\Video\Identity\Models\ApprovedAnchor;
use App\Video\Identity\Models\ReferencePack;
use App\Video\Identity\Models\ReferencePackQaRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReferencePackService
{
    /** @param array<string, mixed> $pack */
    public function freezePlan(ApprovedAnchor $anchor, array $pack): ReferencePack
    {
        $canonical = json_encode(
            $pack,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $packHash = hash('sha256', $canonical);

        return DB::transaction(function () use ($anchor, $pack, $packHash): ReferencePack {
            $referencePack = ReferencePack::query()->firstOrCreate(
                ['pack_hash' => $packHash],
                [
                    'video_project_id' => $anchor->video_project_id,
                    'approved_anchor_id' => $anchor->id,
                    'canonical_concept_revision_id' => $anchor->canonical_concept_revision_id,
                    'canonical_hash' => $anchor->canonical_hash,
                    'pack_version' => (string) ($pack['version'] ?? 'reference-pack-plan-v1'),
                    'pack_json' => $pack,
                    'status' => ReferencePackStatus::PLANNED,
                    'metadata_json' => null,
                ],
            );

            foreach (($pack['views'] ?? []) as $view) {
                $referencePack->assets()->firstOrCreate(
                    ['view_id' => (string) $view['view_id']],
                    [
                        'role' => (string) $view['role'],
                        'order' => (int) $view['order'],
                        'required' => (bool) ($view['required'] ?? true),
                        'camera_json' => $view['camera'] ?? [],
                        'additional_required_paths' => $view['additional_required_paths'] ?? [],
                        'status' => ReferenceAssetStatus::PLANNED,
                        'metadata_json' => $view['metadata'] ?? null,
                    ],
                );
            }

            return $referencePack->refresh();
        }, attempts: 3);
    }

    public function readyForMultiViewQa(ReferencePack $pack): bool
    {
        $pack->loadMissing('assets');

        if ($pack->assets->isEmpty()) {
            return false;
        }

        foreach ($pack->assets as $asset) {
            if ($asset->required && $asset->status !== ReferenceAssetStatus::PASSED) {
                return false;
            }
        }

        return true;
    }

    public function approve(ReferencePack $pack, string $approvedBy, ?ReferencePackQaRun $qaRun = null): ReferencePack
    {
        return DB::transaction(function () use ($pack, $approvedBy, $qaRun): ReferencePack {
            $pack = ReferencePack::query()
                ->with('assets')
                ->whereKey($pack->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->readyForMultiViewQa($pack)) {
                throw new RuntimeException('Reference pack is not ready for approval.');
            }

            if ($qaRun !== null && $qaRun->reference_pack_id !== $pack->id) {
                throw new RuntimeException('Reference pack QA run does not belong to pack.');
            }

            $pack->forceFill([
                'status' => ReferencePackStatus::APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'latest_qa_run_id' => $qaRun?->id ?? $pack->latest_qa_run_id,
            ])->save();

            return $pack->refresh();
        }, attempts: 3);
    }
}
