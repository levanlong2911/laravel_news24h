<?php

declare(strict_types=1);

namespace App\Video\PartsDetail;

use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

/** Deterministic inventory: a renderer may describe facts, never add or drop needs. */
final class PartsDetailPlan
{
    public const VERSION = 'moto-parts-detail-v1';

    public static function needKey(array $shot, array $need): string
    {
        return CanonicalJson::hash(['shot' => array_intersect_key($shot, array_flip(['id', 'state', 'parts', 'held_parts', 'configurations', 'camera', 'first_frame', 'work_items', 'prompts'])), 'need' => $need]);
    }

    public static function build(array $shots, array $design): array
    {
        $parts = array_column($design['parts'] ?? [], null, 'id');
        $states = array_column($design['build_states'] ?? [], null, 'id');
        $cells = [];
        $needs = [];
        $groups = [];
        foreach ($shots as $shot) {
            $frameCell = null;
            foreach ($shot['reference_needs'] ?? [] as $need) {
                if (($need['kind'] ?? null) !== 'design') {
                    continue;
                }
                $target = $need['target'] ?? '';
                if (! isset($parts[$target], $states[$shot['state'] ?? ''])) {
                    throw new RuntimeException("{$shot['id']}: thiếu part hoặc build state trong thiết kế đã duyệt.");
                }
                // Exact state and view, including first-frame work placement. Conservative sharing.
                $facts = [
                    'part' => $parts[$target], 'state' => $states[$shot['state']],
                    'presentation' => array_values(array_filter($shot['parts'] ?? [], fn ($p) => ($p['part'] ?? null) === $target)),
                    'held' => $shot['held_parts'][$target] ?? null,
                    'configurations' => $shot['configurations'] ?? $shot['configuration'] ?? [],
                    'camera' => $shot['camera'], 'need' => $need,
                    'first_frame' => $shot['first_frame'] ?? null,
                    'work' => $shot['work_items'] ?? [],
                    'motorcycle' => $design['motorcycle'] ?? [],
                    'continuity' => $shot['continuity_start'] ?? null,
                    'shot_prompt' => $shot['prompts']['image'] ?? '',
                    'connections' => array_values(array_filter($design['connections'] ?? [], fn ($c) => in_array($target, (array) ($c['between'] ?? []), true))),
                ];
                $key = CanonicalJson::hash($facts);
                $inFrame = in_array($target, array_column($shot['parts'] ?? [], 'part'), true);
                if ($frameCell !== null && $inFrame) {
                    $id = $frameCell;
                    // One reference of the documented first frame can serve its related parts.
                    // Keep each part's own material, placement and request; do not multiply images.
                    $cells[$id]['facts']['related_requirements'][] = array_intersect_key($facts, array_flip(['part', 'presentation', 'held', 'need', 'connections']));
                } elseif (isset($groups[$key])) {
                    $id = $groups[$key];
                } else {
                    $id = sprintf('DT%03d', count($cells) + 1);
                    $groups[$key] = $id;
                    $cells[$id] = ['id' => $id, 'part_id' => $target, 'state_id' => $shot['state'], 'facts' => $facts, 'needs' => []];
                }
                if ($inFrame && $frameCell === null) {
                    $frameCell = $id;
                }
                $needId = self::needKey($shot, $need);
                $cells[$id]['needs'][] = $needId;
                $needs[] = ['id' => $needId, 'shot_id' => $shot['id'], 'cell_id' => $id, 'request' => $need, 'prompt' => $shot['prompts']['image'] ?? ''];
            }
        }
        return ['version' => self::VERSION, 'needs' => $needs, 'cells' => array_values($cells), 'sheets' => self::sheets(array_values($cells))];
    }

    /** Compose only the uncovered cells; cell identities remain those of the full inventory. */
    public static function sheets(array $selected): array
    {
        $cells = array_column($selected, null, 'id');
        $motorcycle = $selected[0]['facts']['motorcycle'] ?? [];
        $sheets = [];
        $pending = array_keys($cells);
        $batchSize = 4;
        while ($pending !== []) {
            $ids = array_splice($pending, 0, $batchSize);
            $order = $ids;
            while (count($order) < 4) {
                $order[] = 'EMPTY'.count($order);
            }
            $layout = ['size' => '3840x2160', 'columns' => 2, 'rows' => 2, 'cell' => ['width' => 1920, 'height' => 1080], 'order' => $order];
            $grid = new ModelSheetMaster($layout);
            $sheetCells = array_values(array_intersect_key($cells, array_flip($ids)));
            foreach ($sheetCells as &$sheetCell) {
                // Keep the exact film prompt in the inventory/UI, but do not repeat its
                // scenery and all-part descriptions inside every reference render cell.
                unset($sheetCell['facts']['motorcycle'], $sheetCell['facts']['shot_prompt']);
            }
            unset($sheetCell);
            $template = file_get_contents(base_path('database/releases/moto-parts-detail-v1/parts-detail.md'));
            if ($template === false) {
                throw new RuntimeException('Thiếu prompt PARTS DETAIL.');
            }
            $identity = array_intersect_key($motorcycle, array_flip(['name', 'coordinate_system', 'palette', 'materials', 'symmetry']));
            $prompt = $template."\n\nCommon approved identity:\n".json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                ."\n\nPinned grid (row-major; pixel coordinates):\n".json_encode($grid->cells(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                ."\n\nApproved facts for each occupied cell:\n".json_encode($sheetCells, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (mb_strlen($prompt) > \App\Services\Video\OpenAiImageClient::MAX_PROMPT_CHARS) {
                if (count($ids) === 1) {
                    throw new RuntimeException('Một ô chi tiết vượt giới hạn prompt render; cần thu gọn dữ kiện trước khi lập bộ.');
                }
                array_unshift($pending, ...$ids);
                $batchSize = count($ids) - 1;
                continue;
            }
            $sheets[] = ['id' => 'PD'.(count($sheets) + 1), 'cell_ids' => $ids, 'layout' => $layout, 'layout_sha256' => $grid->layoutSha256(), 'prompt' => $prompt];
            $batchSize = 4;
        }
        return $sheets;
    }
}
