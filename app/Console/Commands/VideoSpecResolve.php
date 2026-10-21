<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Spec\ActionContract;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\TaskContext;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class VideoSpecResolve extends Command
{
    protected $signature = 'video:spec-resolve
        {project : ID của video project}
        {action : Khoá tác vụ, ví dụ spec.design}
        {--input-id= : Tác vụ xử lý từng bản ghi (vd spec.prompt_compile): ID bản ghi đầu vào, vd shot_id}
        {--input-revision= : Revision của bản ghi đầu vào}
        {--input-sha256= : Hash sha256:<64 hex> của bản ghi đầu vào}
        {--json : In nguyên manifest}';

    protected $description = 'Xem trước gói hợp đồng (hướng dẫn chung, prompt phase, schema, manifest) của một tác vụ cho một project; chỉ đọc, không gọi AI';

    public function handle(): int
    {
        $config = (array) config('video.superyacht');
        $resolver = PhaseResolver::fromConfig($config);
        $project = (string) $this->argument('project');
        $action = (string) $this->argument('action');

        try {
            $context = $this->taskContext($config, $project, $action);
            $bundle = $resolver->forProject($project, $action, $context);
            $replayed = $resolver->fromManifest($bundle->manifest());
        } catch (Throwable $e) {
            $this->error('Không lấy được gói: '.$e->getMessage());

            return self::FAILURE;
        }

        $manifest = $bundle->manifest();
        $schemas = array_column($manifest['schemas'], null, 'id');
        $action = $manifest['action'];

        if ($manifest['context'] !== null) {
            $this->line("Ngữ cảnh tác vụ: {$manifest['context']['input_key']} {$manifest['context']['input_id']} · revision {$manifest['context']['input_revision']} · {$manifest['context']['input_sha256']} (do người gọi cung cấp, chưa đối soát nguồn production)");
        }

        $this->line("Project {$manifest['project_id']} · category {$manifest['category']['slug']} · workflow {$manifest['workflow']}");
        $this->line("Tác vụ {$action['key']} → {$action['phase']} · executor {$action['executor']} · model profile ".($action['model_profile'] ?? '—')." · hợp đồng {$action['contract_version']}");
        $this->line($bundle->ready()
            ? '<fg=green>Đủ hợp đồng dữ liệu</>: output slot '.implode(', ', array_keys($action['outputs'])).' khớp hợp đồng'.($action['executor'] === 'none' ? ' (executor none: chưa gọi AI)' : '')
            : '<fg=yellow>Chưa đủ hợp đồng dữ liệu</>: '.implode('; ', $bundle->mismatches()));

        $this->table(['#', 'phần', 'revision', 'byte', 'sha256'], array_map(static fn (array $part, int $order): array => [
            $order,
            $part['code'],
            $part['code'] === $manifest['phase']['code'] ? $manifest['phase']['revision'] : $manifest['instructions'][$order]['revision'],
            strlen($part['content']),
            substr(hash('sha256', $part['content']), 0, 12),
        ], $bundle->parts(), array_keys($bundle->parts())));

        $this->table(['role', 'slot', 'schema', 'pointer', 'mode', 'bắt buộc'], array_map(static fn (array $link): array => [
            $link['role'],
            $link['slot'],
            $schemas[$link['schema_id']]['key'].'@'.$schemas[$link['schema_id']]['version'],
            $link['data_pointer'] === '' ? '(cả bản ghi)' : $link['data_pointer'],
            $link['validation_mode'],
            $link['is_required'] ? 'có' : 'không',
        ], $manifest['links']));

        $this->table(['schema', 'namespace', 'sha256', 'dependencies'], array_map(static fn (array $schema): array => [
            $schema['key'].'@'.$schema['version'],
            $schema['namespace'],
            substr($schema['sha256'], 0, 12),
            implode(', ', array_map(static fn (string $id): string => isset($schemas[$id]) ? $schemas[$id]['key'] : $id, array_values($schema['dependencies']))),
        ], $manifest['schemas']));

        $this->line('bundle_hash: '.$bundle->bundleHash());
        $this->line('Phát lại từ manifest: '.($replayed->bundleHash() === $bundle->bundleHash() ? 'khớp' : '<fg=red>KHÔNG khớp</>'));

        if ($this->option('json')) {
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $this->info('Chỉ đọc: không ghi DB, không gọi AI.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $config */
    private function taskContext(array $config, string $project, string $action): ?TaskContext
    {
        $id = (string) $this->option('input-id');

        if ($id === '') {
            return null;
        }

        $workflows = (array) ($config['contracts']['workflows'] ?? []);
        $keys = [];

        foreach ($workflows as $workflow) {
            if (isset($workflow['actions'][$action])) {
                $outputs = ActionContract::normalise($action, (array) $workflow['actions'][$action])['outputs'];
                $keys = array_values(array_unique(array_filter(array_map(static fn (?array $output): ?string => $output['items']['key'] ?? null, $outputs))));
            }
        }

        if (count($keys) !== 1) {
            throw new RuntimeException("Action {$action} does not process one keyed input per task.");
        }

        return new TaskContext($project, $keys[0], $id, (int) $this->option('input-revision'), (string) $this->option('input-sha256'));
    }
}
