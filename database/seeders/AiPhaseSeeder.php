<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiPhaseSeeder extends Seeder
{
    private const DATA_FILES = __DIR__.'/data/ai_phases*.php';

    public function run(): void
    {
        /** @var array{schemas: list<array<string, mixed>>, phases: list<array<string, mixed>>, links: list<array<string, mixed>>} $data */
        $data = ['schemas' => [], 'phases' => [], 'links' => []];

        $files = glob(self::DATA_FILES) ?: [];
        natsort($files);

        foreach ($files as $file) {
            $data = array_merge_recursive($data, require $file);
        }

        $categories =$this->categoryIds(array_values(array_unique(array_column($data['phases'], 'category'))));

        DB::transaction(function () use ($data, $categories): void {
            foreach ($data['schemas'] as $row) {
                $this->createIfMissing('ai_json_schemas', $row, "schema {$row['namespace']}/{$row['schema_key']}@{$row['version']}");
            }

            // Before the inserts: a new current revision hits ai_phases_current_key_unique while the old one is current.
            $this->demoteSuperseded($data['phases']);

            foreach ($data['phases'] as $row) {
                $slug = $row['category'];
                unset($row['category']);
                $this->placePhase($categories[$slug], $row, "phase {$slug}/{$row['phase_code']} rev {$row['revision']}");
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

    /**
     * @param array<string, mixed> $row a phase revision of the data files: a stored one stays as it is; a new one takes over as current only from a lower revision, which stays published as history, and below the current revision it is stored as history itself
     *
     * @throws RuntimeException when the table holds this revision under another id or text, or this id under another revision
     */
    private function placePhase(string $category, array $row, string $label): void
    {
        $phase = fn () => DB::table('ai_phases')->where('category_id', $category)->where('workflow_code', $row['workflow_code'])->where('phase_code', $row['phase_code']);
        $sameRevision = $phase()->where('revision', $row['revision'])->first(['id', 'content_sha256']);
        $sameId = DB::table('ai_phases')->where('id', $row['id'])->exists();

        if ($sameRevision !== null ? ((string) $sameRevision->id !== (string) $row['id'] || (string) $sameRevision->content_sha256 !== (string) $row['content_sha256']) : $sameId) {
            throw new RuntimeException("{$label} ({$row['id']}) conflicts with the stored row of that revision or id. Nothing was written.");
        }

        if ($sameRevision === null && $row['is_current']) {
            $current = $phase()->where('is_current', true)->first(['id', 'revision']);

            if ($current !== null && (int) $current->revision > (int) $row['revision']) {
                $row['is_current'] = 0;
                $label .= " (history, rev {$current->revision} stays current)";
            } elseif ($current !== null) {
                DB::table('ai_phases')->where('id', $current->id)->update(['is_current' => false]);
            }
        }

        $this->createIfMissing('ai_phases', ['category_id' => $category] + $row, $label);
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

    /**
     * A database seeded earlier still marks as current a revision the data file has replaced. Only the flag
     * moves, as a publish does: the revision itself stays, and a revision the file does not know is left alone.
     *
     * @param list<array<string, mixed>> $phases
     */
    private function demoteSuperseded(array $phases): void
    {
        $superseded = array_column(array_filter($phases, static fn (array $row): bool => ! $row['is_current']), 'id');

        foreach (DB::table('ai_phases')->whereIn('id', $superseded)->where('is_current', true)->get(['id', 'phase_code', 'revision']) as $row) {
            DB::table('ai_phases')->where('id', $row->id)->update(['is_current' => false]);
            $this->command->info(sprintf('%-7s phase %s rev %d', 'demoted', $row->phase_code, $row->revision));
        }
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
