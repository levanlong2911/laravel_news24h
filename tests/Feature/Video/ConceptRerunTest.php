<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Nut "dung lai concept". Brief khong doi thi `claimProjectStage()` tra
 * `already_succeeded` va khong bao gio goi Sonnet lai — dung de chan bam trung,
 * nhung no chan luon ca truong hop hop le: concept nay ra tau xau, muon thu lai.
 *
 * `$force` bo qua DUNG MOT cua do. Moi chot khac giu nguyen.
 */
class ConceptRerunTest extends TestCase
{
    use DatabaseTransactions;

    private PlanningStageStore $store;

    private VideoProject $project;

    /** @var array<string, mixed> */
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PlanningStageStore;
        $this->project = VideoProject::create(['title' => 'TEST rerun '.uniqid()]);
        $this->input = ['article_id' => 'a1', 'inspiration_sha256' => hash('sha256', 'brief')];
    }

    private function succeededStage(): VideoPlanningStage
    {
        [$stage, $token] = $this->store->claimProjectStage(
            $this->project->id, PlanningStageName::CONCEPT, $this->input,
        );
        $this->store->finishSucceeded($stage->id, $token, '{}', ['design_identity' => ['x' => 1]]);

        return $stage->refresh();
    }

    public function test_without_the_button_a_settled_concept_is_never_rebuilt(): void
    {
        // Cua chong bam trung phai con nguyen: khong co `force` thi van `cached`.
        $this->succeededStage();

        [, $token, $reason] = $this->store->claimProjectStage(
            $this->project->id, PlanningStageName::CONCEPT, $this->input,
        );

        $this->assertSame('already_succeeded', $reason);
        $this->assertNull($token);
    }

    public function test_the_button_opens_a_new_revision_and_keeps_the_old_one(): void
    {
        // Luot cu DA TRA TIEN. Ghi de len no la xoa lich su chi tieu.
        $first = $this->succeededStage();

        [$second, $token, $reason] = $this->store->claimProjectStage(
            $this->project->id, PlanningStageName::CONCEPT, $this->input, true,
        );

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($token);
        $this->assertNotSame($first->id, $second->id);
        $this->assertGreaterThan($first->planning_revision, $second->planning_revision);

        $first->refresh();
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $first->status);
        $this->assertNotEmpty($first->output_json);
    }

    public function test_a_run_already_under_way_still_blocks_a_forced_one(): void
    {
        // `force` KHONG duoc bo qua `$live`: hai lan bam lien tiep trong mot ky
        // lease phai van chi mot lan goi Sonnet.
        $this->store->claimProjectStage($this->project->id, PlanningStageName::CONCEPT, $this->input);

        [, $token, $reason] = $this->store->claimProjectStage(
            $this->project->id, PlanningStageName::CONCEPT, $this->input, true,
        );

        $this->assertSame('claimed_by_other', $reason);
        $this->assertNull($token);
    }

    /**
     * Concept toi thieu ma parser chap nhan. Cac khe identity sinh TU CHINH ho so
     * chu khong viet tay: doi hop dong identity thi fixture tu bam theo, khong de
     * mot bai test mau xanh trong khi man hinh that da hong.
     *
     * @return array<string, mixed>
     */
    private function concept(): array
    {
        $profile = config('video.creative_profiles.profiles.luxury_vessel');
        $slotValue = function (array $spec) use (&$slotValue) {
            return match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                'enum' => $spec['values'][0],
                'object' => array_map($slotValue, $spec['fields']),
                default => 'test value',
            };
        };

        $identity = array_map($slotValue, $profile['identity_slots']);
        $identity['design_length_m'] = 120.0;
        $identity['design_beam_m'] = 20.0;
        $identity['length_to_beam_ratio'] = 6.0;


        return [
            'design_thesis' => 'One continuous line ties the whole form together.',
            'design_identity' => $identity,
            'form_relationships' => [
                'governing_line' => 'One continuous line runs the full length.',
                'massing_rhythm' => 'Volumes taper evenly toward the top.',
                'feature_integration' => 'Every feature is cut into the governing surface.',
            ],
            'signature_features' => [
                ['description' => 'One continuous band runs the full length',
                    'visible_from' => array_keys($profile['viewpoint_guidance'])],
            ],
            'decisions' => array_map(fn (string $aspect) => [
                'aspect' => $aspect,
                'provenance' => 'invented',
                'decision' => 'A deliberate choice made for this test fixture.',
            ], $profile['inspection_aspects']),
        ];
    }

    public function test_the_screen_offers_the_button_and_says_it_costs_money(): void
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            // Phai la category CO creative profile: man anchor bien dich prompt
            // ngay khi mo, va category khong co ho so se nem truoc do.
            'category_id' => DB::table('categories')->where('slug', 'yacht')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST rerun source',
            'title' => 'TEST rerun article '.uniqid(),
            'slug' => 'test-rerun-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);
        $project = VideoProject::create(['title' => 'TEST rerun ui '.uniqid(), 'article_id' => $article->id]);

        $brief = ['x' => 1];

        VideoPlanningStage::create([
            'project_id' => $project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::INSPIRATION->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', 'brief'),
            'output_json' => $brief,
        ]);

        // `input_hash` phai la hash THAT cua `conceptInput()`. Sai mot chut thi
        // man hinh doc ra "brief da doi" va hien nut khac — bai test se xanh hoac
        // do vi mot ly do khong lien quan gi toi thu dang kiem.
        $conceptInput = new ReflectionMethod(VideoProjectService::class, 'conceptInput');
        $conceptInput->setAccessible(true);
        $hash = new ReflectionMethod(PlanningStageStore::class, 'hash');
        $hash->setAccessible(true);

        VideoPlanningStage::create([
            'project_id' => $project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::CONCEPT->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => $hash->invoke($this->store, $conceptInput->invoke(
                app(VideoProjectService::class), $project->fresh('article'), $brief,
            )),
            'output_json' => $this->concept(),
        ]);

        $this->actingAs(Admin::create([
            'name' => 'TEST rerun admin '.uniqid(),
            'email' => 'test_rerun_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));

        $html = $this->get(route('video-projects.anchor', $project->id))->assertOk()->getContent();

        $this->assertStringContainsString('Dựng lại concept', $html);
        $this->assertStringContainsString('THIS ACTION COSTS MONEY', $html);
        // Nut chet cu phai bien mat — no chiem cho ma khong lam gi.
        $this->assertStringNotContainsString('Brief không đổi — concept cũ vẫn đúng', $html);
    }
}
