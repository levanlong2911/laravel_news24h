<?php

namespace App\Console\Commands;

use App\Services\VideoSessionService;
use Illuminate\Console\Command;

/**
 * §18.30 — mở lại claim `planning` một cách CÓ CHỦ Ý. Claim không tự hết hạn
 * (xem docblock `VideoSessionService::runVideoPlanningPipeline()`) — người
 * vận hành phải tự xác nhận tiến trình `video:build-plan` cũ đã chết THẬT
 * (process list, timestamp log) trước khi chạy lệnh này. Chạy nhầm khi
 * tiến trình cũ vẫn còn sống KHÔNG gây double-spend — token sở hữu vẫn chặn
 * đúng lúc ghi kết quả — nhưng sẽ làm worker mới bắt đầu một pipeline thừa,
 * tốn tiền vô ích.
 */
class VideoResetPlanningClaim extends Command
{
    protected $signature = 'video:reset-planning-claim
        {--session= : Mã session (video_sessions.code) cần mở lại claim}
        {--force : Bỏ qua xác nhận tương tác — dùng khi gọi từ script}';

    protected $description = 'Xoá claim planning bị kẹt (chỉ khi đã tự xác nhận tiến trình cũ đã chết) để cho phép chạy lại video:build-plan';

    public function __construct(private VideoSessionService $videoSessionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $code = (string) $this->option('session');

        if ($code === '') {
            $this->error('Thieu --session=');

            return self::FAILURE;
        }

        // Xac nhan CO CHU DICH: chay nham lenh nay khi tien trinh cu van con
        // song khong lam mat tien (token so huu van chan dung luc ghi ket
        // qua), nhung se lam mot worker moi chay thua mot pipeline — that
        // tien vo ich. --force de dung tu script/CI, khong can nguoi ngoi go.
        if (! $this->option('force') && ! $this->confirm(
            "Ban da tu xac nhan (process list, log) tien trinh video:build-plan cu cho session {$code} DA CHET THAT chua?"
        )) {
            $this->warn('Da huy — chua reset gi.');

            return self::FAILURE;
        }

        if (! $this->videoSessionService->resetPlanningClaim($code)) {
            $this->error("Khong reset duoc claim cho session {$code} — kiem tra ma session dung va session dang o trang thai 'planning'.");

            return self::FAILURE;
        }

        $this->info("Da xoa claim cho session {$code}. Chay lai: php artisan video:build-plan --session={$code}");

        return self::SUCCESS;
    }
}
