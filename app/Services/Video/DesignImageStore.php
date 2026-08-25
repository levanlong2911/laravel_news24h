<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageQuality;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DesignImageStore
{
    private const CODE_PREFIX = 'master_vessel_';

    private const CODE_SUFFIX = '_anchor_v';

    private const CODE_MAX = 100;

    private const IDENTITY_KEYS = ['prompt', 'model', 'quality', 'size'];

    private const ANCHOR_TYPE = 'identity_anchor';

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found
     */
    public function createCandidate(string $projectId, string $creator, array $spec): array
    {
        $sha = $this->identityHash($spec);

        try {
            return DB::transaction(function () use ($projectId, $creator, $spec, $sha) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $existing = $this->findByHash($projectId, $sha);

                if ($existing !== null) {
                    return [$existing, 'already_exists'];
                }

                return [
                    VideoDesignImage::create([
                        'project_id' => $projectId,
                        'image_code' => $this->nextImageCode($projectId, $creator),
                        'image_type' => self::ANCHOR_TYPE,
                        'prompt_spec_json' => $spec,
                        'prompt_sha256' => $sha,
                        'status' => 'candidate',
                        'revision' => 1,
                    ]),
                    'created',
                ];
            });
        } catch (ModelNotFoundException) {
            return [null, 'project_not_found'];
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByHash($projectId, $sha);

            if ($existing === null) {
                throw $e;
            }

            return [$existing, 'already_exists'];
        }
    }

    /**
     * O anh neo cua du an, moi nhat truoc, kem ung vien da render.
     *
     * Hien TAT CA o chu khong chi o moi nhat: doi quality hay size la sinh
     * mot o moi, va mot o da tieu tien ma bi giau khoi man hinh la kieu hong te
     * nhat — no van nam trong so cai, chi la khong ai nhin thay.
     *
     * `cost_recorded` doc tu `video_cost_entries`, KHAC `cost_estimate`: uoc
     * luong la thu ta noi truoc khi tra tien, so cai la thu da xay ra.
     *
     * @return list<array<string, mixed>>
     */
    public function anchorCellsFor(string $projectId): array
    {
        $spent = VideoCostEntry::query()
            ->where('project_id', $projectId)
            ->where('entity_type', 'design_image')
            ->selectRaw('entity_id, SUM(cost_usd) as total')
            ->groupBy('entity_id')
            ->pluck('total', 'entity_id');

        return VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::ANCHOR_TYPE)
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->latest('created_at')
            ->get()
            ->map(fn (VideoDesignImage $image) => $this->cellView($image, (float) ($spent[$image->id] ?? 0)))
            ->all();
    }

    /** @return array<string, mixed> */
    private function cellView(VideoDesignImage $image, float $recorded): array
    {
        $spec = $image->prompt_spec_json ?? [];
        $variations = max(1, (int) ($spec['variations'] ?? 1));
        $unit = ImageQuality::fromSpecOrHigh($spec['quality'] ?? '')->estimatedCostUsd();

        return [
            'id' => $image->id,
            'image_code' => $image->image_code,
            'status' => $image->status,
            'status_label' => DesignImageStatus::tryFrom($image->status)?->label() ?? $image->status,
            'is_live' => in_array($image->status, array_merge(
                [DesignImageStatus::QUEUED->value], DesignImageStatus::leasedValues(),
            ), true),
            'can_render' => in_array($image->status, DesignImageStatus::enqueueableValues(), true),
            'has_failed' => $image->status === DesignImageStatus::FAILED->value,
            'render_error' => $image->render_error,
            'queued_at' => $image->queued_at,
            'quality' => (string) ($spec['quality'] ?? ''),
            'size' => (string) ($spec['size'] ?? ''),
            'variations' => $variations,
            'cost_unit' => $unit,
            'cost_estimate' => $unit * $variations,
            'cost_recorded' => $recorded,
            'candidates' => $image->artifacts->map(fn (VideoArtifact $artifact) => [
                'id' => $artifact->id,
                'url' => $artifact->storage_path,
                'width' => $artifact->width,
                'height' => $artifact->height,
                'created_at' => $artifact->created_at,
                'sha' => substr((string) $artifact->sha256, 0, 12),
            ])->all(),
        ];
    }

    public function nextImageCode(string $projectId, string $creator): string
    {
        $slug = Str::slug($creator, '_');

        if ($slug === '') {
            throw new \InvalidArgumentException('nextImageCode: creator name is required');
        }

        $now = now();
        $stamp = $now->format('dmY').'_'.$now->format('His');
        $room = self::CODE_MAX - strlen(self::CODE_PREFIX.'_'.$stamp.self::CODE_SUFFIX) - 3;
        $prefix = self::CODE_PREFIX.Str::limit($slug, max(1, $room), '').'_'.$stamp.self::CODE_SUFFIX;

        return $prefix.($this->highestAnchorNumberToday($projectId, $now) + 1);
    }

    /** @param array<string, mixed> $spec */
    public function identityHash(array $spec): string
    {
        $missing = array_diff(self::IDENTITY_KEYS, array_keys($spec));

        if ($missing !== []) {
            throw new \InvalidArgumentException('identityHash: missing '.implode(', ', $missing));
        }

        $identity = array_intersect_key($spec, array_flip(self::IDENTITY_KEYS));
        $this->sortDeep($identity);

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findByHash(string $projectId, string $sha): ?VideoDesignImage
    {
        return VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('prompt_sha256', $sha)
            ->first();
    }

    /** @param array<string, mixed> $value */
    private function sortDeep(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortDeep($item);
            }
        }
    }

    private function highestAnchorNumberToday(string $projectId, \DateTimeInterface $now): int
    {
        $max = 0;
        $dayMark = '%_'.$now->format('dmY').'_%'.self::CODE_SUFFIX.'%';

        foreach (VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_code', 'like', $dayMark)
            ->pluck('image_code') as $code) {
            if (preg_match('/'.preg_quote(self::CODE_SUFFIX, '/').'(\d+)$/', $code, $found)) {
                $max = max($max, (int) $found[1]);
            }
        }

        return $max;
    }
}
