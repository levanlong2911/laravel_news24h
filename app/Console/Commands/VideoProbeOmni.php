<?php

namespace App\Console\Commands;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Video\Media\FfmpegRunner;
use App\Video\Media\GeminiOmniVideoClient;
use App\Video\Media\MediaProbe;
use App\Video\Media\VideoModelRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoProbeOmni extends Command
{
    public const TASK = 'film_segment';

    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    public const AMBIGUOUS = 'ambiguous';

    public const SUBMIT_FAILED = 'submit_failed';

    public const POLL_STOPPED = 'poll_stopped';

    public const DOWNLOAD_FAILED = 'download_failed';

    public const MEASURE_FAILED = 'measure_failed';

    public const MEASURED = 'measured';

    /** @var list<string> */
    private const RESUMABLE = [self::SUBMITTED, self::POLL_STOPPED, self::DOWNLOAD_FAILED];

    /** @var list<string> */
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    protected $signature = 'video:probe-omni
        {--project= : Du an so huu moi anh tham chieu}
        {--artifact=* : Artifact anh DA DUYET gui lam tham chieu, dung thu tu gui}
        {--prompt-file= : File prompt UTF-8, gui nguyen van}
        {--send : Goi API that — tinh tien}
        {--expect= : Hash request da xem o lan chay khong --send; bat buoc khi --send}
        {--repeat : Cho gui lai mot request da tung do xong — tinh tien them lan nua}
        {--resume= : Ho so luot thu da gui; chi poll va tai, khong bao gio gui lai}
        {--save-sent : Luu ban anh se gui API ra dia de duyet bang mat; khong gui gi}
        {--extend-from= : Ho so luot nguon da do xong; gui Extend tu interaction cua no}
        {--frame-from= : Ho so luot nguon da do xong; them khung cuoi cua video nguon lam anh tham chieu}
        {--poll-seconds=5 : Khoang cach giua hai lan hoi trang thai file}
        {--poll-limit=120 : So lan hoi toi da truoc khi dung}';

    protected $description = 'Xem truoc (mac dinh) hoac gui MOT request Omni de do hop dong: do dai, kich thuoc, am thanh, gia';

    public function handle(GeminiOmniVideoClient $client, VideoModelRegistry $registry, MediaProbe $probe, FfmpegRunner $ffmpeg): int
    {
        $entry = $registry->defaultFor(self::TASK);

        if ((string) $this->option('resume') !== '') {
            return $this->resume($client, $probe, $entry, (string) $this->option('resume'));
        }

        $extendFrom = null;
        $frameFrom = null;

        foreach (['extend-from' => true, 'frame-from' => false] as $option => $needsStore) {
            if ((string) $this->option($option) === '') {
                continue;
            }

            [$source, $problem] = $this->sourceRun((string) $this->option($option), $needsStore);

            if ($problem !== null) {
                $this->error('--'.$option.': '.$problem);

                return self::FAILURE;
            }

            $option === 'extend-from' ? $extendFrom = $source : $frameFrom = $source;
        }

        [$images, $problem] = $this->images((string) $this->option('project'), (array) $this->option('artifact'), $entry,
            $extendFrom !== null || $frameFrom !== null);

        if ($problem === null && $frameFrom !== null) {
            [$frame, $problem] = $this->frame($ffmpeg, $frameFrom, count($images));
            $images = $frame === null ? $images : [...$images, $frame];
        }

        if ($problem === null && count($images) > (int) $entry['controls']['max_reference_images']) {
            $problem = 'Toi da '.$entry['controls']['max_reference_images'].' anh moi request, dang co '.count($images).'.';
        }

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $promptFile = (string) $this->option('prompt-file');
        $prompt = is_file($promptFile) ? trim((string) file_get_contents($promptFile)) : '';

        if ($prompt === '') {
            $this->error('Can --prompt-file tro toi mot file prompt khong rong.');

            return self::FAILURE;
        }

        $output = [
            'aspect_ratio' => (string) $entry['controls']['default_aspect_ratio'],
            'resolution' => (string) $entry['controls']['default_resolution'],
        ];
        $sent = array_map(static fn (array $image): array => ['mime' => $image['mime'], 'base64' => $image['base64']], $images);
        $payload = $extendFrom === null
            ? GeminiOmniVideoClient::payload($entry, $sent, $prompt, $output)
            : GeminiOmniVideoClient::extendPayload($entry, (string) $extendFrom['interaction_id'], $sent, $prompt, $output);
        $payloadJson = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestHash = hash('sha256', $payloadJson);
        $summary = [
            'model' => $entry['model'],
            'endpoint' => 'POST '.config('video.gemini.base_url').'/'.$entry['api_version'].'/'.VideoModelRegistry::INTERACTIONS,
            'mode' => $extendFrom === null ? 'generate' : 'extend',
            'task' => $payload['generation_config']['video_config']['task'],
            'store' => $payload['store'],
            'extend_from' => $extendFrom,
            'frame_from' => $frameFrom,
            'output' => $output + ['delivery' => 'uri'],
            'images' => array_map(static fn (array $image): array => array_diff_key($image, ['base64' => true, 'mime' => true]), $images),
            'reference_policy' => (array) config('video.omni.reference'),
            'prompt' => $prompt,
            'payload_bytes' => strlen($payloadJson),
            'request_sha256' => $requestHash,
        ];

        $this->line((string) json_encode($summary, self::JSON_FLAGS));
        $this->warn('Gia 1080p va gia Extend chua xac minh; 720p theo tai lieu ~ $0.10/giay, tuc ~ $1.00 cho 10 giay.');

        if ($this->option('save-sent') && ! $this->saveSent($images)) {
            return self::FAILURE;
        }

        if (! $this->option('send')) {
            $this->info('Chi xem truoc — chua gui gi. Gui that: them --send --expect='.$requestHash);

            return self::SUCCESS;
        }

        if (! hash_equals($requestHash, (string) $this->option('expect'))) {
            $this->error('--expect khong khop hash request vua dung — chua gui gi. Chay lai khong --send de xem ban hien tai.');

            return self::FAILURE;
        }

        $lock = $this->lock($requestHash);

        if ($lock === null) {
            $this->error('Dang co tien trinh khac gui cung request nay, hoac khong tao duoc khoa — chua gui gi.');

            return self::FAILURE;
        }

        try {
            $blocker = $this->earlierRun($requestHash);

            if ($blocker !== null) {
                $this->error($blocker);

                return self::FAILURE;
            }

            $file = $this->recordPath();
            $record = [
                'captured_at' => now()->toIso8601String(),
                'status' => self::SUBMITTING,
                'video_path' => trim((string) config('video.omni.canary_path'), '/').'/'.basename($file, '.json').'.mp4',
                'request' => $summary,
            ];

            if (! $this->write($file, $record)) {
                $this->error('Khong ghi duoc ho so luot thu '.$file.' — chua gui gi.');

                return self::FAILURE;
            }

            $startedAt = microtime(true);
            $submit = $client->submit($entry, $payload);
            $record['submit_seconds'] = round(microtime(true) - $startedAt, 1);
            $record['submit'] = $this->plain($submit);
            $record['interaction_id'] = is_string($submit['response']['id'] ?? null) ? $submit['response']['id'] : null;
            $record['status'] = match (true) {
                $submit['ok'] => self::SUBMITTED,
                $submit['ambiguous'] => self::AMBIGUOUS,
                default => self::SUBMIT_FAILED,
            };

            if (! $this->write($file, $record)) {
                $this->error('Da gui nhung KHONG ghi duoc ho so '.$file.'. Ket qua: '.json_encode($record['submit'], JSON_UNESCAPED_SLASHES)
                    .' — giu dong nay, khong gui lai.');

                return self::FAILURE;
            }

            $this->line('Ho so: '.$file);

            if (! $submit['ok']) {
                $this->error(($submit['ambiguous'] ? 'KET QUA CHUA RO — khong gui lai, kiem tra tay. ' : 'Gui that bai: ').$submit['error']);

                return self::FAILURE;
            }

            return $this->collect($client, $probe, $entry, $file, $record);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $entry */
    private function resume(GeminiOmniVideoClient $client, MediaProbe $probe, array $entry, string $name): int
    {
        $file = $this->evidenceDir().DIRECTORY_SEPARATOR.basename($name);

        if (! str_starts_with(basename($file), (string) config('video.omni.evidence_prefix'))) {
            $this->error('Khong phai ho so luot thu Omni: '.$file.'.');

            return self::FAILURE;
        }

        $hash = $this->readRecord($file)['request']['request_sha256'] ?? null;
        $lock = is_string($hash) ? $this->lock($hash) : null;

        if ($lock === null) {
            $this->error(is_string($hash)
                ? 'Dang co tien trinh khac xu ly request cua ho so nay — khong lam gi.'
                : 'Khong doc duoc ho so luot thu '.$file.'.');

            return self::FAILURE;
        }

        try {
            $record = $this->readRecord($file);

            if (! is_array($record) || ($record['request']['request_sha256'] ?? null) !== $hash) {
                $this->error('Ho so '.$file.' da doi hoac khong doc duoc sau khi lay khoa — khong lam gi.');

                return self::FAILURE;
            }

            if (! in_array($record['status'] ?? null, self::RESUMABLE, true) || ! is_string($record['submit']['job_id'] ?? null)
                || ! is_string($record['video_path'] ?? null)) {
                $this->error('Ho so o trang thai '.json_encode($record['status'] ?? null).' — chi tiep tuc duoc khi da co ma file va chua tai xong.');

                return self::FAILURE;
            }

            $record['resumed_at'][] = now()->toIso8601String();

            return $this->collect($client, $probe, $entry, $file, $record);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed>|null */
    private function readRecord(string $file): ?array
    {
        $record = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $record
     */
    private function collect(GeminiOmniVideoClient $client, MediaProbe $probe, array $entry, string $file, array $record): int
    {
        $jobId = (string) $record['submit']['job_id'];
        $poll = null;
        $polls = (int) ($record['polls'] ?? 0);
        $limit = $polls + (int) $this->option('poll-limit');

        while ($polls < $limit) {
            $polls++;
            $poll = $client->poll($entry, $jobId);
            $record['polls'] = $polls;
            $record['poll'] = $this->plain($poll);
            $this->save($file, $record);

            if (! $poll['ok'] || $poll['done']) {
                break;
            }

            sleep(max(1, (int) $this->option('poll-seconds')));
        }

        if ($poll === null || ! $poll['ok'] || ! $poll['done']) {
            $final = $poll !== null && ! $poll['ok'] && $poll['done'];
            $record['status'] = $final ? self::DOWNLOAD_FAILED : self::POLL_STOPPED;
            $this->save($file, $record);
            $this->error(($final ? 'File loi: ' : 'Chua san sang: ').($poll['error'] ?? 'het luot hoi')
                .' — tiep tuc bang --resume='.basename($file).', khong gui lai.');

            return self::FAILURE;
        }

        $path = (string) $record['video_path'];
        $download = $client->download((string) $poll['video_uri'], $path);
        $record['download'] = array_diff_key($this->plain($download), ['local_path' => true]) + ['path' => $path];

        if (! $download['ok']) {
            $record['status'] = self::DOWNLOAD_FAILED;
            $this->save($file, $record);
            $this->error('Tai that bai: '.$download['error'].' — tai lai bang --resume='.basename($file).'.');

            return self::FAILURE;
        }

        $measured = $probe->inspect((string) $download['local_path']);
        @unlink((string) $download['local_path']);
        $record['probe'] = $measured;

        if (! $measured['ok']) {
            $record['status'] = self::MEASURE_FAILED;
            $this->save($file, $record);
            $this->error('Da tai '.$path.' nhung KHONG do duoc: '.$measured['error'].' — file va ho so duoc giu.');

            return self::FAILURE;
        }

        $sourceMs = $record['request']['extend_from']['video_ms'] ?? null;
        $record['target'] = $this->verdict($measured, is_int($sourceMs) ? $sourceMs : null);
        $record['status'] = self::MEASURED;

        if (! $this->write($file, $record)) {
            $this->error('Do xong nhung khong ghi duoc ho so '.$file.'.');

            return self::FAILURE;
        }

        $this->info(sprintf('Do duoc: hinh %s ms, tieng %s ms, container %d ms, %dx%d, %d luong hinh, %d luong tieng. File: %s',
            $record['target']['video_ms'] ?? '?', $record['target']['audio_ms'] ?? '?', $measured['duration_ms'],
            $measured['width'], $measured['height'], $measured['video_count'], $measured['audio_count'], $path));
        $record['target']['met']
            ? $this->info('DAT muc tieu do dai, kich thuoc va dong bo hinh-tieng. Noi dung tieng (co nhac/thoai khong) phai nghe duyet.')
            : $this->warn('KHONG DAT muc tieu: '.implode('; ', $record['target']['failures']).' — van la ket qua thuc nghiem hop le.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $measured
     * @return array{met: bool, failures: list<string>, video_ms: ?int, audio_ms: ?int, video_start_ms: ?int, audio_start_ms: ?int,
     *               duration_basis: string, expected: array<string, int>, audio_content: string}
     */
    private function verdict(array $measured, ?int $sourceMs = null): array
    {
        $target = (array) config('video.omni.target');
        $failures = [];
        $videoMs = self::streamMs($measured['video'] ?? null);
        $audioMs = self::streamMs($measured['audio'] ?? null);
        $tolerance = (int) $target['duration_tolerance_ms'];
        $basis = $sourceMs === null ? 'single_clip' : 'extension_unmatched';

        if ((int) $measured['video_count'] !== (int) $target['video_streams']) {
            $failures[] = sprintf('%d luong hinh, muc tieu %d', $measured['video_count'], $target['video_streams']);
        }

        if ($videoMs === null) {
            $failures[] = 'khong doc duoc thoi luong cua luong hinh';
        } elseif ($sourceMs !== null) {
            [$min, $max] = [(int) $target['extension_min_ms'], (int) $target['extension_max_ms']];
            $basis = match (true) {
                $videoMs >= $min - $tolerance && $videoMs <= $max + $tolerance => 'continuation_only',
                $videoMs >= $sourceMs + $min - $tolerance && $videoMs <= $sourceMs + $max + $tolerance => 'includes_source',
                default => 'extension_unmatched',
            };

            if ($basis === 'extension_unmatched') {
                $failures[] = sprintf('luong hinh %d ms khong khop phan noi them %d–%d ms, du tinh rieng hay cong nguon %d ms', $videoMs, $min, $max, $sourceMs);
            }
        } elseif (abs($videoMs - (int) $target['duration_ms']) > $tolerance) {
            $failures[] = sprintf('luong hinh %d ms, muc tieu %d ± %d ms', $videoMs, $target['duration_ms'], $tolerance);
        }

        $width = $measured['video']['width'] ?? $measured['width'];
        $height = $measured['video']['height'] ?? $measured['height'];

        if ((int) $width !== (int) $target['width'] || (int) $height !== (int) $target['height']) {
            $failures[] = sprintf('kich thuoc %dx%d, muc tieu %dx%d', $width, $height, $target['width'], $target['height']);
        }

        $videoStart = self::startMs($measured['video'] ?? null);
        $audioStart = self::startMs($measured['audio'] ?? null);
        $drift = (int) $target['av_drift_tolerance_ms'];

        if ((int) $measured['audio_count'] !== (int) $target['audio_streams']) {
            $failures[] = sprintf('%d luong tieng, muc tieu %d', $measured['audio_count'], $target['audio_streams']);
        } elseif ($audioMs === null || $videoMs === null || $videoStart === null || $audioStart === null) {
            $failures[] = 'chua xac minh dong bo: thieu thoi diem bat dau hoac thoi luong cua luong hinh hay tieng';
        } else {
            if (abs($audioStart - $videoStart) > $drift) {
                $failures[] = sprintf('tieng bat dau o %d ms, hinh o %d ms, lech qua %d ms', $audioStart, $videoStart, $drift);
            }

            if (abs(($audioStart + $audioMs) - ($videoStart + $videoMs)) > $drift) {
                $failures[] = sprintf('tieng ket thuc o %d ms, hinh o %d ms, lech qua %d ms', $audioStart + $audioMs, $videoStart + $videoMs, $drift);
            }
        }

        return [
            'met' => $failures === [],
            'failures' => $failures,
            'video_ms' => $videoMs,
            'audio_ms' => $audioMs,
            'duration_basis' => $basis,
            'video_start_ms' => $videoStart,
            'audio_start_ms' => $audioStart,
            'expected' => array_map('intval', $target),
            'audio_content' => 'unverified: one audio stream proves sound exists, not that it holds no music or speech — listen before approval',
        ];
    }

    private static function streamMs(mixed $stream): ?int
    {
        return self::milliseconds(is_array($stream) ? ($stream['duration'] ?? null) : null);
    }

    private static function startMs(mixed $stream): ?int
    {
        return self::milliseconds(is_array($stream) ? ($stream['start_time'] ?? null) : null);
    }

    private static function milliseconds(mixed $seconds): ?int
    {
        return is_int($seconds) || is_float($seconds) ? (int) round($seconds * 1000) : null;
    }

    private function earlierRun(string $requestHash): ?string
    {
        foreach (glob($this->evidenceDir().DIRECTORY_SEPARATOR.config('video.omni.evidence_prefix').'*.json') ?: [] as $file) {
            $record = json_decode((string) @file_get_contents($file), true);

            if (! is_array($record) || ! is_string($record['request']['request_sha256'] ?? null)) {
                return 'Ho so '.basename($file).' khong doc duoc — doi soat tay truoc khi gui, vi no co the la mot luot da tinh phi. Chua gui gi.';
            }

            if ($record['request']['request_sha256'] !== $requestHash) {
                continue;
            }

            $status = (string) ($record['status'] ?? '');

            if ($status === self::SUBMIT_FAILED) {
                continue;
            }

            if (in_array($status, [self::MEASURED, self::MEASURE_FAILED], true) && $this->option('repeat')) {
                continue;
            }

            return match (true) {
                in_array($status, self::RESUMABLE, true) => 'Request nay da gui ('.basename($file).', '.$status.') — dung --resume='.basename($file).', khong gui lai.',
                in_array($status, [self::SUBMITTING, self::AMBIGUOUS], true) => 'Request nay co ket qua CHUA RO ('.basename($file).') — kiem tra tay, khong gui lai.',
                default => 'Request nay da do xong ('.basename($file).'). Gui lai la tra tien them: them --repeat neu that su muon.',
            };
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function save(string $file, array $record): void
    {
        if (! $this->write($file, $record)) {
            $this->error('Khong ghi duoc ho so '.$file.': trang thai '.json_encode($record['status'] ?? null)
                .', ma file '.json_encode($record['submit']['job_id'] ?? null).' — giu dong nay.');
        }
    }

    /** @param array<string, mixed> $record */
    private function write(string $file, array $record): bool
    {
        $temp = $file.'.tmp';

        try {
            $json = json_encode($record, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);

            if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)
                || ! hash_equals($hash, (string) @hash_file('sha256', $temp))
                || ! @rename($temp, $file)) {
                @unlink($temp);

                return false;
            }

            clearstatcache(true, $file);

            return hash_equals($hash, (string) @hash_file('sha256', $file));
        } catch (\Throwable) {
            @unlink($temp);

            return false;
        }
    }

    /** @return resource|null */
    private function lock(string $requestHash)
    {
        $handle = @fopen($this->evidenceDir().DIRECTORY_SEPARATOR.config('video.omni.evidence_prefix').'lock_'.$requestHash.'.lock', 'c');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function recordPath(): string
    {
        return $this->evidenceDir().DIRECTORY_SEPARATOR.config('video.omni.evidence_prefix').now()->format('Y_m_d_His').'_'.Str::lower(Str::random(6)).'.json';
    }

    private function evidenceDir(): string
    {
        return rtrim((string) config('video.provider_evidence_dir'), '/\\');
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $entry
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function images(string $projectId, array $ids, array $entry, bool $allowNone = false): array
    {
        $ids = array_values(array_filter(array_map('trim', $ids), static fn (string $id): bool => $id !== ''));

        if ($ids === [] && $allowNone) {
            return [[], null];
        }

        if ($projectId === '' || $ids === []) {
            return [[], 'Can --project va it nhat mot --artifact.'];
        }

        if (count($ids) > (int) $entry['controls']['max_reference_images'] || count($ids) !== count(array_unique($ids))) {
            return [[], 'Toi da '.$entry['controls']['max_reference_images'].' anh, khong lap lai.'];
        }

        $images = [];

        foreach ($ids as $order => $id) {
            $artifact = VideoArtifact::query()->whereKey($id)->where('project_id', $projectId)->first();
            $image = $artifact?->design_image_id === null ? null : VideoDesignImage::query()
                ->whereKey($artifact->design_image_id)->where('project_id', $projectId)->first();

            if ($artifact === null || $image === null) {
                return [[], "Artifact {$id} khong thuoc du an {$projectId} hoac khong gan voi anh thiet ke nao."];
            }

            $status = $image->status instanceof DesignImageStatus ? $image->status->value : (string) $image->status;

            if ($status !== DesignImageStatus::APPROVED->value || (string) $image->selected_artifact_id !== (string) $artifact->id) {
                return [[], "Artifact {$id} khong phai anh DA DUYET dang duoc chon cua {$image->image_code}."];
            }

            $mime = (string) $artifact->mime_type;
            $disk = Storage::disk((string) $artifact->storage_disk);
            $bytes = $disk->exists((string) $artifact->storage_path) ? (string) $disk->get((string) $artifact->storage_path) : '';

            if (! in_array($mime, self::IMAGE_MIMES, true) || $bytes === '' || strlen($bytes) > (int) config('video.omni.max_reference_bytes')) {
                return [[], "Artifact {$id}: mime {$mime} hoac kich thuoc ".strlen($bytes).' byte ngoai gioi han.'];
            }

            if (! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
                return [[], "Artifact {$id}: file tren dia khong khop sha256 da ghi."];
            }

            $sent = GeminiOmniVideoClient::reference($bytes, $mime, (array) config('video.omni.reference'));

            if (! $sent['ok']) {
                return [[], "Artifact {$id}: khong tao duoc ban gui API — {$sent['error']}."];
            }

            $images[] = [
                'order' => $order,
                'kind' => 'artifact',
                'artifact_id' => (string) $artifact->id,
                'image_code' => (string) $image->image_code,
                'image_type' => (string) $image->image_type,
                'original' => [
                    'sha256' => (string) $artifact->sha256,
                    'mime' => $mime,
                    'bytes' => strlen($bytes),
                    'width' => $artifact->width,
                    'height' => $artifact->height,
                ],
                'sent' => [
                    'sha256' => $sent['sent_sha256'],
                    'mime' => $sent['mime'],
                    'bytes' => $sent['sent_bytes'],
                    'width' => $sent['sent_width'],
                    'height' => $sent['sent_height'],
                ],
                'transform' => $sent['transform'],
                'mime' => (string) $sent['mime'],
                'base64' => (string) $sent['base64'],
            ];
        }

        return [$images, null];
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    private function sourceRun(string $name, bool $needsStore): array
    {
        $file = $this->evidenceDir().DIRECTORY_SEPARATOR.basename($name);
        $record = str_starts_with(basename($file), (string) config('video.omni.evidence_prefix')) ? $this->readRecord($file) : null;

        if ($record === null) {
            return [null, 'khong doc duoc ho so nguon '.$file.'.'];
        }

        if (($record['status'] ?? null) !== self::MEASURED || ($record['download']['ok'] ?? false) !== true) {
            return [null, 'ho so nguon chua do xong (trang thai '.json_encode($record['status'] ?? null).').'];
        }

        if ($needsStore && (($record['request']['store'] ?? false) !== true || ! is_string($record['interaction_id'] ?? null))) {
            return [null, 'ho so nguon gui truoc khi luu interaction (store) hoac khong co interaction id — khong Extend multi-turn duoc.'];
        }

        $disk = Storage::disk((string) config('video.veo.disk'));
        $path = (string) ($record['video_path'] ?? '');
        $sha = (string) ($record['download']['sha256'] ?? '');

        if ($path === '' || ! $disk->exists($path) || ! hash_equals($sha, hash('sha256', (string) $disk->get($path)))) {
            return [null, 'video nguon '.$path.' khong con hoac khong khop sha256 da ghi.'];
        }

        return [[
            'record' => basename($file),
            'interaction_id' => $record['interaction_id'] ?? null,
            'video_path' => $path,
            'video_sha256' => $sha,
            'video_ms' => $record['target']['video_ms'] ?? null,
        ], null];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function frame(FfmpegRunner $ffmpeg, array $source, int $order): array
    {
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'omni_frame_'.Str::lower(Str::random(8)).'.png';
        $result = $ffmpeg->run(['-v', 'error', '-sseof', '-0.1', '-i', Storage::disk((string) config('video.veo.disk'))->path($source['video_path']),
            '-frames:v', '1', '-y', $out]);
        $bytes = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($out);

        if (! $result->successful || $bytes === '') {
            return [null, 'khong cat duoc khung cuoi cua '.$source['video_path'].': '.$result->tail(200)];
        }

        $size = @getimagesizefromstring($bytes);
        $sent = GeminiOmniVideoClient::reference($bytes, 'image/png', (array) config('video.omni.reference'));

        if (! $sent['ok']) {
            return [null, 'khung cuoi khong dung duoc lam tham chieu: '.$sent['error']];
        }

        return [[
            'order' => $order,
            'kind' => 'render_frame',
            'source_record' => $source['record'],
            'source_video_sha256' => $source['video_sha256'],
            'frame' => 'end-0.1s',
            'original' => [
                'sha256' => hash('sha256', $bytes),
                'mime' => 'image/png',
                'bytes' => strlen($bytes),
                'width' => is_array($size) ? $size[0] : null,
                'height' => is_array($size) ? $size[1] : null,
            ],
            'sent' => [
                'sha256' => $sent['sent_sha256'],
                'mime' => $sent['mime'],
                'bytes' => $sent['sent_bytes'],
                'width' => $sent['sent_width'],
                'height' => $sent['sent_height'],
            ],
            'transform' => $sent['transform'],
            'mime' => (string) $sent['mime'],
            'base64' => (string) $sent['base64'],
        ], null];
    }

    /** @param list<array<string, mixed>> $images */
    private function saveSent(array $images): bool
    {
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        foreach ($images as $image) {
            $bytes = (string) base64_decode($image['base64'], true);
            $path = trim((string) config('video.omni.canary_path'), '/').'/sent/'.$image['sent']['sha256'].'.'
                .($extensions[$image['sent']['mime']] ?? 'bin');

            try {
                $disk = Storage::disk((string) config('video.veo.disk'));
                $written = $disk->put($path, $bytes) === true
                    && hash_equals((string) $image['sent']['sha256'], hash('sha256', (string) $disk->get($path)));
            } catch (\Throwable) {
                $written = false;
            }

            if (! $written) {
                $this->error('Khong luu duoc ban gui API '.$path.' de duyet — chua gui gi.');

                return false;
            }

            $this->line('Ban gui API: '.$disk->path($path));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function plain(array $result): array
    {
        return array_map(static fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $result);
    }
}
