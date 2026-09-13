<?php

namespace App\Console\Commands;

use App\Video\Gemini\GeminiErrorVerdict;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CONG CU CHAN DOAN, KHONG PHAI BANG CHUNG NANG LUC.
 *
 * Sentinel lam moi request chet o buoc parse, nen ket qua tot nhat o day chi la
 * "parser nhan gia tri nay" — no KHONG noi model ve duoc anh o ti le hay kho do.
 * Vi vay file ghi ra khong duoc dung lam cong tac cho registry hay cho dropdown;
 * muon mo mot gia tri cho nguoi dung thi phai co manifest da review va mot lan
 * render canary that.
 */
class VideoProbeGeminiImageValues extends Command
{
    /** @var list<string> */
    private const MODELS = [
        'gemini-3.1-flash-lite-image',
        'gemini-3.1-flash-image',
        'gemini-3-pro-image',
    ];

    /** @var list<string> */
    private const ASPECT_RATIOS = [
        '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4',
        '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1',
    ];

    /** @var list<string> */
    private const IMAGE_SIZES = ['0.5K', '1K', '2K', '4K'];

    /**
     * Hai hang tren la danh sach UNG VIEN de chan doan, khong phai danh sach da
     * tin. Ket qua quet KHONG duoc dung lam cong tac cho registry hay dropdown —
     * xem contract o dau lop. Hep lai o day khi muon hoi it hon.
     *
     * @var array<string, array{aspect_ratios?: list<string>, image_sizes?: list<string>}>
     */
    private const NARROWED = [];

    /** @var list<string> */
    private const VERSIONS = ['v1', 'v1beta'];

    private const ANCHOR_RATIO = '9:16';

    private const ANCHOR_SIZE = '1K';

    protected $signature = 'video:probe-gemini-image-values
        {--model=* : Model can quet; bo trong thi quet ca ba model Gemini}
        {--api-version=v1 : Phien ban API de quet}
        {--pause=1500 : Nghi bao nhieu mili giay giua hai request}
        {--retry-pause=20 : Cho bao nhieu giay roi thu lai khi gap 429}
        {--json= : Ghi ket qua ra path; bo trong thi dung video.gemini.evidence_dir}';

    protected $description = 'Ask Gemini which aspectRatio and imageSize values the parser accepts, without generating an image';

