<?php

namespace App\Console\Commands;

use App\Video\Gemini\GeminiErrorVerdict;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VideoProbeGeminiInteractions extends Command
{
    private const API_VERSION = 'v1beta';

    private const ENDPOINT = 'interactions';

    private const FIRST_MODEL = 'gemini-3.1-flash-lite-image';

    /** @var list<string> */
    private const MODELS = [
        'gemini-3.1-flash-lite-image',
        'gemini-3.1-flash-image',
        'gemini-3-pro-image',
    ];

    protected $signature = 'video:probe-gemini-interactions
        {--all : Chay ca 3 model — chi duoc phep khi da co bang chung luot dau tra 400}';

    protected $description = 'Ask the Gemini Interactions endpoint whether it accepts the image response_format, without generating an image';

    public function handle(): int
    {
        $key = trim((string) config('video.gemini.api_key'));

        if ($key === '') {
            $this->error('config video.gemini.api_key rong — khong goi gi ca.');

            return self::FAILURE;
        }

        $all = (bool) $this->option('all');

        if ($all && ! $this->firstRunProvedSafe()) {
            return self::FAILURE;
        }

        $results = [];

        foreach ($all ? self::MODELS : [self::FIRST_MODEL] as $model) {
            $outcome = $this->probe($key, $model);

            if ($outcome === null) {
                $this->error('DUNG TOAN BO — khong chay cac model con lai.');

                return self::FAILURE;
            }

            $results[$model] = $outcome;
            $this->line(sprintf('%-30s → %s', $model, $outcome['verdict']));
        }

        $usable = $this->report($results);

        if ($this->write($results, $all) === self::FAILURE) {
            return self::FAILURE;
        }

        return $usable ? self::SUCCESS : self::FAILURE;
    }

    private function firstRunProvedSafe(): bool
    {
        $path = $this->latestFirstRunEvidence();
        $data = $path === null ? null : json_decode((string) file_get_contents($path), true);
        $first = is_array($data) ? ($data['results'][self::FIRST_MODEL] ?? null) : null;

        $isInteractionsEvidence = is_array($data)
            && ($data['endpoint'] ?? null) === self::ENDPOINT
            && ($data['api_version'] ?? null) === self::API_VERSION
            && ($data['run'] ?? null) === 'first'
            && ($data['sentinel'] ?? null) === GeminiErrorVerdict::SENTINEL;

        if (! $isInteractionsEvidence
            || ! is_array($first)
            || ($first['status'] ?? null) !== 400
            || ($first['verdict'] ?? 'inconclusive') === 'inconclusive') {
            $this->error('Chua co bang chung luot dau hop le cho /'.self::API_VERSION.'/'.self::ENDPOINT
                .' ('.self::FIRST_MODEL.' tra 400 va doc duoc). Chay lenh khong co --all truoc.');

            return false;
        }

        $this->line('Dua tren bang chung: '.$path);

        return true;
    }

    private function latestFirstRunEvidence(): ?string
    {
        $files = glob($this->evidenceDir().DIRECTORY_SEPARATOR.'gemini_interactions_probe_first_*.json') ?: [];

        sort($files);

        return $files === [] ? null : (string) end($files);
    }

    /**
     * @return array<string, mixed>|null null nghia la guard vo — phai dung ngay,
     *                                   khong chay model tiep theo
     */
    private function probe(string $key, string $model): ?array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('video.gemini.timeout'))
                ->post($this->endpoint(), $this->body($model));
        } catch (ConnectionException $e) {
            $this->error('Khong noi duoc Gemini: '.Str::limit($e->getMessage(), 200));

            return null;
        }

        if ($response->status() !== 400) {
            $this->error(sprintf('CHO DOI 400, NHAN %d tren %s.', $response->status(), $model));

            if ($response->successful()) {
                $this->error('MOT ANH CO THE DA DUOC SINH VA TINH TIEN. Kiem tra hoa don Google.');
            }

            return null;
        }

        $message = (string) ($response->json('error.message') ?? $response->body());
        [$verdict, $unknown, $invalid] = GeminiErrorVerdict::classify($message);

        return [
            'status' => 400,
            'verdict' => $verdict,
            'unknown' => $unknown,
            'invalid' => $invalid,
            'message' => Str::limit($message, 1000),
        ];
    }

    /** @return array<string, mixed> */
    private function body(string $model): array
    {
        return [
            'model' => $model,
            'input' => 'x',
            'response_format' => [
                'type' => 'image',
                'aspect_ratio' => '9:16',
                'image_size' => '1K',
            ],
            GeminiErrorVerdict::SENTINEL => 1,
        ];
    }

    private function endpoint(): string
    {
        return rtrim((string) config('video.gemini.base_url'), '/').'/'.self::API_VERSION.'/'.self::ENDPOINT;
    }

    private function evidenceDir(): string
    {
        return rtrim((string) config('video.gemini.evidence_dir'), '/\\');
    }

    /** @param array<string, array<string, mixed>> $results */
    private function report(array $results): bool
    {
        $rows = [];
        $usable = [];

        foreach ($results as $model => $outcome) {
            $rows[] = [
                $model,
                $outcome['verdict'],
                implode(', ', array_map(
                    static fn (array $u): string => $u['name'].' @ '.$u['at'],
                    $outcome['unknown'],
                )),
                implode(', ', array_map(
                    static fn (array $x): string => $x['value'].' @ '.$x['at'],
                    $outcome['invalid'],
                )),
            ];

            if ($outcome['verdict'] === 'field_accepted') {
                $usable[] = $model;
            }
        }

        $this->table(['MODEL', 'VERDICT', 'UNKNOWN', 'INVALID'], $rows);

        foreach ($results as $model => $outcome) {
            if ($outcome['verdict'] === 'inconclusive') {
                $this->warn('Khong doc duoc thong diep cua '.$model.' — nguyen van:');
                $this->line($outcome['message']);
            }
        }

        if ($usable === []) {
            $this->error('Khong model nao duoc chap nhan qua /'.self::API_VERSION.'/'.self::ENDPOINT.'.');

            return false;
        }

        $this->info('Dung duoc: '.implode(' · ', $usable));
        $this->warn('Van chua chung minh sinh duoc anh: 400 chi noi body hop le den dau.');

        return true;
    }

    /** @param array<string, array<string, mixed>> $results */
    private function write(array $results, bool $all): int
    {
        $dir = $this->evidenceDir();

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Khong tao duoc thu muc '.$dir);

            return self::FAILURE;
        }

        $path = $dir.DIRECTORY_SEPARATOR
            .'gemini_interactions_probe_'.($all ? 'all' : 'first').'_'.now()->format('Y_m_d').'.json';

        $written = file_put_contents($path, json_encode([
            'probed_at' => now()->toIso8601String(),
            'base_url' => (string) config('video.gemini.base_url'),
            'endpoint' => self::ENDPOINT,
            'api_version' => self::API_VERSION,
            'run' => $all ? 'all' : 'first',
            'sentinel' => GeminiErrorVerdict::SENTINEL,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($written === false) {
            $this->error('Khong ghi duoc '.$path);

            return self::FAILURE;
        }

        $this->info('Ghi bang chung: '.$path);

        return self::SUCCESS;
    }
}
