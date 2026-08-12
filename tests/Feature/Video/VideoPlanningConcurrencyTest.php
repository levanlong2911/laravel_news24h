<?php

namespace Tests\Feature\Video;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cùng lý do KHÔNG dùng DatabaseTransactions với FinalCompositionConcurrencyTest:
 * cần HAI kết nối DB thật nhìn thấy cùng một hàng ĐÃ COMMIT. Tạo/xoá dữ liệu
 * THẬT, dọn dẹp tuyệt đối trong tearDown().
 */
class VideoPlanningConcurrencyTest extends TestCase
{
    private ?string $articleId = null;

    protected function tearDown(): void
    {
        if ($this->articleId) {
            DB::table('articles')->where('id', $this->articleId)->delete();
        }
        DB::disconnect('mysql_race_test');

        parent::tearDown();
    }

    /**
     * Chứng minh cơ chế `startVideoPlanning()` dựa vào (lockForUpdate() trong
     * DB::transaction() trên hàng `articles`) THẬT SỰ chặn một kết nối khác —
     * không chỉ đọc code rồi tin. Kết nối 1 giữ khoá CHƯA commit, y hệt lúc
     * startVideoPlanning() đang chạy giữa chừng; kết nối 2 tách biệt hoàn
     * toàn, đặt innodb_lock_wait_timeout ngắn để không treo test.
     */
    public function test_locking_the_article_row_genuinely_blocks_a_second_connection(): void
    {
        $keywordId = DB::table('keywords')->value('id');
        $articleId = (string) \Illuminate\Support\Str::uuid();
        DB::table('articles')->insert([
            'id' => $articleId,
            'keyword_id' => $keywordId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST race lock source',
            'title' => 'TEST race lock article '.uniqid(),
            'slug' => 'test-race-lock-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->articleId = $articleId;

        config(['database.connections.mysql_race_test' => config('database.connections.mysql')]);

        DB::beginTransaction();
        DB::table('articles')->where('id', $articleId)->lockForUpdate()->first();

        $blocked = false;
        $errorMessage = '';
        DB::connection('mysql_race_test')->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::connection('mysql_race_test')->transaction(function () use ($articleId) {
                DB::connection('mysql_race_test')
                    ->table('articles')
                    ->where('id', $articleId)
                    ->lockForUpdate()
                    ->first();
            });
        } catch (QueryException $e) {
            $blocked = true;
            $errorMessage = $e->getMessage();
        }

        DB::rollBack();

        $this->assertTrue($blocked,
            'ket noi thu hai phai bi chan (lock wait timeout) khi ket noi 1 con giu lockForUpdate() chua commit — '.
            'neu khong bi chan thi hai request dong thoi co the cung di qua nhanh "chua co session dang chay"');
        $this->assertStringContainsStringIgnoringCase('lock wait timeout', $errorMessage);
    }
}
