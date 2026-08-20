<?php

namespace Tests\Feature\Video;

use App\Models\VideoRender;
use App\Models\VideoShot;
use Tests\TestCase;

/**
 * Doc DU LIEU THAT. Hai bat bien nay khong the kiem bang fixture, vi fixture bao
 * gio cung dung — chung noi ve trang thai cua DB production tren may nay.
 */
class RenderLedgerIntegrityTest extends TestCase
{
    public function test_a_render_never_takes_its_source_from_another_session(): void
    {
        /**
         * NGUY CO CO THAT, khong phai gia dinh.
         *
         * Prompt cua chuoi sinh tu `superyacht.json` + cau nhan dang chung, nen no
         * KHONG phu thuoc bai viet: do duoc 2026-08-07, ba mat cua session ISA va
         * session Amadea co `prompt_sha256` GIONG HET NHAU, moi sha xuat hien dung
         * hai lan trong bang.
         *
         * `source_render_id` khop bang sha. Neu phep khop khong gioi han trong
         * cung session thi `keel` cua ISA se noi sang `hall` cua Amadea — va
         * lineage van trong "hop le", chi la ke sai chuyen. Day la kieu hong te
         * nhat: no khong nem loi, no ghi mot su that gia.
         */
        $crossed = [];

        foreach (VideoRender::with('shot', 'sourceRender.shot')->whereNotNull('source_render_id')->get() as $render) {
            if ($render->sourceRender === null || $render->shot === null || $render->sourceRender->shot === null) {
                continue;
            }
            if ($render->shot->session_id !== $render->sourceRender->shot->session_id) {
                $crossed[] = "{$render->shot->shot_code} <- {$render->sourceRender->shot->shot_code}";
            }
        }

        $this->assertSame([], $crossed,
            "Render lay anh nguon tu SESSION KHAC:\n  - ".implode("\n  - ", $crossed));
    }

    public function test_every_rendered_shot_has_a_ledger_row(): void
    {
        /**
         * `video_shots.artifact_path` la CON TRO; `video_renders` la LICH SU. Mot
         * shot co artifact ma khong co dong so cai nghia la co mot tam anh/clip da
         * ton tien ma khong ai biet no sinh ra tu prompt nao, model nao, anh nguon
         * nao — dung tinh trang truoc 2026-08-07.
         *
         * Chi kiem shot DA render: shot `draft` chua chay lan nao thi khong co gi
         * de ghi.
         */
        $missing = VideoShot::where('status', 'rendered')
            ->whereDoesntHave('renders')
            ->pluck('shot_code')
            ->all();

        $this->assertSame([], $missing, sprintf(
            "%d shot da render nhung khong co dong nao trong `video_renders`: %s\n".
            'Runner cu (chua gui `render`)? Hay backfill chua chay?',
            count($missing), implode(', ', $missing),
        ));
    }

    public function test_a_ledger_row_never_claims_a_prompt_it_did_not_send(): void
    {
        /**
         * `prompt_sha256` phai bam ra dung tu `sent_prompt` cua chinh dong do.
         *
         * Lech nghia la ai do sua `sent_prompt` sau khi ghi — ma toan bo gia tri
         * cua bang nay nam o cho no BAT BIEN. Backfill cung phai chiu rang buoc
         * nay: no chi duoc ghi khi chuoi trong tay bam ra dung sha cua tam anh.
         */
        $broken = [];

        foreach (VideoRender::with('shot', 'designImage')->get() as $render) {
            if (hash('sha256', (string) $render->sent_prompt) !== $render->prompt_sha256) {
                $broken[] = $render->shot?->shot_code ?? $render->designImage?->image_code ?? $render->id;
            }
        }

        $this->assertSame([], $broken,
            'Dong so cai co `sent_prompt` khong bam ra `prompt_sha256`: '.implode(', ', $broken));
    }
}
