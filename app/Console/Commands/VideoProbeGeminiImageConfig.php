<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VideoProbeGeminiImageConfig extends Command
{
    private const PROBE_MODEL = 'gemini-3.1-flash-lite-image';

    private const SENTINEL = '__probe_unknown_field__';

    /** @var list<string> */
    private const VERSIONS = ['v1', 'v1beta'];

    /** @var array<string, array<string, mixed>> */
    private const VARIANTS = [
        'responseFormat_flat' => [
            'responseFormat' => ['aspectRatio' => '9:16', 'imageSize' => '1K'],
        ],
        'responseFormat_image' => [
            'responseFormat' => ['image' => ['aspectRatio' => '9:16', 'imageSize' => '1K']],
        ],
        'imageConfig' => [
            'imageConfig' => ['aspectRatio' => '9:16', 'imageSize' => '1K'],
        ],
    ];

    protected $signature = 'video:probe-gemini-image-config
        {--json= : Ghi ket qua ra path; bo trong thi dung resources/ai/providers}';

    protected $description = 'Ask Gemini which image config shape and API version it accepts, without generating an image';

    public function handle(): int
    {
        $key = trim((string) config('video.gemini.api_key'));

        if ($key === '') {
            $this->error('config video.gemini.api_key rong — khong goi gi ca.');

            return self::FAILURE;
        }

        $results = [];

        foreach (self::VERSIONS as $version) {
            foreach (array_keys(self::VARIANTS) as $variant) {
                $outcome = $this->probe($key, $version, $variant);

                if ($outcome === null) {
                    $this->error('DUNG TOAN BO MA TRAN — khong chay cac luot con lai.');

                    return self::FAILURE;
                }

                $results[$version][$variant] = $outcome;
                $this->line(sprintf('%-7s %-21s → %s', $version, $variant, $outcome['verdict']));
            }
        }

        $usable = $this->report($results);

        if ($this->input->hasParameterOption('--json') && $this->write($results) === self::FAILURE) {
            return self::FAILURE;
        }

        return $usable ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>|null null nghia la guard vo — phai dung ngay,
     *                                   khong chay luot tiep theo
     */
    private function probe(string $key, string $version, string $variant): ?array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('video.gemini.timeout'))
                ->post($this->endpoint($version), $this->body($variant));
        } catch (ConnectionException $e) {
            $this->error('Khong noi duoc Gemini: '.Str::limit($e->getMessage(), 200));

            return null;
        }

        if ($response->status() !== 400) {
            $this->error(sprintf(
                'CHO DOI 400, NHAN %d tren %s/%s.',
                $response->status(),
                $version,
                $variant,
            ));

            if ($response->successful()) {
                $this->error('MOT ANH CO THE DA DUOC SINH VA TINH TIEN. Kiem tra hoa don Google.');
            }

            return null;
        }

        $message = (string) ($response->json('error.message') ?? $response->body());
        [$verdict, $unknown, $invalid] = $this->verdict($message);

        return [
            'status' => 400,
            'verdict' => $verdict,
            'unknown' => $unknown,
            'invalid' => $invalid,
            'message' => Str::limit($message, 600),
        ];
    }

    /**
     * Google liet ke MOI truong la VA moi gia tri sai trong mot lan. Truong
     * dung ma gia tri sai (enum) la mot loai rieng: body co the dung duoc neu
     * doi gia tri, nhung KHONG dung duoc voi gia tri dang gui.
     *
     * @return array{0: string, 1: list<array{name: string, at: string}>, 2: list<array{at: string, type: string, value: string}>}
     */
    private function verdict(string $message): array
    {
        preg_match_all("/Unknown name \"([^\"]+)\" at '([^']*)'/", $message, $u, PREG_SET_ORDER);
        preg_match_all("/Invalid value at '([^']*)' \(([^)]*)\), (?:\"([^\"]*)\"|(\S+))/", $message, $i, PREG_SET_ORDER);

        $unknown = array_map(
            static fn (array $m): array => ['name' => $m[1], 'at' => $m[2]],
            $u,
        );

        $invalid = array_map(static fn (array $m): array => [
            'at' => $m[1],
            'type' => $m[2],
            'value' => ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''),
        ], $i);

        $names = array_values(array_unique(array_column($unknown, 'name')));
        $fieldUnknown = array_values(array_diff($names, [self::SENTINEL]));

        return match (true) {
            $unknown === [] && $invalid === [] => ['inconclusive', $unknown, $invalid],
            $fieldUnknown !== [] => ['field_rejected', $unknown, $invalid],
            $invalid !== [] => ['value_rejected', $unknown, $invalid],
            $names === [self::SENTINEL] => ['field_accepted', $unknown, $invalid],
            default => ['inconclusive', $unknown, $invalid],
        };
    }

    /**
     * Sentinel co mat trong moi body de body luon sai: provider khong the sinh
     * anh tu mot request khong hop le.
     *
     * @return array<string, mixed>
     */
    private function body(string $variant): array
    {
        return [
            'contents' => [['parts' => [['text' => 'x']]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']]
                + self::VARIANTS[$variant]
                + [self::SENTINEL => 1],
        ];
    }

    private function endpoint(string $version): string
    {
        return rtrim((string) config('video.gemini.base_url'), '/')
            .'/'.$version.'/models/'.self::PROBE_MODEL.':generateContent';
    }

    /** @param array<string, array<string, array<string, mixed>>> $results */
    private function report(array $results): bool
    {
        $rows = [];
        $usable = [];

        foreach ($results as $version => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                $rows[] = [
                    $version,
                    $variant,
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
                    $usable[] = $version.' + '.$variant;
                }
            }
        }

        $this->table(['VERSION', 'VARIANT', 'VERDICT', 'UNKNOWN', 'INVALID'], $rows);

        if ($usable === []) {
            $this->error('Khong cap nao duoc chap nhan — chua co cau hinh de di tiep Pha 2. Doc cot UNKNOWN va INVALID.');

            return false;
        }

        $this->info('Dung duoc: '.implode(' · ', $usable));
        $this->warn('Van chua chung minh sinh duoc anh: 400 chi noi body hop le den dau.');

        return true;
    }

    /** @param array<string, array<string, array<string, mixed>>> $results */
    private function write(array $results): int
    {
        $path = trim((string) $this->option('json'));

        if ($path === '') {
            $path = resource_path('ai/providers').DIRECTORY_SEPARATOR
                .'gemini_image_config_'.now()->format('Y_m_d').'.json';
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Khong tao duoc thu muc '.$dir);

            return self::FAILURE;
        }

        $written = file_put_contents($path, json_encode([
            'probed_at' => now()->toIso8601String(),
            'base_url' => (string) config('video.gemini.base_url'),
            'probe_model' => self::PROBE_MODEL,
            'sentinel' => self::SENTINEL,
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
