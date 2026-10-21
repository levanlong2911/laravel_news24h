<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Video\Superyacht\Spec\ActionContract;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Spec\TaskContext;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class VideoSpecCheckContracts extends Command
{
    public const FIXTURE_PROJECT = 'contract-fixture-project';

    protected $signature = 'video:spec-check-contracts {--category= : Slug category (chủ đề), bắt buộc}';

    protected $description = 'Kiểm hợp đồng theo DB, chỉ đọc: resolve và phát lại manifest của mọi tác vụ, rồi chạy fixture envelope / slot trong resources/ai/superyacht/contracts/fixtures';

    /** @var array<string, ResolvedPhase> */
    private array $bundles = [];

    public function handle(): int
    {
        $this->bundles = [];
        $slug = trim((string) $this->option('category'));
        $category = Category::query()->where('slug', $slug)->get();

        if ($slug === '' || $category->count() !== 1) {
            $this->error('Cần --category trỏ đúng một category.');

            return self::FAILURE;
        }

        $config = (array) config('video.superyacht');
        $resolver = PhaseResolver::fromConfig($config);
        $categoryId = (string) $category->first()->id;
        $workflow = $config['contracts']['categories'][$slug] ?? null;
        $actions = (array) ($config['contracts']['workflows'][$workflow]['actions'] ?? []);
        $files = glob(rtrim((string) $config['contract_fixture_dir'], '/\\').'/*.json') ?: [];
        $fixtures = [];
        $failures = [];

        foreach ($files as $file) {
            try {
                $fixtures[basename($file, '.json')] = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $failures[] = basename($file).": {$e->getMessage()}";
            }
        }

        foreach ($actions as $action => $definition) {
            try {
                $keyed = array_filter(array_map(
                    static fn (?array $output): ?string => $output['items']['key'] ?? null,
                    ActionContract::normalise((string) $action, (array) $definition)['outputs'],
                )) !== [];
                $contexts = $keyed
                    ? array_values(array_unique(array_filter(array_map(
                        static fn (object $fixture): ?string => ($fixture->action ?? null) === $action && isset($fixture->context) ? json_encode($fixture->context) : null,
                        $fixtures,
                    ))))
                    : [null];

                if ($contexts === []) {
                    throw new RuntimeException('tác vụ xử lý từng bản ghi nhưng không fixture nào khai context');
                }

                foreach ($contexts as $context) {
                    $first = $this->bundle($resolver, $categoryId, (string) $action, $context);
                    $again = $resolver->forCategory($categoryId, (string) $action, $context === null ? null : TaskContext::fromArray(json_decode($context, true)));
                    $replayed = $resolver->fromManifest(json_decode(json_encode($first->manifest(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

                    if ($again->bundleHash() !== $first->bundleHash() || $replayed->bundleHash() !== $first->bundleHash()) {
                        $failures[] = "{$action}: resolve hai lần hoặc phát lại manifest ra hash khác";
                    }

                    $this->line(sprintf(
                        '%s%s: %s · %s',
                        $action,
                        $context === null ? '' : ' ['.$first->manifest()['context']['input_id'].']',
                        $first->ready() ? 'đủ hợp đồng dữ liệu' : 'chưa đủ hợp đồng ('.implode('; ', $first->mismatches()).')',
                        $first->bundleHash(),
                    ));
                }
            } catch (Throwable $e) {
                $failures[] = "{$action}: {$e->getMessage()}";
            }
        }

        foreach ($fixtures as $name => $fixture) {
            try {
                $problem = $this->check($resolver, $categoryId, $fixture, rtrim((string) $config['schema_dir'], '/\\').'/fixtures');
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }

            if ($problem !== null) {
                $failures[] = "{$name}: {$problem}";
            }
        }

        foreach ($failures as $failure) {
            $this->line("  <fg=red>x</> {$failure}");
        }

        $this->line(count($actions).' tác vụ, '.count($files).' fixture hợp đồng.');

        if ($failures !== []) {
            $this->error(count($failures).' sai lệch.');

            return self::FAILURE;
        }

        $this->info('Mọi tác vụ phát lại đúng hash; mọi fixture cư xử đúng kỳ vọng.');

        return self::SUCCESS;
    }

    private function bundle(PhaseResolver $resolver, string $categoryId, string $action, ?string $context): ResolvedPhase
    {
        return $this->bundles[$action.'|'.($context ?? '')] ??= $resolver->forCategory(
            $categoryId,
            $action,
            $context === null ? null : TaskContext::fromArray(json_decode($context, true, 512, JSON_THROW_ON_ERROR)),
        );
    }

    private function check(PhaseResolver $resolver, string $categoryId, object $fixture, string $schemaFixtures): ?string
    {
        $context = isset($fixture->context) ? json_encode($fixture->context) : null;
        $bundle = $this->bundle($resolver, $categoryId, (string) ($fixture->action ?? ''), $context);
        $expected = (array) ($fixture->expect->violations ?? []);

        if (isset($fixture->slot)) {
            $payload = json_encode($this->expand($fixture->payload, $schemaFixtures), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $report = $bundle->validateSlot($fixture->slot->role, $fixture->slot->slot, $payload);
            $applicable = null;
        } else {
            $output = json_encode($this->expand($fixture->output, $schemaFixtures), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $result = $bundle->checkOutput($output, self::FIXTURE_PROJECT);
            $report = $result['report'];
            $applicable = $result['applicable'];
        }

        $codes = $report->codes();
        $unexpected = array_filter($codes, static fn (string $code): bool => array_filter($expected, static fn (string $prefix): bool => str_starts_with($code, $prefix)) === []);
        $absent = array_filter($expected, static fn (string $prefix): bool => array_filter($codes, static fn (string $code): bool => str_starts_with($code, $prefix)) === []);

        if ($unexpected !== [] || $absent !== []) {
            return 'mong ['.implode(', ', $expected).'], nhận ['.implode(', ', $codes).']: '.implode('; ', array_slice($report->messages(), 0, 3));
        }

        if (property_exists($fixture->expect, 'applicable') && $applicable !== $fixture->expect->applicable) {
            return 'applicable mong '.json_encode($fixture->expect->applicable).', nhận '.json_encode($applicable);
        }

        return null;
    }

    private function expand(mixed $node, string $schemaFixtures): mixed
    {
        if (is_object($node)) {
            $fields = get_object_vars($node);

            if (array_keys($fields) === ['$fixture'] && is_string($fields['$fixture'])) {
                if (preg_match('/^[A-Za-z]+\/[A-Za-z0-9_\-]+\.json$/', $fields['$fixture']) !== 1) {
                    throw new RuntimeException("đường dẫn fixture không hợp lệ: {$fields['$fixture']}");
                }

                return json_decode((string) file_get_contents($schemaFixtures.'/'.$fields['$fixture']), false, 512, JSON_THROW_ON_ERROR);
            }

            foreach ($fields as $key => $value) {
                $node->{$key} = $this->expand($value, $schemaFixtures);
            }

            return $node;
        }

        return is_array($node) ? array_map(fn (mixed $item): mixed => $this->expand($item, $schemaFixtures), $node) : $node;
    }
}
