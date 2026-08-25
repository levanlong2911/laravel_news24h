<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageQuality;
use App\Models\VideoDesignImage;
use App\Services\PythonRunner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Duong render dong bo: Laravel giu o, goi Python, doc ket qua tren stdout, tu
 * ghi so cai. Python KHONG goi nguoc Laravel mot lan nao.
 *
 * Cung hinh dang voi duong Haiku/Sonnet dang chay: claim + lease NGAY TREN HANG
 * DU LIEU roi moi goi provider. Nho vay boc mot Job ra ngoai sau nay khong phai
 * sua gi ben trong — chay nen hay chay trong request deu an toan nhu nhau.
 *
 * Bon chot giu tien:
 *   1. `python_runner_enabled` — cau dao tong o `PythonRunner`
 *   2. dedupe theo prompt hash o `DesignImageStore::createCandidate()`
 *   3. `claimForDirectRender()` — bam lan hai trong luc render gap `rendering`
 *   4. claim token kiem lai truoc khi ghi so cai
 *
 * Con thieu so voi duong hang doi: khong co outbox. Tien trinh chet SAU khi
 * provider da tinh tien thi khong co gi phat lai — xem
 * `video:sweep-orphan-design-renders`.
 *
 * VA no KHONG chua duoc WinError 10106: van la PHP sinh tien trinh Python, nen
 * van phai chay qua Apache chu khong phai `artisan serve`.
 */
class DesignImageDirectRenderer
{
    private const SCRIPT = 'render_design_image_once.py';

    private const BUDGET_SECONDS = 90;

    public function __construct(private DesignImageQueue $queue, private PythonRunner $pythonRunner) {}

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|not_enqueueable|image_not_found|<chan doan>
     */
    public function renderNow(string $imageId): array
    {
        [$image, $claimToken, $reason] = $this->queue->claimForDirectRender($imageId, self::BUDGET_SECONDS);

        if ($claimToken === null) {
            return [$image, $reason];
        }

        $specPath = $this->writeSpec($image, $image->prompt_spec_json ?? [], $claimToken);

        try {
            [, $output] = $this->pythonRunner->runAndWait(
                self::SCRIPT, ['--spec', $specPath], self::BUDGET_SECONDS,
            );
        } finally {
            @unlink($specPath);
        }

        $result = $this->readResult($output);

        if ($result === null) {

            $this->queue->recordDirectResult(
                $imageId, $claimToken, false,
                'Khong doc duoc ket qua tu worker: '.mb_substr(trim($output), -400), [],
            );

            // Khong doc duoc ket qua: co the tien trinh chet truoc khi in gi. Tra
            // NGUYEN VAN output ra man hinh — do la thu duy nhat noi duoc chuyen gi.
            Log::warning('DesignImageDirectRenderer: khong doc duoc ket qua', [
                'image_id' => $imageId,
                'output' => mb_substr($output, -2000),
            ]);

            return [$image->refresh(), 'failed'];
        }

        [$done, $recordReason] = $this->queue->recordDirectResult(
            $imageId,
            $claimToken,
            (bool) ($result['ok'] ?? false),
            $result['error'] ?? null,
            array_values($result['renders'] ?? []),
        );

        if ($done === null) {
            return [$image, $recordReason];
        }

        return [$done, $done->status === DesignImageStatus::RENDERED->value ? 'rendered' : 'failed'];
    }

    /** @param array<string, mixed> $spec */
    private function writeSpec(VideoDesignImage $image, array $spec, string $claimToken): string
    {
        $path = rtrim((string) config('video.runner.log_dir'), '/\\')
            .DIRECTORY_SEPARATOR.'design_image_'.Str::uuid().'.json';

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode([
            'image_id' => $image->id,
            // Python khong dung toi, nhung file spec la thu con lai khi di tim mot
            // luot render da chay: co token thi doi chieu duoc voi hang du lieu.
            'claim_token' => $claimToken,
            'prompt' => $spec['prompt'] ?? '',
            'operation' => $spec['operation'] ?? 'generate',
            'model' => $spec['model'] ?? '',
            'quality' => $spec['quality'] ?? '',
            'size' => $spec['size'] ?? '',
            'variations' => (int) ($spec['variations'] ?? 1),
            'cost_estimate' => ImageQuality::fromSpecOrHigh($spec['quality'] ?? '')->estimatedCostUsd(),
            'out_dir' => storage_path('app/design-image-renders'),
            'pub_dir' => public_path('renders/'.DesignImageQueue::COLLECTION),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * Doc dong JSON CUOI CUNG. `runAndWait()` noi stdout va stderr lam mot, ma
     * script in chan doan ra stderr — nen khong the parse ca khoi.
     *
     * @return array<string, mixed>|null
     */
    private function readResult(string $output): ?array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            if (! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && array_key_exists('renders', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
