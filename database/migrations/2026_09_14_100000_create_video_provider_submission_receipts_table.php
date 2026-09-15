<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bien lai cua mot lan gui request DA CO THE TINH TIEN.
 *
 * `video_renders` va `render_attempts` la TRANG THAI HIEN TAI: chung bi ghi de theo
 * lease, theo retry, theo generation. Khi provider da nhan job ma ta mat quyen ghi,
 * khong con cho nao ben de giu operation id — va do la thu duy nhat dan ve khoan
 * tien da tieu.
 *
 * Bang nay chi duoc THEM, khong sua, khong xoa theo nghiep vu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_provider_submission_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('render_id');
            $table->uuid('attempt_id');
            $table->unsignedInteger('attempt_no');
            $table->unsignedInteger('claim_generation');
            $table->uuid('claim_token');
            $table->string('provider', 80);
            $table->string('model', 190);
            $table->string('provider_job_id', 160);
            $table->string('provider_request_id', 255)->nullable();
            $table->longText('response_json')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            // Ghi lai cung mot job cho cung mot attempt la idempotent, khong phai loi.
            $table->unique(['attempt_id', 'provider_job_id'], 'submission_receipt_attempt_job_uq');
            $table->index(['render_id', 'attempt_no', 'claim_generation'], 'submission_receipt_generation_idx');
            $table->index('provider_job_id', 'submission_receipt_job_idx');

            // KHONG cascade: bien lai la bang chung chi tieu, khong duoc bien mat theo
            // hang no lam chung. Muon xoa render/attempt thi phai xu ly bien lai truoc.
            $table->foreign('render_id')->references('id')->on('video_renders')->restrictOnDelete();
            $table->foreign('attempt_id')->references('id')->on('render_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Mot bien lai la mot khoan tien DA TIEU. Rollback khong duoc phep xoa no —
        // dung lai va de nguoi quyet dinh, giong migration 2026_08_20_110000.
        if (Schema::hasTable('video_provider_submission_receipts')) {
            $rows = DB::table('video_provider_submission_receipts')->count();

            if ($rows > 0) {
                throw new \RuntimeException(sprintf(
                    'Con %d bien lai submit — rollback se xoa dau vet cua tien da tieu. Xu ly tay truoc.',
                    $rows,
                ));
            }
        }

        Schema::dropIfExists('video_provider_submission_receipts');
    }
};
