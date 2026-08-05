<?php

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lưới an toàn cho prompt:diff.
 *
 * Cùng triết lý với PromptCheckTest: một công cụ so sánh hỏng thành no-op vẫn
 * báo "hai database khớp nhau" và vẫn trả exit 0. Nếu chỉ kiểm "bản giống nhau
 * thì im lặng" thì một hàm luôn luôn trả SAME cũng qua được. Nên phần lớn test
 * ở đây gieo lệch có chủ ý rồi đòi công cụ gọi đúng tên loại lệch đó.
 *
 * ── Vì sao phải dựng database riêng ─────────────────────────────────────────
 *
 * Lệnh này so HAI database, nên không chạy trọn vẹn trên một DB test được.
 *
 * Và nó mở connection riêng cho mỗi bên — nghĩa là dữ liệu do RefreshDatabase
 * ghi trong transaction chưa commit sẽ VÔ HÌNH với nó. Vì thế mọi bản ghi ở đây
 * đi qua adminConnection(): một connection tách rời, ghi thẳng và commit ngay.
 * Cũng chính connection đó chạy DDL, để CREATE/DROP DATABASE không gây implicit
 * commit làm sập transaction mà RefreshDatabase đang giữ.
 *
 * Khung bảng lấy bằng CREATE TABLE ... LIKE từ DB test đã migrate, cố ý không
 * viết DDL tay: viết tay thì đổi tên cột ở migration là test vẫn xanh trong khi
 * lệnh thật đã gãy.
 *
 * ── Vì sao không dùng expectsOutputToContain ────────────────────────────────
 *
 * Nó bỏ sót. Dòng "CHỈ CÓ Ở BẢN SOI" được lệnh in ra thật — kiểm bằng
 * Artisan::output() thấy nguyên văn — nhưng qua console đã mock thì assertion
 * vẫn trượt. Một công cụ kiểm mà chính lưới test của nó báo sai thì vô dụng,
 * nên ở đây đọc thẳng văn bản thật.
 */
class PromptDiffTest extends TestCase
{
    use RefreshDatabase;

    /** Bảng mà prompt:diff đọc. */
    private const TABLES = [
        'prompt_frameworks',
        'framework_content_types',
        'categories',
        'category_contexts',
    ];

    private string $baseline;
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        // Hậu tố ngẫu nhiên: chạy song song, hoặc lần chạy trước chết giữa chừng,
        // cũng không giẫm lên nhau.
        $suffix = Str::lower(Str::random(8));

        $this->baseline = "prompt_diff_base_{$suffix}";
        $this->target   = "prompt_diff_target_{$suffix}";

