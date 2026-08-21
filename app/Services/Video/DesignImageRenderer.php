<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Services\PythonRunner;
use Illuminate\Support\Facades\Log;

/**
 * Bam nut xong dung doi ngay tai cho, thay vi cho ai do go lenh worker.
 *
 * KHONG di duong tat: van enqueue, van claim, van lease, van outbox, van
 * callback, van so cai. Cai duy nhat thay doi la ai bam nut khoi dong worker —
 * truoc la nguoi go lenh, gio la Laravel. Goi thang gpt-image-2 tu day se phai
 * viet ban thu hai cua chinh co che chong tra tien hai lan.
 *
 * Khi hang doi that duoc bat, bo loi goi `runAndWait()` la xong; phan con lai
 * da san sang.
 */
class DesignImageRenderer
{
    private const SCRIPT = 'render_design_images.py';

    /**
     * Ngan sach PHAI thap hon `max_execution_time` (120s tren may nay). De cao
     * hon thi PHP bi giet giua chung va tien trinh Python con co the chet SAU
     * KHI da tieu tien ma chua kip bao ve — mat mot khoan khong vao so cai.
     * Thap hon thi PHP luon la nguoi ket thuc, va o roi ve duong hang doi.
     */
    private const BUDGET_SECONDS = 90;

    public function __construct(
        private DesignImageQueue $queue,
        private PythonRunner $pythonRunner,
    ) {}

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|timed_out|<ly do khong vao duoc hang doi>
     */
    public function renderNow(string $imageId): array
    {
        [$image, $reason] = $this->queue->enqueue($imageId);

        if ($image === null || $reason === 'not_enqueueable') {
            return [$image, $reason];
        }

        if (! config('video.sync_render')) {
            // Cong tac tat: o da vao hang doi, worker that se nhat sau. Khong
            // spawn tien trinh nao, khong tieu dong nao.
            return [$image, 'queued'];
        }

        [, $output] = $this->pythonRunner->runAndWait(
            self::SCRIPT, ['--image-id', $imageId], self::BUDGET_SECONDS,
        );

        $image->refresh();

        if ($image->status === DesignImageStatus::RENDERED->value) {
            return [$image, 'rendered'];
        }

        if ($image->status === DesignImageStatus::FAILED->value) {
            return [$image, 'failed'];
        }

        // Chua ve dich va cung khong bao hong: worker het ngan sach hoac chet
        // giua chung. O dang giu lease, va `video:reclaim-expired-design-image-leases`
        // se dua no ve `queued` — khong ket, chi cham.
        Log::warning('DesignImageRenderer: worker khong ve dich trong ngan sach', [
            'image_id' => $imageId,
            'status' => $image->status,
            'output' => mb_substr($output, -2000),
        ]);

        return [$image, 'timed_out'];
    }
}
