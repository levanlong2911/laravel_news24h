<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiPhaseSeeder extends Seeder
{
    private const DATA_FILE = __DIR__.'/data/ai_phases.php';

    public function run(): void
    {
        /** @var array{schemas: list<array<string, mixed>>, phases: list<array<string, mixed>>, links: list<array<string, mixed>>} $data */
        $data = require self::DATA_FILE;
        $categories = $this->categoryIds(array_values(array_unique(array_column($data['phases'], 'category'))));

        DB::transaction(function () use ($data, $categories): void {
            foreach ($data['schemas'] as $row) {
                $this->createIfMissing('ai_json_schemas', $row, "schema {$row['namespace']}/{$row['schema_key']}@{$row['version']}");
            }

            foreach ($data['phases'] as $row) {
                $slug = $row['category'];
                unset($row['category']);
                $this->createIfMissing('ai_phases', ['category_id' => $categories[$slug]] + $row, "phase {$slug}/{$row['phase_code']} rev {$row['revision']}");
            }

            foreach ($data['links'] as $row) {
                $this->createIfMissing('ai_phase_schemas', $row, "link {$row['phase_id']} {$row['role']}/{$row['slot_key']}");
            }

            $this->assertOneCurrentPerPhase();
        });
    }

    /**
     * @param list<string> $slugs
     * @return array<string, string>
     */
    private function categoryIds(array $slugs): array
    {
        $ids = [];

        foreach ($slugs as $slug) {
            $matches = DB::table('categories')->where('slug', $slug)->pluck('id');

            if ($matches->count() !== 1) {
                throw new RuntimeException("Category slug {$slug} matches {$matches->count()} rows instead of exactly one. Nothing was written.");
            }

            $ids[$slug] = (string) $matches->first();
        }

        return $ids;
    }

    /** @param array<string, mixed> $row */
    private function createIfMissing(string $table, array $row, string $label): void
    {
        $created = ! DB::table($table)->where('id', $row['id'])->exists();

        if ($created) {
            DB::table($table)->insert($row);
        }

        $this->command->info(sprintf('%-7s %s', $created ? 'created' : 'kept', $label));
    }

    private function assertOneCurrentPerPhase(): void
    {
        $doubled = DB::table('ai_phases')->where('is_current', true)
            ->groupBy('category_id', 'workflow_code', 'phase_code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phase_code');

        if ($doubled->isNotEmpty()) {
            throw new RuntimeException('More than one current revision for: '.$doubled->implode(', ').'. Nothing was written.');
        }
    }
}
