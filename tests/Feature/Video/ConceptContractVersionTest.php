<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\VideoProject;
use App\Services\VideoProjectService;
use App\Video\Concept\ClaudeConceptDesigner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class ConceptContractVersionTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<string, mixed> */
    private function conceptInput(string $version): array
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST contract source',
            'title' => 'TEST contract article '.uniqid(),
            'slug' => 'test-contract-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        $project = VideoProject::create([
            'title' => 'TEST contract '.uniqid(),
            'article_id' => $article->id,
        ]);

        $method = new ReflectionMethod(VideoProjectService::class, 'conceptInput');
        $method->setAccessible(true);

        $input = $method->invoke(app(VideoProjectService::class), $project->fresh('article'), ['brief' => 1]);
        $input['instruction_version'] = $version;

        return $input;
    }

    private function hash(array $input): string
    {
        $method = new ReflectionMethod(\App\Services\Video\PlanningStageStore::class, 'hash');
        $method->setAccessible(true);

        return $method->invoke(app(\App\Services\Video\PlanningStageStore::class), $input);
    }

    public function test_the_concept_input_carries_the_instruction_version(): void
    {
        $input = $this->conceptInput(ClaudeConceptDesigner::INSTRUCTION_VERSION);

        $this->assertArrayHasKey('instruction_version', $input);
        $this->assertSame(ClaudeConceptDesigner::INSTRUCTION_VERSION, $input['instruction_version']);
    }

    public function test_changing_the_instruction_changes_the_hash_that_gates_a_rerun(): void
    {
        $this->assertNotSame(
            $this->hash($this->conceptInput('concept-v8')),
            $this->hash($this->conceptInput('concept-v9')),
            'Bump phien ban ma hash khong doi thi concept cu bi phuc vu tiep, im lang',
        );
    }

    public function test_the_shipped_version_is_the_one_that_knows_about_the_boot_stripe(): void
    {
        // `boot_stripe_colour` vao identity_slots o v9. Neu ai do them khe moi ma
        // quen bump, concept cu van duoc dung va prompt van thieu mau dai nuoc.
        //
        // v10 doi LUAT DEM: so lan lap khong con duoc mang di khap concept. Concept
        // v9 nao con trong DB deu la ban co so dem lan khap noi — chung phai bi
        // hash tu choi, khong duoc phuc vu tiep.
        $this->assertSame('concept-v15', ClaudeConceptDesigner::INSTRUCTION_VERSION);
        $this->assertStringContainsString(
            'verification count',
            config('video.creative_profiles.profiles.luxury_vessel.identity_slots')['visible_deck_tiers']['guidance'],
        );
        $this->assertArrayHasKey(
            'boot_stripe_colour',
            config('video.creative_profiles.profiles.luxury_vessel.identity_slots'),
        );
    }
}
