<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Spec\PhaseRelease;
use Illuminate\Console\Command;
use Throwable;

class VideoPhaseRelease extends Command
{
    protected $signature = 'video:phase-release
        {--category= : Slug category (chủ đề), bắt buộc}
        {--package= : Thư mục gói phát hành (có release.json), bắt buộc}
        {--apply : Ghi DB theo đúng kế hoạch đã xem trước}
        {--expected-plan= : plan_hash của bản xem trước đã duyệt, bắt buộc khi ghi}';

    protected $description = 'Phát hành một gói (schema mới + revision prompt mới kèm liên kết theo config) vào DB cho một category; gói chỉ là đầu vào, lúc chạy vẫn đọc DB';

    public function handle(): int
    {
        $category = trim((string) $this->option('category'));
        $package = trim((string) $this->option('package'));

        if ($category === '' || $package === '') {
            $this->error('Thiếu --category hoặc --package. Chưa ghi gì.');

            return self::FAILURE;
        }

        $release = PhaseRelease::fromConfig((array) config('video.superyacht'));

        try {
            $plan = $release->plan($category, $package);
        } catch (Throwable $e) {
            $this->error('Không lập được kế hoạch, chưa ghi gì: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Gói {$plan['package']['name']} ({$plan['package']['sha256']}) · category {$plan['category']['slug']} · workflow {$plan['workflow']} · bảng ghi: ai_json_schemas, ai_phases, ai_phase_schemas");

        foreach ($plan['schemas'] as $schema) {
            $this->line("Schema {$schema['label']}: ".($schema['action'] === 'create' ? 'tạo mới, published' : 'đã có đúng nội dung, dùng lại')." (sha256 {$schema['sha256']})");
        }

        foreach ($plan['phases'] as $phase) {
            $this->line('');
            $this->line("<options=bold>{$phase['code']}</> revision {$phase['current_revision']} → ".($phase['action'] === 'revise' ? 'revision mới' : 'giữ nguyên (đã khớp gói)'));
            $this->line("  prompt: {$phase['current_sha256']} → {$phase['sha256']}");

            foreach ($phase['links'] as $line) {
                $this->line("  liên kết: {$line}");
            }
        }

        $this->line('');
        $this->line('plan_hash: '.$plan['plan_hash']);

        if (! $this->option('apply')) {
            $this->info('Chỉ xem trước: không ghi DB. Để ghi: --apply --expected-plan='.$plan['plan_hash']);

            return self::SUCCESS;
        }

        $expected = trim((string) $this->option('expected-plan'));

        if ($expected === '') {
            $this->error('Thiếu --expected-plan: chỉ ghi đúng kế hoạch đã duyệt ở bản xem trước. Chưa ghi gì.');

            return self::FAILURE;
        }

        try {
            $result = $release->apply($category, $package, $expected);
        } catch (Throwable $e) {
            $this->error('Không ghi, không có dòng nào được ghi: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['backup'] !== null) {
            $this->line("Sao lưu trước khi ghi: {$result['backup']}");
        }

        foreach ($result['schemas'] as $label) {
            $this->line("Đã publish schema {$label}.");
        }

        foreach ($result['phases'] as $row) {
            $this->line("Đã publish {$row['code']} revision {$row['revision']} ({$row['id']}).");
        }

        $this->info($result['changed'] ? 'Đã phát hành gói; resolver đã kiểm các action liên quan.' : 'Gói đã có sẵn trong DB: không ghi thêm gì.');

        return self::SUCCESS;
    }
}
