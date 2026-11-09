<?php

declare(strict_types=1);

namespace App\Video\PartsDetail;

use App\Video\Moto\MotoShots;

/** Existing approved images are references, not the shot's final camera frame. */
final class PartsDetailCoverage
{
    public static function modelSheet(array $shots, array $design, array $finished, array $images): array
    {
        $result = [];
        foreach ($shots as $shot) {
            if (! in_array($shot['state'] ?? null, $finished, true)
                || ! empty($shot['continuity_start']['surface_condition'])) {
                continue;
            }
            foreach ($shot['reference_needs'] ?? [] as $need) {
                if (($need['kind'] ?? null) !== 'design') {
                    continue;
                }
                $target = $need['target'];
                $part = array_values(array_filter($shot['parts'] ?? [], fn ($p) => ($p['part'] ?? null) === $target))[0] ?? null;
                $held = $shot['held_parts'][$target] ?? null;
                $configured = array_filter($shot['configurations']['start'] ?? [], fn ($id) => is_string($id) && str_starts_with($id, $target.'-'));
                if (($part['presentation'] ?? null) !== 'installed' || $configured !== []
                    || ($held !== null && (($held['location'] ?? '') !== 'on_motorcycle' || ($held['status'] ?? '') !== 'installed'))) {
                    continue;
                }
                $available = [];
                foreach ($design['model_sheet']['views'] ?? [] as $view) {
                    // A partly visible or uncertain part is never automatically considered covered.
                    if (isset($images[$view['id']]) && in_array($target, $view['visible'] ?? [], true)) {
                        $available[] = $view['id'];
                    }
                }
                $view = MotoShots::nearestView($shot['camera'], $available);
                // The source reference can be another view of the same documented part.
                $view ??= $available[0] ?? null;
                if ($view !== null) {
                    $result[PartsDetailPlan::needKey($shot, $need)] = ['source' => $view, 'artifact_id' => $images[$view]];
                }
            }
        }
        return $result;
    }
}
