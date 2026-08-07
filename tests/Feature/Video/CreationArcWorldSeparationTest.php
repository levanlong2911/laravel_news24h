<?php

namespace Tests\Feature\Video;

use App\Video\Story\CreationArcPlanner;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

/**
 * NƠI CHỐN chỉ được sống ở MỘT chỗ: `phase.world` (khoá ngữ nghĩa).
 *
 * Bối cảnh: trước đây mô tả thế giới nằm lẫn trong `composition_note` của từng
 * pha VÀ trong hồ sơ ngành bên Python (`anchor_setting`) — cùng một sự thật
 * viết ở 4 chỗ. Hậu quả đo được (2026-08-03): prose ghi "At an open waterside
 * shipyard under a clear sky" trong khi nghiệp vụ nói du thuyền đóng trong nhà
 * xưởng, và không có gì phát hiện được mâu thuẫn đó cho tới lúc nhìn ảnh render.
 *
 * Hai test dưới đây khoá lại ranh giới:
 *   - Laravel chỉ gửi KHOÁ, không gửi tiếng Anh  → Python tự diễn đạt
 *   - prose không được nhắc nơi chốn nữa         → không còn hai nguồn để chọi
 *
 * Đây là test CONFIG (dữ liệu người viết), nên nó phải boot Laravel để đọc
 * config('video') thật — khác CreationArcPlannerTest vốn cố ý chạy trên fixture
 * và không gọi config().
 */
class CreationArcWorldSeparationTest extends TestCase
{
    /**
     * Từ chỉ NƠI CHỐN. Không phải danh sách "từ cấm" mở rộng vô hạn — chỉ đủ bắt
     * đúng loại lỗi đã xảy ra thật. Muốn tả nơi chốn thì khai `world`, đừng viết
     * vào prose.
     */
    private const PLACE_WORDS = ['shipyard', 'hall', 'sky', 'outdoor', 'indoor', 'dry dock', 'quay', 'workshop'];

