<?php

namespace App\Services\Video;

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
                        'image_type' => 'identity_anchor',
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
