<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoArtifact;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dau ra theo shot cua proxy da duyet (Phase 3P buoc 4, dung tu Phase 6): clay, mask than tau,
 * depth cho moi shot theo camera da bien dich cua shot va build state. Diem noi cho cac phase sau;
 * khong tu sinh shot khi DB chua co.
 *
 * - Chi chay tren proxy da duyet CON HIEU LUC (YachtProxyService::approvedFor).
 * - Shot PROGRESS cung nhom dung chung MOT camera + ti le khung anh da khoa. Khoa nhom la ban ghi
 *   rieng (khong suy tu artifact anh), tao/kiem trong transaction ngan co khoa dong project TRUOC
 *   khi render, va luon kiem — ke ca khi anh lay tu cache.
 * - Cache theo shot + spec + state + camera + code bo dung + kich thuoc anh.
 * - Moi lan render ghi thu muc tam rieng; chi cong bo (doi ten thu muc + ghi artifact) trong
 *   transaction ngan co khoa; request khac da cong bo truoc thi bo ban cua minh. Loi chi don thu
 *   muc tam cua chinh lan do. Khong giu transaction DB trong luc render.
 */
final class YachtProxyShotService
{
    public const ROLE = 'proxy_shot';

    public const LOCK_ROLE = 'proxy_shot_group_lock';

    private const CAMERA_KEYS = ['projection', 'position', 'target', 'up', 'near', 'far'];

