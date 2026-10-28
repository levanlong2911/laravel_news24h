<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Import\SpecImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class VideoSpecImport extends Command
{
    protected $signature = 'video:spec-import
        {--category= : Slug category (chủ đề) nhận bộ phase, bắt buộc}
        {--dry-run : Chỉ xem trước, không ghi DB}
        {--expected-spec-sha256= : SHA-256 của file spec đã xác nhận, bắt buộc khi ghi}';

    protected $description = 'Nạp nguyên văn tài liệu spec v6.3 và 8 JSON Schema vào ai_phases, ai_json_schemas, ai_phase_schemas';

    public function handle(): int
    {
        $category = trim((string) $this->option('category'));

        if ($category === '') {
            $this->error('Thiếu --category: phải chỉ rõ chủ đề nhận bộ phase. Chưa ghi gì.');

            return self::FAILURE;
        }

        $importer = SpecImporter::fromConfig((array) config('video.superyacht'), $category);

        try {
            $plan = $importer->plan();
        } catch (Throwable $e) {
            $this->error('Không lập được kế hoạch nạp, chưa ghi gì: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->show($plan);
        $state = $importer->existing($plan);

        foreach ($state['conflicts'] as $line) {
            $this->warn('Đã có hoặc xung đột trong DB: '.$line);
        }

        if ($state['reuse'] !== []) {
            $this->line(count($state['reuse']).' schema đã có trong namespace '.$plan['namespace'].' khớp nội dung, hash và dependency: sẽ dùng lại, không ghi thêm.');
        }

        $expected = strtolower(trim((string) $this->option('expected-spec-sha256')));

        if ($this->option('dry-run')) {
            if ($expected !== '') {
                $this->line(hash_equals($plan['spec']['sha256'], $expected) ? 'Hash đã xác nhận: khớp.' : '<fg=red>Hash đã xác nhận: KHÔNG khớp.</>');
            }

            $this->info('Chế độ xem trước: không ghi DB.');

            return self::SUCCESS;
        }

        if ($expected === '') {
            $this->error('Thiếu --expected-spec-sha256: phải xác nhận hash tài liệu trước khi ghi. Chưa ghi gì.');

            return self::FAILURE;
        }

        if ($state['conflicts'] !== []) {
            $this->error('Category/workflow đã có dữ liệu hoặc schema xung đột: dừng, không ghi đè, không tạo revision mới.');

            return self::FAILURE;
        }

        try {
            $report = $importer->import($plan, $expected);
        } catch (Throwable $e) {
            $this->error('Không nạp, không có dòng nào được ghi: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Đã nạp workflow %s cho category %s: %d mục ai_phases (%s), %d schema namespace %s (dùng lại %d), %d liên kết. Hash nguồn %s.',
            $report['workflow_code'],
            $report['category'],
            $report['phases'],
            $this->kinds($report['kinds']),
            $report['schemas'],
            $report['namespace'],
            $report['reused'],
            $report['links'],
            $report['spec_sha256'],
        ));

        foreach ($report['dependencies'] as $schema => $targets) {
            foreach ($targets as $target) {
                $this->line("Dependency đã khoá: {$schema} → {$target}");
            }
        }

        $this->line('Chờ cấu hình liên kết: '.($report['pending'] === [] ? 'không còn' : implode(', ', $report['pending'])));
        $this->info('Đã lưu tài liệu; chưa tích hợp pipeline.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $plan */
    private function show(array $plan): void
    {
        $this->line("Category: {$plan['category']['slug']} ({$plan['category']['id']}) · workflow {$plan['workflow_code']} · namespace schema {$plan['namespace']}");
        $this->line("Nguồn: {$plan['spec']['source_file']} · {$plan['spec']['bytes']} byte · sha256 {$plan['spec']['sha256']}");

        $this->table(
            ['#', 'phase_code', 'kind', 'Tên', 'byte', 'sha256', 'needs', 'song song', 'dừng'],
            array_map(static fn (array $row): array => [
                $row['sort_order'],
                $row['phase_code'],
                $row['kind'],
                Str::limit($row['name'], 46),
                strlen($row['prompt_content']),
                substr($row['content_sha256'], 0, 12),
                $row['needs'] ?? '',
                $row['parallel_with'] ?? '',
                Str::limit((string) $row['stop'], 30),
            ], $plan['phases']),
        );
        $this->line(count($plan['phases']).' mục: '.$this->kinds(array_count_values(array_column($plan['phases'], 'kind'))).'.');

        $this->table(
            ['schema', 'version', 'name', 'byte', 'sha256', '$id', 'dependencies'],
            array_map(static fn (array $row): array => [
                $row['schema_key'],
                $row['version'],
                $row['name'],
                strlen($row['schema_content']),
                substr($row['schema_sha256'], 0, 12),
                $row['json_schema_id'],
                implode(', ', array_keys($row['dependencies'])),
            ], $plan['schemas']),
        );

        $this->line('Liên kết phase–schema đã xác nhận (video.superyacht.contracts.workflows.<workflow>.links):'.count($plan['links']));
        $this->line('Chờ cấu hình liên kết: '.($plan['pending'] === [] ? 'không còn' : implode(', ', $plan['pending'])));
        $this->line('Liên kết chờ gói phát hành (schema không có trong spec, chạy video:phase-release sau khi nhập): '.($plan['deferred_links'] === [] ? 'không có' : implode(', ', $plan['deferred_links'])));
    }

    /** @param array<string, int> $kinds */
    private function kinds(array $kinds): string
    {
        return implode(', ', array_map(static fn (string $kind, int $count): string => "{$count} {$kind}", array_keys($kinds), $kinds));
    }
}
