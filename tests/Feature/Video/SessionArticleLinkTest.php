<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\VideoSession;
use Tests\TestCase;

/**
 * Moi session PHAI noi duoc ve bai viet cua no bang KHOA, khong bang so chuoi.
 *
 * Test doc DU LIEU THAT chu khong dung fixture, va do la co y: loi nay khong nam
 * o logic ma o mot dong BI QUEN. Ngay 2026-08-07 cot `article_id` duoc them va 4
 * session cu duoc backfill, nhung cho GHI thi chua noi — nen session tiep theo
 * do nguoi dung tao ra co `article_id = NULL`. Khong loi, khong log; chi lo ra
 * khi ai do di query.
 *
 * Fixture khong bat duoc loai loi do, vi fixture bao gio cung dien du truong.
 */
class SessionArticleLinkTest extends TestCase
{
    public function test_every_session_whose_article_still_exists_is_linked_by_key(): void
    {
        $orphans = [];

        foreach (VideoSession::all() as $session) {
            if ($session->article_id !== null) {
                continue;
            }

            // Ma session mang 8 ky tu dau uuid bai viet. Neu bai do VAN CON, thi
            // `article_id` trong la mot dong bi quen — khong phai du lieu mo coi.
            if (! preg_match('/^art_([0-9a-f]{8})_/', (string) $session->code, $m)) {
                continue;
            }
            if (Article::where('id', 'like', $m[1].'%')->exists()) {
                $orphans[] = $session->code;
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "%d session co bai viet ton tai nhung `article_id` trong: %s\n".
            'Cho GHI da quen gan khoa — xem VideoSessionService::creatVideoById().',
            count($orphans), implode(', ', $orphans),
        ));
    }

    public function test_a_linked_session_points_at_the_article_its_code_names(): void
    {
        // Backfill bang tien to chuoi la mot phep DOAN. Test nay bat no phai dong
        // y voi khoa: lech nhau nghia la co ai do gan nham, va moi thu truy nguoc
        // tu do tro di deu sai bai.
        //
        // Bang RONG khac han bang CO session ma khong cai nao link duoc. Truong
        // hop dau la mot may chua chay gi; truong hop sau la duong ghi hong. Chot
        // ben duoi chi noi duoc mot cau cho ca hai, nen tach o day.
        if (VideoSession::count() === 0) {
            $this->markTestSkipped('Chua co session nao — audit nay can du lieu that de kiem.');
        }

        $checked = 0;

        foreach (VideoSession::whereNotNull('article_id')->get() as $session) {
            if (! preg_match('/^art_([0-9a-f]{8})_/', (string) $session->code, $m)) {
                continue;
            }
            $this->assertStringStartsWith(
                $m[1],
                (string) $session->article_id,
                "session {$session->code} tro toi article_id khong khop tien to ma cua chinh no",
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Khong session nao duoc kiem — test dang khong bao ve gi.');
    }
}
