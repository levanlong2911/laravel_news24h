<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class VideoListGeminiModels extends Command
{
    private const VERSION_PATTERN = '/^v[0-9]+(?:alpha|beta)?$/';

    protected $signature = 'video:list-gemini-models
        {--api-version= : Chi doc mot phien ban, vi du v1beta}
        {--json= : Ghi payload tho ra path; bo trong thi dung resources/ai/providers}';

    protected $description = 'List the Gemini models this API key can see, per API version';

    public function handle(): int
    {
        $key = trim((string) config('video.gemini.api_key'));

        if ($key === '') {
            $this->error('config video.gemini.api_key rong — khong goi gi ca.');

            return self::FAILURE;
        }

        $versions = $this->versions();

        if ($versions === []) {
            $this->error('Khong co phien ban API hop le de doc.');

            return self::FAILURE;
        }

        $raw = [];

        foreach ($versions as $version) {
            $models = $this->fetch($key, $version);

            if ($models === null) {
                return self::FAILURE;
            }

            $raw[$version] = $models;
        }

        $this->report($raw);

        return $this->input->hasParameterOption('--json')
            ? $this->write($raw)
            : self::SUCCESS;
    }

    /** @return list<string> */
    private function versions(): array
    {
        $one = trim((string) $this->option('api-version'));

        if ($one !== '') {
            if (preg_match(self::VERSION_PATTERN, $one) !== 1) {
                $this->error('Phien ban API khong hop le: '.$one);

                return [];
            }

            return [$one];
        }

        $configured = config('video.gemini.versions');

        if (! is_array($configured)) {
            return [];
        }

        $valid = [];

        foreach ($configured as $version) {
            $version = trim((string) $version);

            if (preg_match(self::VERSION_PATTERN, $version) === 1) {
                $valid[] = $version;
            } else {
                $this->warn('Bo qua phien ban API khong hop le trong config: '.$version);
            }
        }

        return array_values(array_unique($valid));
    }

    /** @return list<array<string, mixed>>|null */
    private function fetch(string $key, string $version): ?array
    {
        $models = [];
        $pageToken = null;

        do {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $key])
                    ->timeout((int) config('video.gemini.timeout'))
                    ->retry(2, 1000, when: static fn (Throwable $e): bool => $e instanceof RequestException
                        && $e->response->status() === 429, throw: false)
                    ->get($this->endpoint($version), array_filter(
                        ['pageSize' => 200, 'pageToken' => $pageToken],
                        static fn ($value): bool => $value !== null,
                    ));
            } catch (ConnectionException $e) {
                $this->error('Khong noi duoc Gemini ('.$version.'): '.Str::limit($e->getMessage(), 200));

                return null;
            }

            if ($response->failed()) {
                $this->error(sprintf(
                    'Gemini %s tra %d: %s',
                    $version,
                    $response->status(),
                    Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
                ));

                return null;
            }

            $page = $response->json('models');
            $models = array_merge($models, is_array($page) ? $page : []);
            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');

        return $models;
    }

    private function endpoint(string $version): string
    {
        return rtrim((string) config('video.gemini.base_url'), '/').'/'.$version.'/models';
    }

    /** @param array<string, list<array<string, mixed>>> $raw */
    private function report(array $raw): void
    {
        $versions = array_keys($raw);
        $seen = [];

        foreach ($raw as $version => $models) {
            foreach ($models as $model) {
                $name = Str::after((string) ($model['name'] ?? ''), 'models/');

                if ($name === '') {
                    continue;
                }

                $methods = $model['supportedGenerationMethods'] ?? [];

                $seen[$name]['methods'] = array_values(array_unique(array_merge(
                    $seen[$name]['methods'] ?? [],
                    is_array($methods) ? array_map('strval', $methods) : [],
                )));
                $seen[$name]['versions'][] = $version;
            }
        }

        ksort($seen);

        $this->table(
            array_merge(['MODEL'], $versions, ['LONG-RUNNING', 'METHODS']),
            array_map(static function (string $name) use ($seen, $versions): array {
                $cells = [$name];

                foreach ($versions as $version) {
                    $cells[] = in_array($version, $seen[$name]['versions'], true) ? 'x' : '-';
                }

                $cells[] = in_array('predictLongRunning', $seen[$name]['methods'], true) ? 'yes' : '';
                $cells[] = implode(', ', $seen[$name]['methods']);

                return $cells;
            }, array_keys($seen)),
        );

        $this->line(sprintf('%d model tren %d phien ban.', count($seen), count($versions)));
        $this->warn('Thay model KHONG chung minh goi duoc: quota, vung va allowlist khong nam trong models.list.');
        $this->warn('models.list cung khong noi kho anh, so anh dau vao hay gia — nhung thu do lay tu tai lieu va render that.');
    }

    /** @param array<string, list<array<string, mixed>>> $raw */
    private function write(array $raw): int
    {
        $path = trim((string) $this->option('json'));

        if ($path === '') {
            $path = resource_path('ai/providers').DIRECTORY_SEPARATOR
                .'gemini_models_'.now()->format('Y_m_d').'.json';
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Khong tao duoc thu muc '.$dir);

            return self::FAILURE;
        }

        $written = file_put_contents($path, json_encode([
            'fetched_at' => now()->toIso8601String(),
            'base_url' => (string) config('video.gemini.base_url'),
            'versions' => $raw,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($written === false) {
            $this->error('Khong ghi duoc '.$path);

            return self::FAILURE;
        }

        $this->info('Ghi bang chung: '.$path);

        return self::SUCCESS;
    }
}
