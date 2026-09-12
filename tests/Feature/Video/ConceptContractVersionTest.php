<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\VideoProject;
use App\Services\VideoProjectService;
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

        $method = new ReflectionMethod(VideoProjectService::class, 'canonicalConceptStageInput');
        $method->setAccessible(true);

        $input = $method->invoke(app(VideoProjectService::class), $project->fresh('article'), 'yacht');
        $input['canonical_prompt_version'] = $version;

        return $input;
    }

    private function hash(array $input): string
    {
        $method = new ReflectionMethod(\App\Services\Video\PlanningStageStore::class, 'hash');
        $method->setAccessible(true);

        return $method->invoke(app(\App\Services\Video\PlanningStageStore::class), $input);
    }

    public function test_the_concept_input_carries_the_prompt_version(): void
    {
        $version = (string) config('canonical_concept.prompt_version');
        $input = $this->conceptInput($version);

        $this->assertArrayHasKey('canonical_prompt_version', $input);
        $this->assertSame($version, $input['canonical_prompt_version']);
    }

    public function test_changing_the_instruction_changes_the_hash_that_gates_a_rerun(): void
    {
        $this->assertNotSame(
            $this->hash($this->conceptInput('concept-v1')),
            $this->hash($this->conceptInput('concept-v2')),
            'Bump phien ban ma hash khong doi thi concept cu bi phuc vu tiep, im lang',
        );
    }

    public function test_the_gate_also_watches_the_schema_and_the_model(): void
    {
        // Duoi Phan 1 co BA thu doi duoc ket qua concept, khong con mot:
        // prompt, schema cua CanonicalDesignSpec, va model. Doi bat ky cai nao
        // ma hash khong doi thi concept cu bi phuc vu tiep, im lang.
        $base = $this->conceptInput((string) config('canonical_concept.prompt_version'));

        foreach (['canonical_schema_version', 'canonical_model'] as $key) {
            $changed = $base;
            $changed[$key] = $base[$key].'-changed';

            $this->assertNotSame($this->hash($base), $this->hash($changed), $key);
        }
    }
}