    public function test_phase_prose_never_states_the_place_when_setting_is_declared(): void
    {
        $checked = 0;

        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                if (! isset($phase['setting'])) {
                    continue;
                }
                $checked++;
                $note = strtolower($phase['composition_note'] ?? '');

                foreach (self::PLACE_WORDS as $word) {
                    $this->assertStringNotContainsString($word, $note, sprintf(
                        "%s.%s: composition_note nhắc nơi chốn ('%s') trong khi pha này đã khai setting. ".
                        'Nơi chốn chỉ được sống ở setting — nếu không, đổi nghiệp vụ phải sửa hai chỗ và chúng sẽ lệch nhau.',
                        $setKey, $phaseKey, $word,
                    ));
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Không pha nào khai world — test này đang không bảo vệ gì cả.');
    }

    /**
     * MẮT NỐI CUỐI: plan ĐÃ MERGE Creation Arc phải qua được schema v1.0.
     *
     * `RenderPlanSchemaTest` chỉ validate golden fixture — fixture đó KHÔNG có
     * scene do Creation Arc sinh, nên `scene.world` và `director_notes.crowd`
     * (hai field vừa thêm) chưa từng bị schema soi. Và production KHÔNG validate
     * (schema chỉ được đọc trong test), nên một field phá contract sẽ đi thẳng
     * sang Python mà không ai chặn.
     *
     * Test này chạy ĐÚNG đường thật: config/video.php -> CreationArcPlanner ->
     * mergeInto -> schema.
     */
    public function test_creation_arc_plan_satisfies_the_frozen_contract(): void
    {
        $fixture = __DIR__.'/../../../contracts/renderplan/v1.0/fixtures/moonrise.json';
        $schemaPath = __DIR__.'/../../../contracts/renderplan/v1.0/schema.json';
        $this->assertFileExists($fixture, 'Thiếu golden fixture — không có plan hợp lệ để merge arc vào.');

        $plan = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $heroId = $plan['world']['entities'][0]['id'];

        $set = config('video.creation_arc.phase_sets.vessel');
        $merged = (new CreationArcPlanner($set['phases'], $set['identity'] ?? []))
            ->mergeInto($plan, $heroId, 'Test Vessel');

        // Có ít nhất một scene mang `world` và một scene mang `crowd` — nếu không,
        // test này đang xanh mà chẳng bảo vệ gì.
        $withWorld = array_filter($merged['scenes'], fn ($s) => isset($s['setting']));
        $withCrowd = array_filter($merged['scenes'], fn ($s) => ! empty($s['director_notes']['crowd']));
        $withState = array_filter($merged['scenes'], fn ($s) => isset($s['requires_state']));
        $this->assertNotEmpty($withWorld, 'Không scene nào có setting — test không bảo vệ gì.');
        $this->assertNotEmpty($withCrowd, 'Không scene nào có crowd — test không bảo vệ gì.');
        $this->assertNotEmpty($withState, 'Không scene nào có requires_state — test không bảo vệ gì.');

        $result = (new Validator)->validate(
            json_decode(json_encode($merged), false, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR),
        );

        if ($result->hasError()) {
            $this->fail("Plan có Creation Arc KHÔNG qua schema v1.0:\n".json_encode(
                (new ErrorFormatter)->format($result->error()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        }
        $this->addToAssertionCount(1);
    }

    public function test_setting_carries_semantic_keys_only_never_english(): void
    {
        // Laravel là Semantic Authority: nó nói "loại nơi nào", không nói "tả ra
        // sao". Giá trị phải là khoá snake_case khớp enum trong
        // contracts/renderplan/v1.0/schema.json, không phải một câu.
        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                foreach ($phase['setting'] ?? [] as $axis => $value) {
                    $this->assertMatchesRegularExpression(
                        '/^[a-z0-9]+(_[a-z0-9]+)*$/',
                        (string) $value,
                        "{$setKey}.{$phaseKey}.setting.{$axis} = '{$value}' — phải là khoá ngữ nghĩa snake_case, không phải văn xuôi.",
                    );
                }
            }
        }
    }

    /**
     * `requires_state` là TOÀN BỘ HOẶC KHÔNG GÌ trong mỗi phase set.
     *
     * Không ép mọi set phải khai: `restoration` (phục chế xe) chưa có chuỗi nào
     * chứng minh được trạng thái nào, nên bắt nó khai là đặt một đòi hỏi vĩnh
     * viễn không thoả — mọi clip thành UNSATISFIABLE và ta giết một arc đang
     * chạy được. Set không khai thì đi đường cũ (một ảnh neo), y như hôm nay.
     *
     * Chỗ nguy hiểm là NỬA VỜI: set khai 4/6 pha thì 4 clip được canh, 2 clip
     * lặng lẽ lấy nhầm ảnh — và không có gì phân biệt "cố ý bỏ" với "quên".
     * Test này chỉ cấm đúng trạng thái nửa vời đó.
     *
     * Khai rồi thì phải khai bằng KHOÁ NẰM TRONG TỪ VỰNG: resolver bên Python so
     * khớp CHÍNH XÁC, nên 'shell_complete' thay vì 'hull_shell' là mất clip vì
     * một lỗi đánh máy. Từ vựng là dữ liệu bên Python
     * (construction_chains/*.json, trường `states`) — đây là ranh giới hai repo
     * nên thiếu file thì bỏ qua phần đối chiếu chứ không FAIL: người chỉ làm CMS
     * không có repo Python trên máy.
     */
    public function test_requires_state_is_declared_for_a_whole_phase_set_or_not_at_all(): void
    {
        // Đường dẫn suy ra từ `runner.runner_dir` — đã có sẵn và đã trỏ đúng repo
        // Python. Thêm một knob config thứ hai cho cùng một thư mục là mời hai
        // giá trị lệch nhau.
        // `runner_dir` trỏ vào thư mục CHỨA SCRIPT (`.../AI VIDEO/tools`), nên
        // `media_runtime/` nằm ở thư mục cha. Thử cả hai cấp thay vì cứng một
        // cấu trúc — repo Python nằm ở đâu là chuyện của từng máy.
        $runnerDir = rtrim((string) config('video.runner.runner_dir', ''), '\\/');
        $states = null;
        if ($runnerDir !== '') {
            $glob = '/media_runtime/design/data/construction_chains/*.json';
            $files = array_merge(glob($runnerDir.$glob) ?: [], glob(dirname($runnerDir).$glob) ?: []);
            foreach ($files as $file) {
                $vocab = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['states'] ?? [];
                $states = array_values(array_unique(array_merge($states ?? [], $vocab)));
            }
        }

        $sets = config('video.creation_arc.phase_sets', []);
        $this->assertNotEmpty($sets, 'video.creation_arc.phase_sets rỗng — test không bảo vệ gì.');

        $checkedAgainstVocabulary = false;

        foreach ($sets as $setKey => $set) {
            $phases = $set['phases'] ?? [];
            $declared = array_keys(array_filter($phases, fn ($p) => isset($p['requires_state'])));
            $missing = array_values(array_diff(array_keys($phases), $declared));

            if ($declared === []) {
                continue;   // set chưa dùng hợp đồng trạng thái — hợp lệ, đi đường cũ
            }

            $this->assertSame([], $missing, sprintf(
                'Set `%s` khai requires_state NỬA VỜI: %d/%d pha có, thiếu [%s]. '.
                'Pha thiếu sẽ lặng lẽ lấy nhầm ảnh nguồn trong khi các pha khác được canh — '.
                'khai hết, hoặc bỏ hết.',
                $setKey, count($declared), count($phases), implode(', ', $missing),
            ));

            foreach ($phases as $phaseKey => $phase) {
                $state = (string) $phase['requires_state'];
                $this->assertMatchesRegularExpression('/^[a-z0-9]+(_[a-z0-9]+)*$/', $state,
                    "{$setKey}.{$phaseKey}.requires_state = '{$state}' — phải là khoá snake_case.");

                if ($states !== null) {
                    $this->assertContains($state, $states,
                        "{$setKey}.{$phaseKey}.requires_state = '{$state}' không có trong từ vựng đóng: ".implode(', ', $states));
                    $checkedAgainstVocabulary = true;
                }
            }
        }

        if (! $checkedAgainstVocabulary) {
            $this->markTestIncomplete('Không đọc được construction_chains/*.json (VIDEO_RUNNER_DIR?) — phần đối chiếu từ vựng đã bị bỏ qua.');
        }
    }
}