    public function __construct(
        private readonly YachtProxyService $proxy,
        private readonly YachtProxyWorker $worker,
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $shots  {shot_id, state, camera, width, height, kind?, group?}
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function render(string $projectId, array $shots): array
    {
        $stage = $this->proxy->approvedFor($projectId);

        if ($stage === null) {
            return [[], 'Chưa có proxy đã duyệt còn hiệu lực cho nguồn hiện hành.'];
        }

        return $this->renderFor($projectId, $stage, $shots);
    }

    /**
     * Render tren revision da duyet con hieu luc (chi goi tu render()).
     *
     * @param  list<array<string, mixed>>  $shots
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function renderFor(string $projectId, VideoPlanningStage $stage, array $shots): array
    {
        $spec = (array) ($stage->output_json['spec'] ?? []);
        $states = array_column((array) ($spec['build_states'] ?? []), 'id');

        foreach ($shots as $shot) {
            if (($problem = self::shotProblem($shot, $states)) !== null) {
                return [[], $problem];
            }
        }

        if (($problem = self::progressProblem($shots) ?? $this->claimGroupLocks($projectId, $shots)) !== null) {
            return [[], $problem];
        }

        $builder = YachtProxyWorker::builderSha256();
        $out = [];

        foreach ($shots as $shot) {
            $key = self::cacheKey((string) $shot['shot_id'], (string) $spec['spec_sha256'], (string) $shot['state'], (array) $shot['camera'], $builder, (int) $shot['width'], (int) $shot['height']);

            try {
                $out[] = $this->cached($projectId, $stage, $shot, $key) ?? $this->renderOne($projectId, $stage, $spec, $shot, $key);
            } catch (RuntimeException $e) {
                return [$out, 'Shot '.$shot['shot_id'].': '.$e->getMessage()];
            }
        }

        return [$out, 'rendered'];
    }

    /**
     * Khoa cache cua MOT shot: doi bat ky thanh phan nao thi render lai. Gom shot_id de artifact,
     * shot.json va thu muc cua moi shot rieng (hai shot cung camera khong dung chung ban ghi).
     */
    public static function cacheKey(string $shotId, string $specSha, string $state, array $camera, string $builderSha, int $width, int $height): string
    {
        return hash('sha256', (string) json_encode([$shotId, $specSha, $state, self::cameraOnly($camera), $builderSha, $width, $height], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Khoa camera cua nhom PROGRESS: camera + TI LE khung anh render (rasterizer tinh goc nhin ngang
     * theo width / height). Doi do phan giai giu nguyen ti le thi van cung khoa.
     */
    public static function cameraLockSha(array $camera, int $width, int $height): string
    {
        return hash('sha256', (string) json_encode([self::cameraOnly($camera), round($width / max(1, $height), 6)], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Shot PROGRESS cung nhom trong cung lan goi phai cung camera va ti le khung.
     *
     * @param  list<array<string, mixed>>  $shots
     */
    public static function progressProblem(array $shots): ?string
    {
        $groups = [];

        foreach ($shots as $shot) {
            if (self::isProgress($shot)) {
                $groups[(string) ($shot['group'] ?? '')][] = $shot;
            }
        }

        foreach ($groups as $group => $members) {
            if ($group === '') {
                return 'Shot PROGRESS phải khai nhóm (group) để khoá camera chung.';
            }

            $first = self::lockOf($members[0]);

            foreach ($members as $member) {
                if (self::lockOf($member) !== $first) {
                    return "Nhóm PROGRESS {$group}: shot {$member['shot_id']} dùng camera hoặc tỉ lệ khung khác camera đã khoá của nhóm.";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $states
     */
    public static function shotProblem(array $shot, array $states): ?string
    {
        $id = (string) ($shot['shot_id'] ?? '');
        $camera = (array) ($shot['camera'] ?? []);

        if ($id === '' || ! in_array($shot['state'] ?? null, $states, true)) {
            return 'Shot '.json_encode($id).' thiếu mã hoặc build state không có trong proxy ('.implode(', ', $states).').';
        }

        foreach (self::CAMERA_KEYS as $key) {
            if (! array_key_exists($key, $camera)) {
                return "Shot {$id}: camera thiếu {$key} (cần camera đã biên dịch đầy đủ).";
            }
        }

        $size = static fn (mixed $v): bool => is_int($v) && $v >= 16 && $v <= 4096;

        if (! $size($shot['width'] ?? null) || ! $size($shot['height'] ?? null)) {
            return "Shot {$id}: kích thước ảnh phải là số nguyên 16–4096.";
        }

        $problem = self::cameraProblem($camera, $shot['width'] / $shot['height']);

        return $problem === null ? null : "Shot {$id}: {$problem}";
    }

    /**
     * Gia tri camera (khong chi su co mat cua truong): kieu chieu, vec-to 3 so huu han, vi tri khac
     * dich, up khac 0 va khong song song huong nhin, 0 < near < far, fov trong (0, 180) hoac khung
     * truc giao left < right, bottom < top dung ti le khung anh (khong co gian rieng hai chieu).
     *
     * @param  array<string, mixed>  $camera
     */
    private static function cameraProblem(array $camera, float $aspect): ?string
    {
        $finite = static fn (mixed $v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v);
        $vector = static fn (mixed $v): bool => is_array($v) && array_is_list($v) && count($v) === 3 && count(array_filter($v, $finite)) === 3;

        if (! in_array($camera['projection'], ['perspective', 'orthographic'], true)) {
            return 'projection phải là perspective hoặc orthographic.';
        }

        foreach (['position', 'target', 'up'] as $key) {
            if (! $vector($camera[$key])) {
                return "{$key} phải là 3 số hữu hạn.";
            }
        }

        $dir = array_map(static fn ($t, $p): float => (float) $t - (float) $p, $camera['target'], $camera['position']);
        $norm = static fn (array $v): float => sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2);
        $up = array_map('floatval', $camera['up']);

        if ($norm($dir) < 1e-6) {
            return 'position trùng target.';
        }

        $cross = [$up[1] * $dir[2] - $up[2] * $dir[1], $up[2] * $dir[0] - $up[0] * $dir[2], $up[0] * $dir[1] - $up[1] * $dir[0]];

        if ($norm($up) < 1e-6 || $norm($cross) < 1e-6 * $norm($up) * $norm($dir)) {
            return 'up bằng 0 hoặc song song hướng nhìn.';
        }

        if (! $finite($camera['near']) || ! $finite($camera['far']) || $camera['near'] <= 0 || $camera['far'] <= $camera['near']) {
            return 'cần 0 < near < far hữu hạn.';
        }

        if ($camera['projection'] === 'perspective') {
            return $finite($camera['fov'] ?? null) && $camera['fov'] > 0 && $camera['fov'] < 180 ? null : 'fov phải trong khoảng (0, 180) độ.';
        }

        $o = $camera['ortho'] ?? null;

        if (! is_array($o) || count(array_filter([$o['left'] ?? null, $o['right'] ?? null, $o['top'] ?? null, $o['bottom'] ?? null], $finite)) !== 4
            || $o['right'] <= $o['left'] || $o['top'] <= $o['bottom']) {
            return 'khung trực giao cần left < right, bottom < top (số hữu hạn).';
        }

        return abs(($o['right'] - $o['left']) / ($o['top'] - $o['bottom']) / $aspect - 1) <= 1e-3 ? null : 'khung trực giao khác tỉ lệ khung ảnh (ảnh sẽ bị co giãn).';
    }

    /** @param array<string, mixed> $shot */
    private static function isProgress(array $shot): bool
    {
        return strtoupper((string) ($shot['kind'] ?? '')) === 'PROGRESS';
    }

    /** @param array<string, mixed> $shot */
    private static function lockOf(array $shot): string
    {
        return self::cameraLockSha((array) $shot['camera'], (int) $shot['width'], (int) $shot['height']);
    }

    /** @return array<string, mixed> */
    private static function cameraOnly(array $camera): array
    {
        $out = [];

        foreach ([...self::CAMERA_KEYS, 'fov', 'ortho'] as $key) {
            $out[$key] = $camera[$key] ?? null;
        }

        return $out;
    }

    /**
     * Tao hoac kiem khoa camera cua tung nhom PROGRESS, nguyen tu theo project (khoa dong project
     * nhu claimProjectStage): hai request dau tien cua mot nhom khong the cung thay "chua co khoa".
     * Shot hop le duoc ghi vao danh sach thanh vien cua khoa (ke ca khi anh se lay tu cache).
     *
     * @param  list<array<string, mixed>>  $shots
     */
    private function claimGroupLocks(string $projectId, array $shots): ?string
    {
        $progress = array_values(array_filter($shots, self::isProgress(...)));

        if ($progress === []) {
            return null;
        }

        $disk = (string) config('video.openai_image.disk');
        $written = [];

        try {
            return DB::transaction(function () use ($projectId, $progress, $disk, &$written): ?string {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                foreach ($progress as $shot) {
                    $group = (string) $shot['group'];
                    $sha = self::lockOf($shot);
                    $lock = VideoArtifact::query()
                        ->where('project_id', $projectId)
                        ->where('role', self::LOCK_ROLE)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.group')) = ?", [$group])
                        ->first();

                    if ($lock !== null) {
                        $meta = (array) $lock->metadata_json;

                        if (($meta['camera_lock_sha256'] ?? null) !== $sha) {
                            // Nem de rollback ca cac khoa khac vua tao trong cung lan goi.
                            throw new RuntimeException("Nhóm PROGRESS {$group}: shot {$shot['shot_id']} dùng camera hoặc tỉ lệ khung khác camera đã khoá của nhóm (khoá từ shot {$meta['locked_by']}).");
                        }

                        if (! in_array($shot['shot_id'], (array) ($meta['members'] ?? []), true)) {
                            $meta['members'] = [...(array) ($meta['members'] ?? []), $shot['shot_id']];
                            $lock->metadata_json = $meta;
                            $lock->save();
                        }

                        continue;
                    }

                    $bytes = (string) json_encode(['group' => $group, 'camera' => self::cameraOnly((array) $shot['camera']), 'width' => (int) $shot['width'], 'height' => (int) $shot['height']], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                    $path = $projectId.'/proxy-shots/locks/'.Str::uuid().'.json';
                    $this->storage->disk($disk)->put($path, $bytes);
                    $written[] = $path;
                    VideoArtifact::create([
                        'project_id' => $projectId,
                        'artifact_type' => 'json',
                        'role' => self::LOCK_ROLE,
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'mime_type' => 'application/json',
                        'file_size' => strlen($bytes),
                        'sha256' => hash('sha256', $bytes),
                        'metadata_json' => ['group' => $group, 'camera_lock_sha256' => $sha, 'aspect' => round((int) $shot['width'] / max(1, (int) $shot['height']), 6), 'locked_by' => $shot['shot_id'], 'members' => [$shot['shot_id']]],
                    ]);
                }

                $written = [];

                return null;
            });
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } finally {
            // Transaction loi/rollback: bo file khoa vua ghi (ban ghi DB da rollback).
            $this->storage->disk($disk)->delete($written);
        }
    }

    /**
     * Artifact da render cung khoa (con nguyen sha256) cho dung shot va revision.
     *
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>|null
     */
    private function cached(string $projectId, VideoPlanningStage $stage, array $shot, string $key): ?array
    {
        $rows = $this->active($projectId, $stage, $shot, $key)->get();

        if ($rows->count() !== 4) {
            return null;
        }

        foreach ($rows as $row) {
            $bytes = $this->storage->disk((string) $row->storage_disk)->get((string) $row->storage_path);

            if (! is_string($bytes) || ! hash_equals((string) $row->sha256, hash('sha256', $bytes))) {
                return null;
            }
        }

        return ['shot_id' => $shot['shot_id'], 'cache_key' => $key, 'cached' => true, 'artifacts' => $rows->map(fn (VideoArtifact $a): array => ['file' => $a->metadata_json['file'], 'artifact_id' => (string) $a->id, 'sha256' => (string) $a->sha256])->values()->all()];
    }

    /**
     * Bo artifact HIEN HANH cua dung project + revision + shot + khoa cache (bo da bi thay khi phuc
     * hoi cache hong mang `superseded_at`, khong tinh).
     *
     * @param  array<string, mixed>  $shot
     */
    private function active(string $projectId, VideoPlanningStage $stage, array $shot, string $key): \Illuminate\Database\Eloquent\Builder
    {
        return VideoArtifact::query()
            ->where('project_id', $projectId)
            ->where('role', self::ROLE)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.cache_key')) = ?", [$key])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.shot_id')) = ?", [(string) $shot['shot_id']])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.proxy_stage_id')) = ?", [(string) $stage->id])
            ->whereRaw("JSON_EXTRACT(metadata_json, '$.superseded_at') IS NULL");
    }

    /**
     * Render vao thu muc tam rieng roi cong bo nguyen tu.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed>
     */
    private function renderOne(string $projectId, VideoPlanningStage $stage, array $spec, array $shot, string $key): array
    {
        $disk = (string) config('video.openai_image.disk');
        $temp = $projectId.'/proxy-shots/tmp/'.Str::uuid();

        try {
            $result = $this->worker->run([
                'job' => 'shot',
                'spec' => $spec,
                'state' => $shot['state'],
                'camera' => $shot['camera'],
                'width' => (int) $shot['width'],
                'height' => (int) $shot['height'],
                'shot_id' => $shot['shot_id'],
                'out_dir' => $this->storage->disk($disk)->path($temp),
            ]);

            return $this->publish($projectId, $stage, $shot, $key, $disk, $temp, (array) $result['files'], (string) $result['camera_sha256']);
        } finally {
            $this->storage->disk($disk)->deleteDirectory($temp);
        }
    }

    /**
     * Cong bo ket qua render: trong transaction ngan co khoa dong project, neu request khac da cong
     * bo cung khoa thi tra ban do (bo ban tam cua minh); khong thi doi ten thu muc tam thanh thu
     * muc chinh (xoa thu muc mo coi neu co) va ghi artifact.
     *
     * @param  array<string, mixed>  $shot
     * @param  array<string, array{sha256: string}>  $files
     * @return array<string, mixed>
     */
    private function publish(string $projectId, VideoPlanningStage $stage, array $shot, string $key, string $disk, string $temp, array $files, string $cameraSha): array
    {
        $storage = $this->storage->disk($disk);

        foreach ($files as $name => $meta) {
            $bytes = $storage->get($temp.'/'.$name);

            if (! is_string($bytes) || ! hash_equals((string) $meta['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException("file {$name} của worker thiếu hoặc không khớp sha256");
            }
        }

        return DB::transaction(function () use ($projectId, $stage, $shot, $key, $storage, $disk, $temp, $files, $cameraSha): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

            if (($existing = $this->cached($projectId, $stage, $shot, $key)) !== null) {
                return $existing;
            }

            // Bo hien hanh con lai (file hong / thieu) bi thay: danh dau, giu lich su, khong tinh nua.
            foreach ($this->active($projectId, $stage, $shot, $key)->lockForUpdate()->get() as $old) {
                $old->metadata_json = ((array) $old->metadata_json) + ['superseded_at' => now()->toIso8601String(), 'superseded_reason' => 'phục hồi cache: file hỏng hoặc thiếu'];
                $old->save();
            }

            $dir = $projectId.'/proxy-shots/'.$key;

            if ($storage->exists($dir)) {
                $storage->deleteDirectory($dir);
            }

            if (! @rename($storage->path($temp), $storage->path($dir))) {
                throw new RuntimeException('không công bố được thư mục kết quả');
            }

            $artifacts = [];

            foreach (array_keys($files) as $name) {
                $bytes = (string) $storage->get($dir.'/'.$name);
                $info = str_ends_with($name, '.png') ? @getimagesizefromstring($bytes) : false;
                $artifact = VideoArtifact::create([
                    'project_id' => $projectId,
                    'artifact_type' => str_ends_with($name, '.png') ? 'image' : 'json',
                    'role' => self::ROLE,
                    'storage_disk' => $disk,
                    'storage_path' => $dir.'/'.$name,
                    'mime_type' => str_ends_with($name, '.png') ? 'image/png' : 'application/json',
                    'file_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'width' => $info === false ? null : $info[0],
                    'height' => $info === false ? null : $info[1],
                    'metadata_json' => ['shot_id' => $shot['shot_id'], 'proxy_stage_id' => (string) $stage->id, 'cache_key' => $key, 'state' => $shot['state'], 'camera_sha256' => $cameraSha, 'file' => $name],
                ]);
                $artifacts[] = ['file' => $name, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256];
            }

            return ['shot_id' => $shot['shot_id'], 'cache_key' => $key, 'cached' => false, 'artifacts' => $artifacts];
        });
    }
}
