<?php

namespace App\Console\Commands;

use App\Video\FinalComposition\CompositionExecutor;
use Illuminate\Console\Command;

/**
 * Ghep thu mot ban final tu file cuc bo, KHONG dung DB va khong goi provider.
 *
 * Ten co `preview` de khong lan voi duong final that su cua muc sau: man hinh Final
 * Composition van chua co nut nao noi vao day.
 */
class VideoComposeFinalPreview extends Command
{
    protected $signature = 'video:compose-final-preview
        {--manifest= : duong dan file JSON mo ta ban ghep}
        {--dry-run : in filter graph va mang doi so roi dung}';

    protected $description = 'Ghep thu cac clip cuc bo thanh mot video, theo manifest JSON';

    public function handle(CompositionExecutor $executor): int
    {
        $manifestPath = (string) $this->option('manifest');

        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('can --manifest tro toi mot file JSON co that');

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            $this->error('manifest khong phai JSON hop le');

            return self::FAILURE;
        }

        $sources = [];

        foreach ((array) ($manifest['clips'] ?? []) as $clip) {
            $sources[] = (string) (is_array($clip) ? ($clip['path'] ?? '') : '');
        }

        if ($this->option('dry-run')) {
            return $this->describe($executor, $manifest, $sources);
        }

        $result = $executor->execute($manifest, $sources);

        if (! $result->successful) {
            $this->error('khong ghep duoc:');

            foreach ($result->reasons as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $this->info('Ghep xong: '.$result->path);
        $this->table(['do', 'gia tri'], [
            ['khung hinh', (string) $result->frames],
            ['mau tieng', (string) $result->audioSamples],
            ['dung luong', number_format((float) $result->bytes).' byte'],
            ['sha256', (string) $result->sha256],
        ]);

        return self::SUCCESS;
    }

    /**
     * In graph ma khong encode.
     *
     * Di qua `CompositionExecutor::describe()` chu khong goi thang builder: dry-run
     * phai chiu dung cac buoc kiem dau vao cua luot chay that — gioi han thu muc
     * goc, han muc byte, ngan sach thoi gian — neu khong no se bao hop le cho mot
     * dau vao ma luot that se tu choi.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $sources
     */
    private function describe(CompositionExecutor $executor, array $manifest, array $sources): int
    {
        $described = $executor->describe($manifest, $sources);

        if (! $described['ok']) {
            foreach ($described['reasons'] as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }

        $this->line('khung hinh ky vong: '.$described['frames']);
        $this->line('mau tieng ky vong:  '.$described['samples']);
        $this->newLine();
        $this->line((string) $described['graph']);
        $this->newLine();
        $this->line(implode(' ', (array) $described['arguments']));

        return self::SUCCESS;
    }
}
