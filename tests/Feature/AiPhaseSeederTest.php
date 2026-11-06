<?php

namespace Tests\Feature;

use Database\Seeders\AiPhaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Seeder 3 bang AI tren DB rieng: DB da seed truoc do van len duoc ban moi, chi doi co is_current. */
final class AiPhaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array{schemas: list<array<string, mixed>>, phases: list<array<string, mixed>>, links: list<array<string, mixed>>} */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = require database_path('seeders/data/ai_phases.php');

        foreach (array_unique(array_column($this->data['phases'], 'category')) as $slug) {
            DB::table('categories')->insert(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_a_database_seeded_earlier_moves_to_the_new_current_revision(): void
    {
        $this->seed(AiPhaseSeeder::class);
        [$old, $new] = $this->lastTwoRevisions('phase-3');
        $content = DB::table('ai_phases')->where('id', $old['id'])->value('prompt_content');
        DB::table('ai_phase_schemas')->where('phase_id', $new['id'])->delete();
        DB::table('ai_phases')->where('id', $new['id'])->delete();
        DB::table('ai_phases')->where('id', $old['id'])->update(['is_current' => true]);

        $this->seed(AiPhaseSeeder::class);

        self::assertSame($new['id'], $this->currentId($new));
        self::assertSame($content, DB::table('ai_phases')->where('id', $old['id'])->value('prompt_content'), 'Revision cu chi doi co, noi dung giu nguyen.');
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(AiPhaseSeeder::class);
        $before = DB::table('ai_phases')->orderBy('id')->pluck('is_current', 'id');

        $this->seed(AiPhaseSeeder::class);

        self::assertEquals($before, DB::table('ai_phases')->orderBy('id')->pluck('is_current', 'id'));
        self::assertSame(count($this->data['phases']), $before->count());
    }

    public function test_a_current_revision_the_file_does_not_know_is_not_overwritten(): void
    {
        [, $new] = $this->lastTwoRevisions('phase-3');
        $local = (string) Str::uuid();
        DB::table('ai_phases')->insert(['id' => $local, 'phase_code' => $new['phase_code'], 'revision' => 999, 'status' => 'published', 'is_current' => true, 'content_sha256' => hash('sha256', 'local')]
            + ['category_id' => DB::table('categories')->where('slug', $new['category'])->value('id')]
            + array_intersect_key($new, array_flip(['workflow_code', 'kind', 'name', 'sort_order', 'prompt_content'])));

        $this->expectException(QueryException::class);

        try {
            $this->seed(AiPhaseSeeder::class);
        } finally {
            self::assertSame($local, $this->currentId($new), 'Ban cuc bo ngoai file van la ban hien hanh.');
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} ban lien truoc va ban hien hanh cua phase yacht */
    private function lastTwoRevisions(string $phaseCode): array
    {
        $rows = array_values(array_filter($this->data['phases'], static fn (array $row): bool => $row['category'] === 'yacht' && $row['phase_code'] === $phaseCode));
        usort($rows, static fn (array $a, array $b): int => $a['revision'] <=> $b['revision']);

        return array_slice($rows, -2);
    }

    /** @param array<string, mixed> $row */
    private function currentId(array $row): ?string
    {
        return DB::table('ai_phases')->where('workflow_code', $row['workflow_code'])->where('phase_code', $row['phase_code'])->where('is_current', true)->value('id');
    }
}
