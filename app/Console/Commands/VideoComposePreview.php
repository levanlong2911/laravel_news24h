<?php

namespace App\Console\Commands;

use App\Models\VideoRender;
use App\Video\Media\ClipConcatenator;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;

/**
 * Ghep thu vai clip DA RENDER de xem duong FFmpeg cua Laravel chay the nao.
 *
 * Day la CONG CU CHAN DOAN, khong phai final composition:
 *   - thu tu ghep la thu tu tren dong lenh, KHONG phai thu tu scene;
 *   - khong ghi DB, khong tao `VideoArtifact`, khong doi trang thai gi;
 *   - khong goi Python, khong dung toi `VideoFinal`.
 *
 * `-safe 0` trong `ClipConcatenator` chi an toan vi duong dan di vao no da qua bon
 * cua o day: dung loai render, da succeeded, file con tren dia, va sha256 khop
 * `primary_artifact_hash`.
 */
class VideoComposePreview extends Command
{
    protected $signature = 'video:compose-preview
        {--renders= : Danh sach render UUID, ngan bang dau phay, THU TU LA THU TU GHEP}
        {--out= : Ten tep .mp4, chi ten — khong duoc chua duong dan}';

    protected $description = 'Ghep thu vai clip da render thanh mot file xem truoc, khong ghi DB';

    public function handle(ClipConcatenator $concatenator, FilesystemFactory $storage): int
    {
        $ids = $this->renderIds();

        if ($ids === null) {
            return self::FAILURE;
        }

        $disk = $storage->disk((string) config('video.veo.disk'));
        $files = [];

        foreach ($ids as $position => $id) {
            $file = $this->verifiedFile($id, $disk, $position + 1);

            if ($file === null) {
                return self::FAILURE;
            }

            $files[] = $file;
        }

        $output = $this->outputPath();

        if ($output === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Thu tu ghep la THU TU TREN DONG LENH, khong phai thu tu scene.');
        $this->warn('Day la cong cu CHAN DOAN — khong ghi DB, khong tao artifact.');
        $this->newLine();

        foreach ($files as $i => $file) {
            $this->line(sprintf('  %d. %s', $i + 1, $file));
        }

        $this->newLine();

        $result = $concatenator->concat($files, $output);

        if (! $result->successful) {
            $this->error('Tu choi: '.$result->code);

            foreach ($result->reasons as $reason) {
                $this->line('  · '.$reason);
            }

            $this->newLine();

            return self::FAILURE;
        }

        $this->info('Da ghep: '.$output);
        $this->line(sprintf('  thoi luong : %dms  (tong cac phan %dms)',
            $result->durationMs, $result->expectedDurationMs));
        $this->line(sprintf('  khung hinh : %d  (tong cac phan %d)',
            $result->videoFrames, $result->expectedVideoFrames));
        $this->line(sprintf('  kich thuoc : %dx%d  ·  %d bytes', $result->width, $result->height, $result->bytes));
        $this->line('  sha256     : '.$result->sha256);
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function renderIds(): ?array
    {
        $ids = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('renders')),
        )));

        if (count($ids) < 2) {
            $this->error('Can it nhat hai render: --renders=id1,id2');

            return null;
        }

        if (count($ids) !== count(array_unique($ids))) {
            // Ghep mot clip voi chinh no gan nhu luon la go nham. Neu that su muon
            // thi phai noi ro y do bang mot co khac, khong de no lot qua im lang.
            $this->error('Co id bi lap trong --renders');

            return null;
        }

        return $ids;
    }

    private function verifiedFile(string $id, $disk, int $position): ?string
    {
        $render = VideoRender::query()->whereKey($id)->first();
        $label = sprintf('clip %d (%s)', $position, $id);

        if ($render === null) {
            $this->error($label.': khong thay render');

            return null;
        }

        if ($render->render_kind !== 'video') {
            $this->error($label.': render_kind la "'.$render->render_kind.'", khong phai video');

            return null;
        }

        if ($render->execution_status !== RenderStatus::SUCCEEDED) {
            $this->error($label.': execution_status la "'
                .($render->execution_status?->value ?? 'null').'", chua succeeded');

            return null;
        }

        $path = (string) $render->artifact_path;

        if ($path === '' || ! $disk->exists($path)) {
            $this->error($label.': file khong con tren dia — '.$path);

            return null;
        }

        $full = $disk->path($path);
        $hash = (string) hash_file('sha256', $full);

        // Cua cuoi, va la cua quan trong nhat: sau day duong dan se di vao ffmpeg
        // voi `-safe 0`. Mot file da bi thay the sau khi render khong duoc phep
        // lot qua chi vi no van nam dung cho.
        if (! hash_equals((string) $render->primary_artifact_hash, $hash)) {
            $this->error($label.': sha256 lech voi primary_artifact_hash');

            return null;
        }

        return $full;
    }

    private function outputPath(): ?string
    {
        $name = trim((string) $this->option('out'));

        if ($name === '') {
            // UUID chu khong phai moc thoi gian theo giay: hai lan chay trong cung
            // mot giay se de len nhau.
            $name = 'preview_'.Str::uuid()->toString().'.mp4';
        }

        // TU CHOI chu khong cat am tham: `basename()` se lang le doi cho ghi, roi
        // nguoi chay di tim file o noi ho da go.
        //
        // Kiem `..` TRUOC dau phan cach: `../x.mp4` co ca hai, ma "di len tren mot
        // cap" moi la dieu nguoi doc can biet, khong phai "co dau /".
        if (str_contains($name, '..')) {
            $this->error('--out khong duoc chua ".."');

            return null;
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            $this->error('--out chi nhan TEN TEP, khong duoc chua duong dan');

            return null;
        }

        if (! str_ends_with(strtolower($name), '.mp4')) {
            $this->error('--out phai ket thuc bang .mp4');

            return null;
        }

        $dir = (string) config('video.veo.preview_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Khong tao duoc thu muc xem truoc: '.$dir);

            return null;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$name;

        if (is_file($path)) {
            $this->error('Output da ton tai, khong ghi de: '.$name);

            return null;
        }

        return $path;
    }
}
