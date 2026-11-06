<?php

namespace Tests\Feature;

use App\Models\AiPhase;
use App\Video\Moto\MotoContract;
use App\Video\Superyacht\Spec\PhaseResolver;
use Database\Seeders\AiPhaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AiPhaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    private const PHASE_8_REV_1 = 'a3265213-971c-4fa8-88a7-6cd3279135ab';

    private const PHASE_8_REV_2 = '9fb2d804-aaca-4d0c-bf3e-733c6d1193b0';

    private const PHASE_8_REV_3 = '6c76db7c-8a86-4f68-a710-968838646703';

    private const PHASE_8 = 'fd5cc579-5bc1-41c1-ba6a-0c8b506f632d';

    public function test_the_phase_8_rows_are_the_release_packages_byte_for_byte(): void
    {
        foreach (['ai_phases_moto_phase_8.php' => 'moto-phase-8-reference-v1', 'ai_phases_moto_phase_8_v2.php' => 'moto-phase-8-reference-v2', 'ai_phases_moto_phase_8_v3.php' => 'moto-phase-8-reference-v3', 'ai_phases_moto_phase_8_v4.php' => 'moto-phase-8-reference-v4'] as $file => $release) {
            $data = require database_path('seeders/data/'.$file);
            $package = base_path("database/releases/{$release}/");

            if (! is_dir($package)) {
                self::markTestSkipped('The release packages stay off git: '.$release);
            }

            self::assertSame(file_get_contents($package.'phase-8.md'), $data['phases'][0]['prompt_content'], $release);
            self::assertSame(file_get_contents($package.'MotoReferencePrompt.schema.json'), $data['schemas'][0]['schema_content'], $release);
            self::assertSame(hash('sha256', $data['phases'][0]['prompt_content']), $data['phases'][0]['content_sha256'], $release);
            self::assertSame(hash('sha256', $data['schemas'][0]['schema_content']), $data['schemas'][0]['schema_sha256'], $release);
        }
    }

    public function test_seeding_publishes_the_newest_phase_8_revision_keeps_the_older_ones_as_history_and_a_second_run_changes_nothing(): void
    {
        $this->categories();
        $category = (string) DB::table('categories')->where('slug', 'moto')->value('id');

        $this->artisan('db:seed', ['--class' => AiPhaseSeeder::class, '--force' => true])->assertExitCode(0);

        $phase = AiPhase::currentFor($category, MotoContract::WORKFLOW, 'phase-8');
        self::assertSame([self::PHASE_8, 4, AiPhase::PUBLISHED], [(string) $phase->id, (int) $phase->revision, $phase->status]);
        self::assertSame(hash('sha256', (string) $phase->prompt_content), (string) $phase->content_sha256);

        foreach ([self::PHASE_8_REV_1, self::PHASE_8_REV_2, self::PHASE_8_REV_3] as $older) {
            self::assertSame([AiPhase::PUBLISHED, 0], [DB::table('ai_phases')->where('id', $older)->value('status'), (int) DB::table('ai_phases')->where('id', $older)->value('is_current')], 'an old revision stays as history and stops being current');
        }

        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forCategory($category, 'spec.reference');
        self::assertTrue($bundle->ready(), implode('; ', $bundle->mismatches()));
        self::assertSame('https://superyacht.local/schemas/MotoReferencePrompt', json_decode((string) $bundle->outputSchemas()[0]['content'], true)['$id']);

        $script = AiPhase::currentFor($category, MotoContract::WORKFLOW, 'phase-4');
        self::assertSame(11, (int) $script->revision, 'the data files load in natural order, so a later revision follows the one it is based on');
        self::assertSame([0, 0, 0, 0], array_map(static fn (int $revision): int => (int) DB::table('ai_phases')->where('category_id', $category)->where('workflow_code', MotoContract::WORKFLOW)->where('phase_code', 'phase-4')->where('revision', $revision)->value('is_current'), [7, 8, 9, 10]));
        $scriptBundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forCategory($category, 'spec.script');
        self::assertTrue($scriptBundle->ready(), implode('; ', $scriptBundle->mismatches()));

        $counts = static fn (): array => [DB::table('ai_json_schemas')->count(), DB::table('ai_phases')->count(), DB::table('ai_phase_schemas')->count()];
        $before = $counts();
        $current = DB::table('ai_phases')->orderBy('id')->pluck('is_current', 'id');
        $this->artisan('db:seed', ['--class' => AiPhaseSeeder::class, '--force' => true])->assertExitCode(0);
        self::assertSame($before, $counts(), 'a second run keeps every row');
        self::assertEquals($current, DB::table('ai_phases')->orderBy('id')->pluck('is_current', 'id'), 'a second run moves no flag');
    }

    public function test_a_database_seeded_earlier_moves_to_the_new_current_revision_and_the_old_text_stays(): void
    {
        $this->categories();
        $this->seed(AiPhaseSeeder::class);
        [$old, $new] = $this->lastTwoRevisions('phase-3');
        $content = DB::table('ai_phases')->where('id', $old['id'])->value('prompt_content');
        DB::table('ai_phase_schemas')->where('phase_id', $new['id'])->delete();
        DB::table('ai_phases')->where('id', $new['id'])->delete();
        DB::table('ai_phases')->where('id', $old['id'])->update(['is_current' => true]);

        $this->seed(AiPhaseSeeder::class);

        self::assertSame($new['id'], $this->currentId($new));
        self::assertSame($content, DB::table('ai_phases')->where('id', $old['id'])->value('prompt_content'), 'only the flag of the old revision moves');
    }

    public function test_a_current_revision_the_data_files_do_not_know_stays_current(): void
    {
        $this->categories();
        [, $new] = $this->lastTwoRevisions('phase-3');
        $local = (string) Str::uuid();
        DB::table('ai_phases')->insert(['id' => $local, 'phase_code' => $new['phase_code'], 'revision' => 999, 'status' => 'published', 'is_current' => true, 'content_sha256' => hash('sha256', 'local')]
            + ['category_id' => DB::table('categories')->where('slug', $new['category'])->value('id')]
            + array_intersect_key($new, array_flip(['workflow_code', 'kind', 'name', 'sort_order', 'prompt_content'])));

        $this->seed(AiPhaseSeeder::class);

        self::assertSame($local, $this->currentId($new));
        self::assertSame(0, (int) DB::table('ai_phases')->where('id', $new['id'])->value('is_current'), 'the revision of the data file is stored as history');
    }

    public function test_a_higher_revision_already_on_the_server_stays_current_and_the_older_rows_become_history(): void
    {
        $category = $this->withoutMotoPhase8();
        $newer = (string) Str::uuid();
        $this->storeRevision($category, $newer, 5);

        $this->artisan('db:seed', ['--class' => AiPhaseSeeder::class, '--force' => true])->assertExitCode(0);

        $phase = AiPhase::currentFor($category, MotoContract::WORKFLOW, 'phase-8');
        self::assertSame([$newer, 5], [(string) $phase->id, (int) $phase->revision], 'seeding never takes the current revision backwards');
        self::assertSame([0, 0, 0, 0], array_map(static fn (string $id): int => (int) DB::table('ai_phases')->where('id', $id)->value('is_current'), [self::PHASE_8_REV_1, self::PHASE_8_REV_2, self::PHASE_8_REV_3, self::PHASE_8]), 'the older revisions are kept as history');
    }

    public function test_the_same_revision_with_another_id_stops_the_seeding(): void
    {
        $this->storeRevision($this->withoutMotoPhase8(), (string) Str::uuid(), 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicts with the stored row');
        $this->artisan('db:seed', ['--class' => AiPhaseSeeder::class, '--force' => true])->run();
    }

    public function test_the_same_row_with_another_text_stops_the_seeding(): void
    {
        $this->storeRevision($this->withoutMotoPhase8(), self::PHASE_8_REV_2, 2, 'Another text.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicts with the stored row');
        $this->artisan('db:seed', ['--class' => AiPhaseSeeder::class, '--force' => true])->run();
    }

    private function categories(): void
    {
        foreach (['moto', 'yacht'] as $slug) {
            if (! DB::table('categories')->where('slug', $slug)->exists()) {
                DB::table('categories')->insert(['id' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function withoutMotoPhase8(): string
    {
        $this->categories();
        $category = (string) DB::table('categories')->where('slug', 'moto')->value('id');
        $stored = DB::table('ai_phases')->where('category_id', $category)->where('workflow_code', MotoContract::WORKFLOW)->where('phase_code', 'phase-8')->pluck('id');
        DB::table('ai_phase_schemas')->whereIn('phase_id', $stored)->delete();
        DB::table('ai_phases')->whereIn('id', $stored)->orderByDesc('revision')->delete();

        return $category;
    }

    private function storeRevision(string $category, string $id, int $revision, ?string $text = null): void
    {
        $data = require database_path('seeders/data/ai_phases_moto_phase_8_v2.php');
        $row = $data['phases'][0];
        unset($row['category']);
        $prompt = $text ?? (string) $row['prompt_content'];

        DB::table('ai_phases')->insert(['id' => $id, 'category_id' => $category, 'revision' => $revision, 'based_on_id' => null, 'prompt_content' => $prompt, 'content_sha256' => hash('sha256', $prompt)] + $row);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the revision before the current one and the current one of a yacht phase */
    private function lastTwoRevisions(string $phaseCode): array
    {
        $data = require database_path('seeders/data/ai_phases.php');
        $rows = array_values(array_filter($data['phases'], static fn (array $row): bool => $row['category'] === 'yacht' && $row['phase_code'] === $phaseCode));
        usort($rows, static fn (array $a, array $b): int => $a['revision'] <=> $b['revision']);

        return array_slice($rows, -2);
    }

    /** @param array<string, mixed> $row */
    private function currentId(array $row): ?string
    {
        return DB::table('ai_phases')->where('workflow_code', $row['workflow_code'])->where('phase_code', $row['phase_code'])->where('is_current', true)->value('id');
    }
}