        $this->createDatabase($this->baseline);
        $this->createDatabase($this->target);
    }

    protected function tearDown(): void
    {
        foreach ([$this->baseline, $this->target] as $database) {
            $this->adminConnection()->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        parent::tearDown();
    }

    // ── Không được báo động giả ───────────────────────────────────────────────

    /**
     * Hai bên giống hệt nhau thì phải im lặng và trả 0.
     *
     * Khẳng định "2 khớp hoàn toàn" trước là bắt buộc: trên hai database rỗng
     * lệnh cũng trả 0 vì không có gì để so. Thiếu dòng đó thì test này xanh kể
     * cả khi hàm nạp dữ liệu hỏng hoàn toàn — đúng lớp lỗi cần chặn.
     */
    public function test_identical_databases_report_no_drift(): void
    {
        foreach (['alpha_domain', 'beta_domain'] as $name) {
            $this->insertFramework($this->baseline, $name, $this->samplePhase3());
            $this->insertFramework($this->target, $name, $this->samplePhase3());
        }

        [$exitCode, $output] = $this->runDiff();

        $this->assertStringContainsString('2 khớp hoàn toàn', $output);
        $this->assertStringContainsString('khớp nhau trên mọi trường prompt', $output);
        $this->assertSame(0, $exitCode);
    }

    // ── Phải phân loại đúng ───────────────────────────────────────────────────

    /**
     * CRLF vs LF là ĐỊNH DẠNG, không phải NỘI DUNG.
     *
     * Ca có thật chứ không phải giả định: <textarea> của admin form luôn gửi
     * CRLF, và PromptFrameworkObserver::saving() sinh ra để chặn đúng nó. Xếp
     * nhầm ca này sang NỘI DUNG là bắt người deploy đi đọc diff cho một thứ
     * chữa máy móc được.
     */
    public function test_line_ending_difference_is_classified_as_formatting(): void
    {
        $phase3 = $this->samplePhase3();

        $this->insertFramework($this->baseline, 'alpha_domain', $phase3);
        $this->insertFramework($this->target, 'alpha_domain', str_replace("\n", "\r\n", $phase3));

        [$exitCode, $output] = $this->runDiff();

        $this->assertStringContainsString('ĐỊNH DẠNG', $output);
        $this->assertStringNotContainsString('NỘI DUNG', $output);
        $this->assertSame(1, $exitCode);
    }

    /** Chữ khác thật thì phải là NỘI DUNG, và phải in ra dòng đã đổi. */
    public function test_real_text_change_is_classified_as_content(): void
    {
        $this->insertFramework($this->baseline, 'alpha_domain', $this->samplePhase3());
        $this->insertFramework(
            $this->target,
            'alpha_domain',
            str_replace('Name the specific stake.', 'Name the exact stake and the deadline.', $this->samplePhase3()),
        );

        [$exitCode, $output] = $this->runDiff();

        $this->assertStringContainsString('NỘI DUNG', $output);
        $this->assertStringContainsString('Name the exact stake and the deadline.', $output);
        $this->assertSame(1, $exitCode);
    }

    /**
     * Thêm/bớt một dòng kẻ ═ thuần trang trí vẫn là NỘI DUNG.
     *
     * Khoá lại một quyết định thiết kế cố ý chặt tay. Nới cho nó thành ĐỊNH DẠNG
     * nghe có vẻ hợp lý — dòng kẻ có mang nghĩa gì đâu — nhưng thế là công cụ tự
     * phán đoán rằng một dòng biến mất là vô hại, rồi tự chữa mà không hỏi. Đúng
     * ca travel_mobility ngày 2026-08-05: mất một dòng kẻ, và người phải là
     * người nhìn thấy nó.
     */
    public function test_decorative_rule_line_counts_as_content(): void
    {
        $phase3 = $this->samplePhase3();

        $this->insertFramework($this->baseline, 'alpha_domain', $phase3);
        $this->insertFramework(
            $this->target,
            'alpha_domain',
            str_replace(str_repeat('═', 42) . "\n", '', $phase3),
        );

        [$exitCode, $output] = $this->runDiff();

        $this->assertStringContainsString('NỘI DUNG', $output);
        $this->assertSame(1, $exitCode);
    }

    /** Bản ghi chỉ có một bên phải được gọi tên, không lặng lẽ bỏ qua. */
    public function test_record_missing_on_one_side_is_reported(): void
    {
        $this->insertFramework($this->baseline, 'alpha_domain', $this->samplePhase3());
        $this->insertFramework($this->target, 'alpha_domain', $this->samplePhase3());
        $this->insertFramework($this->target, 'extra_domain', $this->samplePhase3());

        [$exitCode, $output] = $this->runDiff();

        $this->assertStringContainsString('extra_domain', $output);
        $this->assertStringContainsString('CHỈ CÓ Ở BẢN SOI', $output);
        $this->assertSame(1, $exitCode);
    }

    // ── Không được hỏng trong im lặng ─────────────────────────────────────────

    /** Trỏ vào database không tồn tại phải gãy, tuyệt đối không báo "khớp". */
    public function test_unknown_database_never_reports_a_match(): void
    {
        $this->insertFramework($this->baseline, 'alpha_domain', $this->samplePhase3());

        $missing = "khong_ton_tai_{$this->target}";

        $this->withoutMockingConsoleOutput();

        try {
            $exitCode = Artisan::call('prompt:diff', [
                'baseline' => $this->baseline,
                'target'   => $missing,
            ]);
        } catch (\Throwable $exception) {
            // Ném lỗi cũng đạt — điều cấm là im lặng báo thành công. Nhưng lỗi
            // phải gọi tên database sai, nếu không thì người đọc không biết tìm đâu.
            $this->assertStringContainsString($missing, $exception->getMessage());

            return;
        }

        $this->assertNotSame(0, $exitCode, 'Database không tồn tại mà vẫn trả 0.');
        $this->assertStringNotContainsString('khớp nhau trên mọi trường prompt', Artisan::output());
    }

    /** So một database với chính nó là vô nghĩa — phải từ chối thay vì trả 0. */
    public function test_comparing_a_database_with_itself_is_refused(): void
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('prompt:diff', [
            'baseline' => $this->baseline,
            'target'   => $this->baseline,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cùng một database', Artisan::output());
    }

    // ── Hỗ trợ ────────────────────────────────────────────────────────────────

    /**
     * Chạy lệnh, trả về [mã thoát, văn bản thật].
     *
     * withoutMockingConsoleOutput() là bắt buộc: TestCase mock console mặc định,
     * và bản mock đó nuốt luôn Artisan::output().
     *
     * @return array{0: int, 1: string}
     */
    private function runDiff(): array
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('prompt:diff', [
            'baseline' => $this->baseline,
            'target'   => $this->target,
        ]);

        return [$exitCode, Artisan::output()];
    }

    /**
     * Connection tách khỏi transaction của RefreshDatabase.
     *
     * Mọi thứ test này ghi phải commit thật, vì prompt:diff đọc bằng connection
     * riêng của nó và sẽ không thấy dữ liệu còn nằm trong transaction.
     */
    private function adminConnection(): ConnectionInterface
    {
        $default = (string) config('database.default');

        config([
            'database.connections.prompt_diff_admin' => (array) config("database.connections.{$default}"),
        ]);

        return DB::connection('prompt_diff_admin');
    }

    /** Dựng database rỗng với khung bảng chép từ DB test đã migrate. */
    private function createDatabase(string $name): void
    {
        $admin  = $this->adminConnection();
        $source = (string) config('database.connections.' . config('database.default') . '.database');

        $admin->statement("DROP DATABASE IF EXISTS `{$name}`");
        $admin->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        foreach (self::TABLES as $table) {
            $admin->statement("CREATE TABLE `{$name}`.`{$table}` LIKE `{$source}`.`{$table}`");
        }
    }

    private function insertFramework(string $database, string $name, string $phase3): void
    {
        $this->adminConnection()->table("{$database}.prompt_frameworks")->insert([
            'id'                => (string) Str::uuid(),
            'name'              => $name,
            'group_description' => 'fixture',
            'system_prompt'     => 'fixture system prompt',
            'phase1_analyze'    => 'fixture phase 1',
            'phase2_diagnose'   => 'fixture phase 2',
            'phase3_generate'   => $phase3,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * phase3 rút gọn, giữ đúng hai đặc điểm khiến diff dễ đọc sai: dòng nội dung
     * mở đầu bằng "- ", và dòng kẻ ═ thuần trang trí.
     */
    private function samplePhase3(): string
    {
        $rule = str_repeat('═', 42);

        return implode("\n", [
            'FB_IMAGE_TEXT:',
            '- 1 sentence 70-100 chars.',
            '- Name the specific stake.',
            '',
            'FB_POST_CONTENT:',
            '• 150-250 chars. Plain text only.',
            '',
            $rule,
            'QUALITY GATE — verify before output',
            $rule,
            '[ ] Title starts with name, number, or active verb',
        ]);
    }
}
