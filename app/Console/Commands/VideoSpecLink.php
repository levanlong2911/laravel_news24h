<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Spec\PhaseRevisions;
use Illuminate\Console\Command;
use Throwable;

class VideoSpecLink extends Command
{
    protected $signature = 'video:spec-link
        {--category= : Slug category (chủ đề), bắt buộc}
        {--dry-run : Chỉ xem trước, không ghi DB}
        {--expected-plan= : plan_hash của bản xem trước đã duyệt, bắt buộc khi ghi}';

    protected $description = 'Áp liên kết phase–schema đã khai trong config cho một category: mỗi phase có thay đổi nhận một revision mới, prompt giữ nguyên';

    public function handle(): int
    {
        $category = trim((string) $this->option('category'));

        if ($category === '') {
            $this->error('Thiếu --category. Chưa ghi gì.');

            return self::FAILURE;
        }

        $revisions = PhaseRevisions::fromConfig((array) config('video.superyacht'));

        try {
            $plan = $revisions->linkPlan($category);
        } catch (Throwable $e) {
            $this->error('Không lập được kế hoạch, chưa ghi gì: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Category {$plan['category']['slug']} ({$plan['category']['id']}) · workflow {$plan['workflow']} · bảng ghi: ai_phases, ai_phase_schemas");

        foreach ($plan['deferred'] as $line) {
            $this->warn("Chờ gói phát hành (schema chưa publish, không ghi liên kết này): {$line}");
        }

        if ($plan['changes'] === []) {
            $this->info('Liên kết của mọi phase đã khớp config: không có revision nào cần tạo.');

            return self::SUCCESS;
        }

        foreach ($plan['changes'] as $change) {
            $this->line('');
            $this->line("<options=bold>{$change['phase_code']}</> revision {$change['current_revision']} → {$change['next_revision']} (prompt giữ nguyên từng byte)");

            foreach (['added' => '+ thêm', 'removed' => '- bỏ', 'changed' => '~ đổi'] as $key => $label) {
                foreach ($change[$key] as $line) {
                    $this->line("  {$label}: {$line}");
                }
            }
        }

        $this->line('');
        $this->line(count($plan['changes']).' phase sẽ có revision mới. plan_hash: '.$plan['plan_hash']);

        if ($this->option('dry-run')) {
            $this->info('Chế độ xem trước: không ghi DB. Để ghi: --expected-plan='.$plan['plan_hash']);

            return self::SUCCESS;
        }

        $expected = trim((string) $this->option('expected-plan'));

        if ($expected === '') {
            $this->error('Thiếu --expected-plan: chỉ ghi đúng kế hoạch đã duyệt ở chế độ xem trước. Chưa ghi gì.');

            return self::FAILURE;
        }

        try {
            $written = $revisions->applyLinkPlan($category, $expected, 'video:spec-link');
        } catch (Throwable $e) {
            $this->error('Không ghi, không có dòng nào được ghi: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($written as $row) {
            $this->line("Đã publish {$row['phase_code']} revision {$row['revision']} ({$row['id']}).");
        }

        $this->info('Đã áp liên kết vào thư viện tài liệu; chưa tích hợp pipeline.');

        return self::SUCCESS;
    }
}