    public function handle(): int
    {
        $key = trim((string) config('video.gemini.api_key'));

        if ($key === '') {
            $this->error('config video.gemini.api_key rong — khong goi gi ca.');

            return self::FAILURE;
        }

        $version = (string) $this->option('api-version');
        $models = array_map('strval', $this->option('model') ?: self::MODELS);
        $unknown = array_values(array_diff($models, self::MODELS));

        if (! in_array($version, self::VERSIONS, true)) {
            $this->error('api-version chi nhan '.implode(' hoac ', self::VERSIONS).", nhan duoc: {$version}.");

            return self::FAILURE;
        }

        if ($unknown !== []) {
            $this->error('Model ngoai danh sach: '.implode(', ', $unknown).' — khong goi gi ca.');

            return self::FAILURE;
        }

        if (count(array_unique($models)) !== count($models)) {
            $this->error('Model lap lai — evidence chi giu mot khoa moi model nen se khong khop so request.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($models as $model) {
            $anchor = $this->verdictFor($key, $version, $model, self::ANCHOR_RATIO, self::ANCHOR_SIZE);

            if ($anchor === null) {
                return $this->stop();
            }

            if ($anchor['verdict'] !== 'parse_accepted') {
                $this->error(sprintf(
                    '%s: cap neo %s + %s cho ket qua %s — bo qua model nay, quet tiep khong con y nghia.',
                    $model, self::ANCHOR_RATIO, self::ANCHOR_SIZE, $anchor['verdict'],
                ));

                $results[$model] = ['status' => 'skipped_anchor', 'anchor' => $anchor];

                continue;
            }

            $ratios = $this->sweep($key, $version, $model, $anchor, 'aspect_ratios');

            if ($ratios === null) {
                return $this->stop();
            }

            $sizes = $this->sweep($key, $version, $model, $anchor, 'image_sizes');

            if ($sizes === null) {
                return $this->stop();
            }

            $results[$model] = [
                'status' => 'swept',
                'anchor' => $anchor,
                'aspect_ratios' => $ratios,
                'image_sizes' => $sizes,
            ];
        }

        $this->report($results);

        if ($this->input->hasParameterOption('--json') && $this->write($version, $results) === self::FAILURE) {
            return self::FAILURE;
        }

        $skipped = array_keys(array_filter(
            $results, static fn (array $entry): bool => $entry['status'] !== 'swept',
        ));

        if ($results === [] || $skipped !== []) {
            $this->error('Ma tran khong day du — bo qua: '.(implode(', ', $skipped) ?: 'tat ca'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Moi gia tri duoc hoi kem gia tri neo da biet la hop le o nhom con lai, nen
     * mot dong "Invalid value" chi co the noi ve gia tri dang thu.
     *
     * @param  array{verdict: string, invalid: list<array<string, string>>, message: string}  $anchor
     * @return array<string, array{verdict: string, invalid: list<array<string, string>>, message: string}>|null
     */
    private function sweep(string $key, string $version, string $model, array $anchor, string $group): ?array
    {
        $ratios = $group === 'aspect_ratios';
        $anchorValue = $ratios ? self::ANCHOR_RATIO : self::ANCHOR_SIZE;
        $outcomes = [];

        foreach (self::NARROWED[$model][$group] ?? ($ratios ? self::ASPECT_RATIOS : self::IMAGE_SIZES) as $value) {
            $outcome = $value === $anchorValue ? $anchor : $this->verdictFor(
                $key,
                $version,
                $model,
                $ratios ? $value : self::ANCHOR_RATIO,
                $ratios ? self::ANCHOR_SIZE : $value,
            );

            if ($outcome === null) {
                return null;
            }

            $outcomes[$value] = $outcome;
            $this->line(sprintf(
                '%-30s %-13s %-6s → %s', $model, $group, $value, $outcome['verdict'],
            ));
        }

        return $outcomes;
    }

    /**
     * Sentinel nam trong moi body nen provider KHONG BAO GIO sinh duoc anh: 400
     * la ket qua duy nhat duoc phep. Khong co dong "Invalid value" nghia la parser
     * nhan gia tri dang thu — chi the, khong hon.
     *
     * @return array{verdict: string, invalid: list<array<string, string>>, message: string}|null
     *                null nghia la guard vo — phai dung ngay
     */
    private function verdictFor(string $key, string $version, string $model, string $ratio, string $size): ?array
    {
        $response = $this->send($key, $version, $model, $ratio, $size);

        if ($response !== null && $response->status() === 429) {
            $wait = max(0, (int) $this->option('retry-pause'));

            $this->warn(sprintf('429 tren %s %s + %s — cho %ds roi thu lai mot lan.', $model, $ratio, $size, $wait));
            sleep($wait);

            $response = $this->send($key, $version, $model, $ratio, $size);
        }

        if ($response === null) {
            return null;
        }

        if ($response->status() !== 400) {
            $this->error(sprintf(
                'CHO DOI 400, NHAN %d tren %s %s + %s.', $response->status(), $model, $ratio, $size,
            ));

            if ($response->successful()) {
                $this->error('MOT ANH CO THE DA DUOC SINH VA TINH TIEN. Kiem tra hoa don Google.');
            }

            return null;
        }

        $message = (string) ($response->json('error.message') ?? $response->body());
        [$verdict, , $invalid] = GeminiErrorVerdict::classify($message);

        return [
            'verdict' => match ($verdict) {
                'field_accepted' => 'parse_accepted',
                'value_rejected' => 'value_rejected',
                default => 'inconclusive',
            },
            'invalid' => $invalid,
            'message' => Str::limit($message, 400),
        ];
    }

    private function send(string $key, string $version, string $model, string $ratio, string $size): ?Response
    {
        usleep(max(0, (int) $this->option('pause')) * 1000);

        try {
            return Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('video.gemini.timeout'))
                ->post(sprintf(
                    '%s/%s/models/%s:generateContent',
                    rtrim((string) config('video.gemini.base_url'), '/'),
                    $version,
                    $model,
                ), [
                    'contents' => [['parts' => [['text' => 'x']]]],
                    'generationConfig' => [
                        'responseModalities' => ['IMAGE'],
                        'imageConfig' => ['aspectRatio' => $ratio, 'imageSize' => $size],
                        GeminiErrorVerdict::SENTINEL => 1,
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->error('Khong noi duoc Gemini: '.Str::limit($e->getMessage(), 200));

            return null;
        }
    }

    private function stop(): int
    {
        $this->error('DUNG TOAN BO MA TRAN — khong chay cac luot con lai.');

        return self::FAILURE;
    }

    /** @param array<string, array<string, array<string, array<string, mixed>>>> $results */
    private function report(array $results): void
    {
        $rows = [];

        foreach ($results as $model => $entry) {
            if ($entry['status'] !== 'swept') {
                $rows[] = [$model, $entry['status'], '', $entry['anchor']['verdict']];

                continue;
            }

            foreach (['aspect_ratios', 'image_sizes'] as $group) {
                $rows[] = [
                    $model,
                    $group,
                    implode(' ', array_keys(array_filter(
                        $entry[$group], static fn (array $v): bool => $v['verdict'] === 'parse_accepted',
                    ))),
                    implode(' ', array_keys(array_filter(
                        $entry[$group], static fn (array $v): bool => $v['verdict'] !== 'parse_accepted',
                    ))),
                ];
            }
        }

        $this->table(['MODEL', 'NHOM', 'PARSER NHAN', 'TU CHOI / CHUA RO'], $rows);
        $this->warn('Day la ket qua PARSE. No khong chung minh model render duoc gia tri nao —');
        $this->warn('muon mo mot gia tri cho nguoi dung thi van phai co manifest da review va render canary.');
    }

    /** @param array<string, array<string, array<string, array<string, mixed>>>> $results */
    private function write(string $version, array $results): int
    {
        $path = trim((string) $this->option('json'));

        if ($path === '') {
            $path = rtrim((string) config('video.gemini.evidence_dir'), '/\\').DIRECTORY_SEPARATOR
                .'gemini_image_values_'.now()->format('Y_m_d').'.json';
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Khong tao duoc thu muc '.$dir);

            return self::FAILURE;
        }

        $written = file_put_contents($path, json_encode([
            'probed_at' => now()->toIso8601String(),
            'base_url' => (string) config('video.gemini.base_url'),
            'api_version' => $version,
            'sentinel' => GeminiErrorVerdict::SENTINEL,
            'anchor' => ['aspect_ratio' => self::ANCHOR_RATIO, 'image_size' => self::ANCHOR_SIZE],
            'proves' => 'parse only',
            'does_not_prove' => 'model render duoc gia tri — file nay khong duoc dung lam cong tac cho registry',
            'models' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($written === false) {
            $this->error('Khong ghi duoc '.$path);

            return self::FAILURE;
        }

        $this->info('Ghi bang chung: '.$path);

        return self::SUCCESS;
    }
}
