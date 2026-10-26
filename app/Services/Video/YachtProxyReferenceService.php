<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoArtifact;
use App\Video\Proxy\ProxyVerdict;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tham chieu doi chieu proxy voi model sheet: mask tau va moc da danh dau tren tung o cua master
 * da duyet. Moi ban ghi gan voi sha256 cua master va cua o; doi master/o thi tham chieu cu khong
 * con dung. Mask chi dung khi nguoi dung xac nhan (de xuat tu dong hoac da giac tu khoanh); doi
 * mask/moc tao ban ghi moi, khong sua ban cu. Khong goi model.
 */
final class YachtProxyReferenceService
{
    public const PROPOSAL_ROLE = 'proxy_reference_proposal';

    public const MASK_ROLE = 'proxy_reference_mask';

    public const LANDMARKS_ROLE = 'proxy_reference_landmarks';

    public const CAMERA_ROLE = 'proxy_reference_camera';

    public const STATUSES = ['visible', 'occluded', 'out_of_frame'];

    public function __construct(
        private readonly YachtProxySource $source,
        private readonly YachtProxyWorker $worker,
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * Trang thai tham chieu tung goc cho nguon hien hanh.
     *
     * @param  array<string, mixed>  $source  YachtProxySource::resolve()
     * @return array<string, array<string, mixed>>
     */
    public function current(string $projectId, array $source): array
    {
        $out = [];

        foreach ($this->crops($source) as $view => $crop) {
            $rows = $this->rows($projectId, $source, $crop);
            $mask = $rows->firstWhere('role', self::MASK_ROLE);
            $landmarks = $rows->firstWhere('role', self::LANDMARKS_ROLE);
            $proposal = $rows->firstWhere('role', self::PROPOSAL_ROLE);
            $camera = $rows->firstWhere('role', self::CAMERA_ROLE);
            $out[$view] = [
                'view' => $view,
                'crop' => $crop,
                'proposal' => $proposal === null ? null : $this->view($proposal),
                'mask' => $mask === null ? null : $this->view($mask),
                'landmarks' => $landmarks === null ? null : $this->view($landmarks) + ['points' => (array) ($landmarks->metadata_json['points'] ?? [])],
                'camera' => $camera === null ? null : $this->view($camera) + ['camera_sha256' => (string) ($camera->metadata_json['camera_sha256'] ?? '')],
            ];
        }

        return $out;
    }

    /** @return array{0: ?VideoArtifact, 1: string} */
    public function propose(string $projectId, string $view, int $tolerance): array
    {
        try {
            [$source, $crop] = $this->context($projectId, $view);
            $local = $this->localCopy($crop['artifact_id']);
            $out = $local.'.mask.png';

            try {
                $result = $this->worker->run(['job' => 'propose', 'crop_path' => $local, 'tolerance' => $tolerance, 'out_path' => $out]);
                $bytes = (string) file_get_contents($out);
            } finally {
                @unlink($local);
                @unlink($out);
            }

            if (! hash_equals((string) $result['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Mask đề xuất không khớp sha256 worker trả về.');
            }

            return [$this->store($projectId, $source, $crop, self::PROPOSAL_ROLE, $bytes, 'image/png', ['method' => 'auto', 'tolerance' => $tolerance, 'coverage' => $result['coverage']]), 'proposed'];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Mask tu da giac nguoi dung khoanh tren o (pixel cua o 960x1080).
     *
     * @param  list<array{0: int|float, 1: int|float}>  $points
     * @return array{0: ?VideoArtifact, 1: string}
     */
    public function polygon(string $projectId, string $view, array $points): array
    {
        try {
            [$source, $crop] = $this->context($projectId, $view);
            [$width, $height] = [(int) $crop['width'], (int) $crop['height']];

            if (count($points) < 3 || count($points) > 400) {
                throw new RuntimeException('Đa giác cần từ 3 đến 400 điểm.');
            }

            $flat = [];

            foreach ($points as $point) {
                [$x, $y] = [(float) ($point[0] ?? -1), (float) ($point[1] ?? -1)];

                if ($x < 0 || $y < 0 || $x > $width || $y > $height) {
                    throw new RuntimeException('Điểm đa giác nằm ngoài ô '.$width.'×'.$height.'.');
                }

                array_push($flat, (int) round($x), (int) round($y));
            }

            // Truecolor (PNG RGB): worker doc PNG xam/RGB, khong doc anh bang mau.
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
            imagefilledpolygon($image, $flat, imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();

            return [$this->store($projectId, $source, $crop, self::PROPOSAL_ROLE, $bytes, 'image/png', ['method' => 'polygon', 'points' => array_chunk($flat, 2)]), 'proposed'];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /** @return array{0: ?VideoArtifact, 1: string} */
    public function confirm(string $projectId, string $view, string $proposalId, string $creator): array
    {
        try {
            [$source, $crop] = $this->context($projectId, $view);
            $proposal = $this->rows($projectId, $source, $crop)->first(fn (VideoArtifact $a): bool => (string) $a->id === $proposalId && $a->role === self::PROPOSAL_ROLE);

            if ($proposal === null) {
                throw new RuntimeException('Mask đề xuất không thuộc ô hiện hành của master đã duyệt.');
            }

            $bytes = (string) $this->storage->disk((string) $proposal->storage_disk)->get((string) $proposal->storage_path);

            if (! hash_equals((string) $proposal->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('File mask đề xuất không khớp sha256.');
            }

            return [$this->store($projectId, $source, $crop, self::MASK_ROLE, $bytes, 'image/png', [
                'proposal_artifact_id' => (string) $proposal->id,
                'method' => $proposal->metadata_json['method'] ?? null,
                'confirmed_by' => $creator,
                'confirmed_at' => now()->toIso8601String(),
            ]), 'confirmed'];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Moc tham chieu: ten moc cua thiet ke, trang thai (thay / bi che / ngoai khung), pixel khi thay.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{0: ?VideoArtifact, 1: string}
     */
    public function landmarks(string $projectId, string $view, array $points, string $creator): array
    {
        try {
            [$source, $crop] = $this->context($projectId, $view);
            $names = self::landmarkNames($source);
            $clean = [];

            foreach ($points as $point) {
                $name = (string) ($point['name'] ?? '');
                $status = (string) ($point['status'] ?? '');

                if (! in_array($name, $names, true) || ! in_array($status, self::STATUSES, true) || isset($clean[$name])) {
                    throw new RuntimeException('Mốc '.json_encode($name).' không thuộc thiết kế, trùng hoặc trạng thái không hợp lệ.');
                }

                $entry = ['name' => $name, 'status' => $status];

                if ($status === 'visible') {
                    [$x, $y] = [$point['x'] ?? null, $point['y'] ?? null];

                    if (! is_numeric($x) || ! is_numeric($y) || $x < 0 || $y < 0 || $x > $crop['width'] || $y > $crop['height']) {
                        throw new RuntimeException("Mốc {$name} cần toạ độ pixel trong ô.");
                    }

                    $entry += ['x' => round((float) $x, 1), 'y' => round((float) $y, 1)];
                }

                $clean[$name] = $entry;
            }

            $bytes = (string) json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return [$this->store($projectId, $source, $crop, self::LANDMARKS_ROLE, $bytes, 'application/json', ['points' => array_values($clean), 'confirmed_by' => $creator, 'confirmed_at' => now()->toIso8601String()]), 'saved'];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Xac nhan camera cua mot goc: thong so camera cua spec (goc, tieu cu, eye level, khung o, muc
     * tieu) khop o model sheet. Gan voi hash thong so — doi camera thi phai xac nhan lai.
     *
     * @param  array<string, mixed>  $camera  camera cua ProxySpec hien hanh
     * @return array{0: ?VideoArtifact, 1: string}
     */
    public function confirmCamera(string $projectId, string $view, array $camera, string $creator): array
    {
        try {
            [$source, $crop] = $this->context($projectId, $view);

            if (($camera['view'] ?? null) !== $view || (int) ($camera['width'] ?? 0) !== (int) $crop['width'] || (int) ($camera['height'] ?? 0) !== (int) $crop['height']) {
                throw new RuntimeException("Camera {$view} không khớp khung ô của master.");
            }

            $sha = ProxyVerdict::cameraSha($camera);
            $bytes = (string) json_encode(['camera_sha256' => $sha, 'camera' => $camera], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

            return [$this->store($projectId, $source, $crop, self::CAMERA_ROLE, $bytes, 'application/json', ['camera_sha256' => $sha, 'confirmed_by' => $creator, 'confirmed_at' => now()->toIso8601String()]), 'camera_confirmed'];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Tham chieu cho worker: mask da xac nhan (ban sao tam), moc, va hash dua vao dau vao revision.
     * Goc chi co moc (MS8, hoac goc IoU chua co mask) van duoc gui de do / bao cho mask.
     *
     * @param  array<string, mixed>  $source
     * @return array{references: array<string, array<string, mixed>>, sha256: string, cameras: array<string, string>, temp: list<string>}
     */
    public function forBuild(string $projectId, array $source): array
    {
        $current = $this->current($projectId, $source);
        $refs = [];
        $temp = [];

        try {
            foreach ($current as $view => $state) {
                if ($state['mask'] === null && $state['landmarks'] === null) {
                    continue;
                }

                $refs[$view] = [
                    'mask_path' => null,
                    'mask_sha256' => $state['mask']['sha256'] ?? null,
                    'landmarks' => $state['landmarks']['points'] ?? [],
                    'landmarks_sha256' => $state['landmarks']['sha256'] ?? null,
                ];

                if ($state['mask'] !== null) {
                    $temp[] = $refs[$view]['mask_path'] = $this->localCopy($state['mask']['id']);
                }
            }
        } catch (RuntimeException $e) {
            foreach ($temp as $path) {
                @unlink($path);
            }

            throw $e;
        }

        return ['references' => $refs, 'sha256' => self::sha($current), 'cameras' => self::cameras($current), 'temp' => $temp];
    }

    /**
     * Hash tham chieu hien hanh (mask, moc, camera da xac nhan) — khong chep file.
     *
     * @param  array<string, mixed>  $source
     * @return array{sha256: string, cameras: array<string, string>}
     */
    public function fingerprint(string $projectId, array $source): array
    {
        $current = $this->current($projectId, $source);

        return ['sha256' => self::sha($current), 'cameras' => self::cameras($current)];
    }

    /** @param array<string, array<string, mixed>> $current */
    private static function sha(array $current): string
    {
        $ids = [];

        foreach ($current as $view => $state) {
            $entry = array_filter(['mask' => $state['mask']['sha256'] ?? null, 'landmarks' => $state['landmarks']['sha256'] ?? null, 'camera' => $state['camera']['sha256'] ?? null]);

            if ($entry !== []) {
                $ids[$view] = $entry;
            }
        }

        return hash('sha256', (string) json_encode($ids, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @return array<string, string> goc => hash camera da xac nhan
     */
    private static function cameras(array $current): array
    {
        return array_filter(array_map(static fn (array $state): ?string => $state['camera']['camera_sha256'] ?? null, $current));
    }

    /**
     * Ten moc cua thiet ke (bang facts cua nguon) co toa do x/z — moc danh dau duoc tren anh.
     *
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    public static function landmarkNames(array $source): array
    {
        return array_values(array_map(
            static fn (array $e): string => substr((string) $e['id'], 2),
            array_filter((array) ($source['facts']['entities'] ?? []), static fn (array $e): bool => ($e['kind'] ?? null) === 'landmark' && is_numeric($e['from'] ?? null) && is_numeric($e['z'] ?? null)),
        ));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function context(string $projectId, string $view): array
    {
        if (! in_array($view, (array) config('video.superyacht.proxy.reference_views'), true)) {
            throw new RuntimeException("Góc {$view} không có tham chiếu.");
        }

        $source = $this->source->resolve($projectId);
        $crop = $this->crops($source)[$view] ?? throw new RuntimeException("Master đã duyệt không có ô {$view}.");

        return [$source, $crop];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, array<string, mixed>>
     */
    private function crops(array $source): array
    {
        $views = (array) config('video.superyacht.proxy.reference_views');

        return collect((array) $source['crops'])->filter(fn (array $c): bool => in_array($c['view_id'], $views, true))->keyBy('view_id')->all();
    }

    /** Ban ghi tham chieu cua dung o hien hanh (master + o cung sha), moi nhat truoc. */
    private function rows(string $projectId, array $source, array $crop): \Illuminate\Support\Collection
    {
        return VideoArtifact::query()
            ->where('project_id', $projectId)
            ->whereIn('role', [self::PROPOSAL_ROLE, self::MASK_ROLE, self::LANDMARKS_ROLE, self::CAMERA_ROLE])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.crop_sha256')) = ?", [(string) $crop['sha256']])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.master_sha256')) = ?", [(string) $source['ids']['master_sha256']])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $crop
     * @param  array<string, mixed>  $metadata
     */
    private function store(string $projectId, array $source, array $crop, string $role, string $bytes, string $mime, array $metadata): VideoArtifact
    {
        $disk = (string) config('video.openai_image.disk');
        $path = $projectId.'/proxy-reference/'.$crop['view_id'].'/'.Str::uuid().($mime === 'application/json' ? '.json' : '.png');

        if (! $this->storage->disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('Không ghi được file tham chiếu.');
        }

        $info = $mime === 'image/png' ? @getimagesizefromstring($bytes) : false;

        if ($mime === 'image/png' && ($info === false || $info[0] !== (int) $crop['width'] || $info[1] !== (int) $crop['height'])) {
            $this->storage->disk($disk)->delete($path);

            throw new RuntimeException('Mask phải đúng khung ô '.$crop['width'].'×'.$crop['height'].'.');
        }

        return VideoArtifact::create([
            'project_id' => $projectId,
            'artifact_type' => $mime === 'image/png' ? 'image' : 'json',
            'role' => $role,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'width' => $info === false ? null : $info[0],
            'height' => $info === false ? null : $info[1],
            'metadata_json' => $metadata + [
                'view_id' => $crop['view_id'],
                'crop_artifact_id' => $crop['artifact_id'],
                'crop_sha256' => $crop['sha256'],
                'master_artifact_id' => $source['ids']['master_artifact_id'],
                'master_sha256' => $source['ids']['master_sha256'],
            ],
        ]);
    }

    /** @return array{id: string, url: string, sha256: string, created_at: mixed, meta: array<string, mixed>} */
    private function view(VideoArtifact $artifact): array
    {
        return [
            'id' => (string) $artifact->id,
            'url' => route('video-artifacts.show', $artifact->id),
            'sha256' => (string) $artifact->sha256,
            'created_at' => $artifact->created_at,
            'meta' => (array) $artifact->metadata_json,
        ];
    }

    /** Ban sao tam cua file artifact (kiem sha256) de worker doc theo duong dan. */
    private function localCopy(string $artifactId): string
    {
        $artifact = VideoArtifact::query()->findOrFail($artifactId);
        $bytes = (string) $this->storage->disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path);

        if (! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('File '.$artifact->role.' không khớp sha256.');
        }

        $dir = storage_path('app/proxy-jobs');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.Str::uuid().'.png';
        file_put_contents($path, $bytes);

        return $path;
    }
}
