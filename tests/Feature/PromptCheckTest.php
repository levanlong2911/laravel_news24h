<?php

namespace Tests\Feature;

use App\Models\PromptFramework;
use Database\Seeders\PromptSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Lưới an toàn cho prompt:check.
 *
 * Hai loại khẳng định, cố ý tách rời:
 *
 *   1. Dữ liệu ĐANG CHẠY sạch — bắt hồi quy do migration hoặc do admin sửa form.
 *   2. Bản thân lệnh kiểm THỰC SỰ phát hiện được lỗi — không có phần này thì một
 *      audit hỏng thành no-op vẫn cho báo cáo xanh, và test số 1 mất hết ý nghĩa.
 *
 * ── Chạy trên database nào ──────────────────────────────────────────────────
 *
 * phpunit.xml trỏ DB_DATABASE sang news24h_test, nên RefreshDatabase an toàn:
 * nó dựng lại DB test chứ không đụng DB dev. Trước đây hai dòng sqlite/:memory:
 * ở đó bị comment, tức test chạy thẳng vào DB dev và RefreshDatabase đồng nghĩa
 * với xoá sạch dữ liệu thật.
 *
 * DB test khởi đầu rỗng nên mỗi test tự seed. Điều đó đổi ý nghĩa của test đầu
 * tiên theo hướng tốt hơn: nó không còn kiểm "DB trên máy tôi đang sạch" — thứ
 * phụ thuộc máy ai chạy — mà kiểm PromptSystemSeeder sinh ra trạng thái sạch.
 * Seeder là nguồn chân lý cho mọi môi trường mới, nên đó mới là thứ đáng khoá
 * trong CI. Còn kiểm dữ liệu đang chạy thật là việc của `php artisan prompt:check`
 * gọi tay hoặc lúc deploy.
 */
class PromptCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PromptSystemSeeder::class);
    }

    /**
     * Seeder phải sinh ra trạng thái qua được mọi nhóm kiểm.
     *
     * Khẳng định số lượng trước là bắt buộc: trên database rỗng, prompt:check
     * cũng trả exit 0 vì không có gì để kiểm. Thiếu dòng đó thì test này xanh
     * kể cả khi seeder hỏng hoàn toàn.
     */
    public function test_seeded_database_passes_every_audit(): void
    {
        $this->assertSame(8, PromptFramework::count(), 'Seeder phải tạo đủ 8 framework.');

        $this->artisan('prompt:check')->assertExitCode(0);
    }

    /**
     * Tái hiện lỗi migration 074940: regex khớp cả hai dòng "[ ] FB post:" rồi
     * ghi đè bằng cùng một chuỗi. Số dòng vẫn đúng, từ cấm vẫn sạch, placeholder
     * vẫn đủ — chỉ có dòng kiểm format biến mất. Đây là lớp lỗi mà mọi nhóm kiểm
     * khác đều bỏ lọt.
     */
    public function test_gate_audit_catches_duplicated_gate_lines(): void
    {
        $this->cloneFramework('test_gate_duplicate', function (string $phase3): string {
            preg_match_all('/\[ \] FB post:[^\n]*/u', $phase3, $matches);

            // Dòng thứ hai (ràng buộc format) bị thay bằng bản sao của dòng đầu.
            return str_replace($matches[0][1], $matches[0][0], $phase3);
        });

        $this->artisan('prompt:check')
            ->expectsOutputToContain('gate_lines')
            ->assertExitCode(1);
    }

    /**
     * Parser không được phép im lặng khi không hiểu tài liệu.
     *
     * Bản kiểm đầu tiên lọc phạm vi bằng "phase3 có mục FORBIDDEN PHRASES không",
     * nên đổi tiêu đề mục là framework đó lặng lẽ rơi khỏi phạm vi và báo cáo vẫn
     * xanh. Test này khoá hành vi ngược lại.
     */
    public function test_parser_fails_loud_when_forbidden_block_is_missing(): void
    {
        $this->cloneFramework('test_parser_error', function (string $phase3): string {
            return str_ireplace('FORBIDDEN', 'NOT ALLOWED', $phase3);
        });

        $this->artisan('prompt:check')
            ->expectsOutputToContain('PARSE_ERROR')
            ->assertExitCode(1);
    }

    /** Framework hỏng phải làm hỏng báo cáo, kể cả khi mọi cái khác đều sạch. */
    public function test_a_single_broken_framework_fails_the_whole_report(): void
    {
        $this->artisan('prompt:check')->assertExitCode(0);

        $this->cloneFramework('test_forbidden_in_type_name', fn (string $phase3): string => $phase3);

        // Content type mang đúng một từ cấm của framework vừa clone.
        $framework = PromptFramework::where('name', 'test_forbidden_in_type_name')->firstOrFail();

        $framework->contentTypes()->getRelated()->newQuery()->create([
            'framework_id'       => $framework->id,
            'type_code'          => 'test_case',
            'type_name'          => 'An Extraordinary Case',
            'trigger_keywords'   => ['test'],
            'tone_profile'       => ['neutral'],
            'structure_template' => "① HOOK — test\n② CONTEXT — test",
            'sort_order'         => 99,
            'is_active'          => true,
        ]);

        $this->artisan('prompt:check')
            ->expectsOutputToContain('extraordinary')
            ->assertExitCode(1);
    }

    /**
     * Vân tay phải chỉ phụ thuộc nội dung phase3.
     *
     * Nếu ai đó nhét updated_at, time() hay bất kỳ thứ gì đổi theo thời gian vào
     * hash thì mọi lần so local với production đều báo lệch — công cụ mất sạch
     * giá trị mà không ai nhận ra, vì báo cáo vẫn chạy và vẫn có vẻ đúng.
     */
    public function test_fingerprint_is_stable_across_runs(): void
    {
        $first  = $this->runFingerprint();
        $second = $this->runFingerprint();

        $this->assertNotEmpty($first, 'Không đọc được vân tay nào từ output.');
        $this->assertSame($first, $second);
    }

    /**
     * Mặt còn lại: một hàm hash trả về hằng số cũng "ổn định" hoàn hảo. Nội dung
     * khác nhau bắt buộc phải cho vân tay khác nhau, nếu không thì test ở trên
     * đang khoá một thứ vô dụng.
     */
    public function test_fingerprint_changes_when_phase3_changes(): void
    {
        $source = PromptFramework::query()
            ->where('is_active', true)
            ->where('name', 'not like', 'video_%')
            ->orderBy('name')
            ->firstOrFail();

        $this->cloneFramework(
            'test_fingerprint_delta',
            fn (string $phase3): string => $phase3 . "\n\nDòng thêm vào chỉ để đổi nội dung.",
        );

        $prints = $this->runFingerprint();

        $this->assertArrayHasKey('test_fingerprint_delta', $prints);
        $this->assertNotSame($prints[$source->name], $prints['test_fingerprint_delta']);
    }

    /**
     * Thuộc tính then chốt của cả tính năng: vân tay là hàm của RIÊNG nội dung
     * phase3 — không dính id, không dính tên.
     *
     * Nếu id lọt vào hash thì so local với production hỏng hoàn toàn, vì UUID mỗi
     * môi trường một khác: tám hash sẽ luôn luôn lệch dù dữ liệu giống hệt nhau.
     * Hai test kia không bắt được lỗi đó — bản clone của chúng khác nội dung LẪN
     * khác tên, nên một hàm băm chỉ dựa trên tên cũng qua được cả hai.
     */
    public function test_fingerprint_depends_only_on_phase3_content(): void
    {
        $source = PromptFramework::query()
            ->where('is_active', true)
            ->where('name', 'not like', 'video_%')
            ->orderBy('name')
            ->firstOrFail();

        // Khác id, khác tên, phase3 y hệt.
        $this->cloneFramework('test_fingerprint_twin', fn (string $phase3): string => $phase3);

        $prints = $this->runFingerprint();

        $this->assertSame($prints[$source->name], $prints['test_fingerprint_twin']);
    }

    // ── Hỗ trợ ────────────────────────────────────────────────────────────────

    /**
     * Chạy prompt:check --fingerprint và bóc ra map tên framework => vân tay.
     *
     * @return array<string, string>
     */
    private function runFingerprint(): array
    {
        // TestCase mock console output mặc định — đó là cơ chế cho
        // $this->artisan()->expectsOutputToContain() hoạt động, nhưng nó nuốt
        // luôn Artisan::output(). Ba test vân tay cần văn bản thật, không cần
        // expectation, nên tắt mock ở đây.
        $this->withoutMockingConsoleOutput();

        Artisan::call('prompt:check', ['--fingerprint' => true]);

        // Bỏ mã màu ANSI: có hay không tuỳ môi trường chạy test.
        $output = preg_replace('/\e\[[0-9;]*m/', '', Artisan::output());

        preg_match_all('/^\s+(\S+)\s+([0-9a-f]{12})\s/m', $output, $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }



    /**
     * Nhân bản một framework thật rồi bẻ phase3 theo $break.
     *
     * Nhân bản chứ không dựng từ đầu: bản sao thừa hưởng mọi thứ đang đúng, nên
     * chỉ còn đúng một thứ sai — nếu không thì test có thể xanh/đỏ vì lý do khác.
     */
    private function cloneFramework(string $name, callable $break): PromptFramework
    {
        $source = PromptFramework::query()
            ->where('is_active', true)
            ->where('name', 'not like', 'video_%')
            ->orderBy('name')
            ->firstOrFail();

        return PromptFramework::create([
            'name'              => $name,
            'group_description' => 'fixture — RefreshDatabase sẽ dọn',
            'system_prompt'     => $source->system_prompt,
            'phase1_analyze'    => $source->phase1_analyze,
            'phase2_diagnose'   => $source->phase2_diagnose,
            'phase3_generate'   => $break($source->phase3_generate),
            'is_active'         => true,
        ]);
    }
}
