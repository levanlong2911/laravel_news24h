<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoArtifact;
use App\Models\VideoProject;
use App\Video\ModelSheet\ModelSheetMaster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SheetCropService
{
    /**
     * @param  array<string, mixed>  $layout  the layout a master was rendered on
     * @return bool whether its cells come from the lines the image draws: a layout with a fixed cell keeps its own coordinates and one picture has no lines
     */
    public static function cutsByLines(array $layout): bool
    {
        return ! isset($layout['cell']) && (int) ($layout['rows'] ?? 0) * (int) ($layout['columns'] ?? 0) > 1;
    }

    /**
     * @return ?list<array<string, int>> the boxes found on this very file and stored with it, which every later cut keeps; null when it has none
     */
    public static function pinnedBoxes(VideoArtifact $master): ?array
    {
        $stored = (array) ($master->metadata_json['parts_detail'] ?? []);

        return is_array($stored['boxes'] ?? null) && ($stored['master_sha256'] ?? null) === (string) $master->sha256 ? $stored['boxes'] : null;
    }

    /**
     * @return string the bytes of the file of the artifact
     *
     * @throws RuntimeException when the file is gone or is no longer the one stored
     */
    public function bytes(VideoArtifact $artifact): string
    {
        $disk = Storage::disk($artifact->storage_disk);

        if (! $disk->exists($artifact->storage_path)) {
            throw new RuntimeException('File ảnh không còn: '.$artifact->id);
        }

        $bytes = (string) $disk->get($artifact->storage_path);

        if ((string) $artifact->sha256 === '' || ! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Nguồn hoặc nội dung Reference đã đổi. Tải lại trang rồi làm lại từ kế hoạch hiện hành.');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $layout  the layout the master was rendered on
     * @param  array<string, mixed>  $sizes  the size of each master read so far, by artifact id
     *
     * @throws RuntimeException when the crop is not the cell of this master at its own size and place
     */
    public function verifyCrop(VideoArtifact $crop, VideoArtifact $master, array $layout, array &$sizes = []): void
    {
        $boxes = self::pinnedBoxes($master);
        $grid = new ModelSheetMaster($boxes === null ? $layout : $layout + ['boxes' => $boxes]);
        [$width, $height] = $grid->canvas();
        $masterSize = $sizes[(string) $master->id] ??= @getimagesizefromstring($this->bytes($master));
        $bytes = $this->bytes($crop);
        $cell = collect($grid->cells())->firstWhere('id', $crop->metadata_json['view_id'] ?? '');
        $size = @getimagesizefromstring($bytes);

        if ($masterSize === false || $masterSize[0] !== $width || $masterSize[1] !== $height
            || $cell === null || $size === false || $size[0] !== $cell['width'] || $size[1] !== $cell['height']
            || $crop->width !== $cell['width'] || $crop->height !== $cell['height']
            || ($crop->metadata_json['master_sha256'] ?? '') !== $master->sha256
            || ($crop->metadata_json['x'] ?? null) !== $cell['x'] || ($crop->metadata_json['y'] ?? null) !== $cell['y']) {
            throw new RuntimeException('Crop không khớp kích thước, tọa độ hoặc master.');
        }
    }

    /**
     * @param  array<string, mixed>  $layout  the layout the master was rendered on
     * @param  list<string>  $cellIds  the cells to keep; an empty cell is not cut
     * @param  string  $setId  what the crops belong to, kept on each crop
     * @param  array<string, mixed>  $processing  what is stored on the master beside the boxes
     * @return string processed, already_processed
     *
     * @throws RuntimeException when the file changed, or it has no pinned boxes and its lines do not give its cells for certain: what was stored before stays
     */
    public function cut(string $projectId, VideoArtifact $master, array $layout, array $cellIds, string $setId, string $role, array $processing): string
    {
        $grid = new ModelSheetMaster($layout);
        $decoded = $grid->decode($this->bytes($master));
        $boxes = self::pinnedBoxes($master);
        $detector = $master->metadata_json['parts_detail']['detector'] ?? null;

        if ($boxes === null && self::cutsByLines($layout)) {
            try {
                $boxes = $grid->boxesFromLines($decoded);
                $detector = ModelSheetMaster::LINE_DETECTOR;
            } catch (RuntimeException $e) {
                $meta = $master->metadata_json ?? [];
                $meta['parts_detail'] = ['status' => 'failed', 'error' => $e->getMessage(), 'detector' => ModelSheetMaster::LINE_DETECTOR] + (array) ($meta['parts_detail'] ?? []) + ['master_sha256' => (string) $master->sha256];
                $master->forceFill(['metadata_json' => $meta])->save();

                throw $e;
            }
        }

        if ($boxes !== null) {
            $grid = new ModelSheetMaster($layout + ['boxes' => $boxes]);
        }

        $crops = array_intersect_key($grid->crop($decoded), array_flip($cellIds));

        return DB::transaction(function () use ($projectId, $master, $setId, $role, $crops, $boxes, $detector, $processing): string {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $valid = function (array $crop, VideoArtifact $locked) use ($crops, $master, $setId, $role): bool {
                $expected = $crops[$crop['view_id']] ?? null;

                if ($expected === null || $locked->sha256 !== $master->sha256 || $crop['master_sha256'] !== $locked->sha256
                    || $crop['x'] !== $expected['cell']['x'] || $crop['y'] !== $expected['cell']['y'] || $crop['width'] !== $expected['cell']['width']
                    || $crop['height'] !== $expected['cell']['height'] || $crop['sha256'] !== hash('sha256', $expected['png'])) {
                    return false;
                }

                try {
                    $artifact = VideoArtifact::query()->where('project_id', $master->project_id)->where('role', $role)->findOrFail($crop['id']);

                    if (($artifact->metadata_json['parts_detail_plan_id'] ?? '') !== $setId) {
                        return false;
                    }

                    $this->bytes($artifact);

                    return true;
                } catch (RuntimeException|ModelNotFoundException) {
                    return false;
                }
            };

            return app(DesignImageStore::class)->storeSheetCrops($projectId, $master, $setId, $crops,
                $processing + ['status' => 'processed', 'master_sha256' => (string) $master->sha256] + ($boxes === null ? [] : ['boxes' => $boxes, 'detector' => $detector]),
                static function (array $existing, VideoArtifact $locked) use ($crops, $valid): bool {
                    if (count($existing) !== count($crops) || count(array_unique(array_column($existing, 'view_id'))) !== count($crops)) {
                        return false;
                    }

                    foreach ($existing as $crop) {
                        if (! $valid($crop, $locked)) {
                            return false;
                        }
                    }

                    return true;
                }, $role, $valid);
        });
    }
}
