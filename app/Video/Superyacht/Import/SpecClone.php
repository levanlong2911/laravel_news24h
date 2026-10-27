<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Import;

use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\Category;
use App\Video\Superyacht\Spec\PhaseRevisions;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tao bo phase cho mot chu de moi bang cach chep bo dang hien hanh cua mot chu de da co, chi trong DB:
 * moi phase hien hanh (prompt, needs, parallel_with, stop, lien ket schema) thanh revision 1 cua chu
 * de moi, based_on tro ve ban goc. Chu de dich phai chua co phase nao cua workflow.
 */
final class SpecClone
{
    private const LOCK = 'yacht-spec-clone';

    private const COPIED = ['workflow_code', 'phase_code', 'kind', 'name', 'sort_order', 'prompt_content', 'content_sha256', 'needs', 'parallel_with', 'stop', 'source_file', 'source_section_key'];

    private const LINK_FIELDS = ['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required'];

    /** @param array<string, string> $categoryWorkflows video.superyacht.contracts.categories */
    public function __construct(private readonly array $categoryWorkflows) {}

    /** @param array<string, mixed> $config video.superyacht */
    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['contracts']['categories'] ?? []));
    }

    /** @return array<string, mixed> Chi doc: dung cho buoc xem truoc. */
    public function plan(string $fromSlug, string $toSlug): array
    {
        if ($fromSlug === '' || $toSlug === '' || $fromSlug === $toSlug) {
            throw new RuntimeException('Give two different category slugs: --from (source) and --to (new topic).');
        }

        $from = Category::where('slug', $fromSlug)->first() ?? throw new RuntimeException("Category {$fromSlug} does not exist.");
        $to = Category::where('slug', $toSlug)->first() ?? throw new RuntimeException("Category {$toSlug} does not exist; create the topic first.");
        $workflow = (string) ($this->categoryWorkflows[$fromSlug] ?? throw new RuntimeException("Category {$fromSlug} has no configured workflow."));

        if (AiPhase::where('category_id', $to->id)->where('workflow_code', $workflow)->exists()) {
            throw new RuntimeException("Category {$toSlug} already has {$workflow} phases; nothing is overwritten.");
        }

        $phases = AiPhase::where('category_id', $from->id)->where('workflow_code', $workflow)->where('is_current', true)
            ->with('schemaLinks')->orderBy('sort_order')->orderBy('phase_code')->get();

        if ($phases->isEmpty()) {
            throw new RuntimeException("Category {$fromSlug} has no current {$workflow} phases to copy.");
        }

        $rows = [];

        foreach ($phases as $phase) {
            if (isset($rows[$phase->phase_code])) {
                throw new RuntimeException("Duplicate current {$phase->phase_code} in category {$fromSlug}.");
            }

            if ($phase->status !== AiPhase::PUBLISHED || ! hash_equals((string) $phase->content_sha256, hash('sha256', (string) $phase->prompt_content))) {
                throw new RuntimeException("Current {$phase->phase_code} revision {$phase->revision} of {$fromSlug} is not published or does not match its hash.");
            }

            $rows[$phase->phase_code] = [
                'id' => (string) $phase->id,
                'phase' => $phase->phase_code,
                'revision' => $phase->revision,
                'sha256' => $phase->content_sha256,
                'links' => $phase->schemaLinks->map(fn (AiPhaseSchema $link): array => $link->only(self::LINK_FIELDS))->sortBy(['role', 'slot_key'])->values()->all(),
            ];
        }

        $warnings = isset($this->categoryWorkflows[$toSlug]) ? [] : ["Category {$toSlug} is not mapped to {$workflow} in video.superyacht.contracts.categories; projects of this topic will not resolve their phases until it is."];

        $plan = [
            'workflow' => $workflow,
            'from' => ['id' => (string) $from->id, 'slug' => $fromSlug],
            'to' => ['id' => (string) $to->id, 'slug' => $toSlug],
            'phases' => array_values($rows),
            'warnings' => $warnings,
        ];
        $plan['plan_hash'] = CanonicalJson::hash($plan);

        return $plan;
    }

    /** @return array{phases: int, links: int} */
    public function apply(string $fromSlug, string $toSlug, string $expectedPlan): array
    {
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [self::LOCK])->acquired !== 1) {
            throw new RuntimeException('Another clone is running.');
        }

        try {
            return DB::transaction(function () use ($fromSlug, $toSlug, $expectedPlan): array {
                $plan = $this->plan($fromSlug, $toSlug);
                AiPhase::whereIn('id', array_column($plan['phases'], 'id'))->lockForUpdate()->get();
                $plan = $this->plan($fromSlug, $toSlug);

                if (! hash_equals($plan['plan_hash'], $expectedPlan)) {
                    throw new RuntimeException('Clone plan changed. Run the preview again; nothing written.');
                }

                $revisions = PhaseRevisions::fromConfig((array) config('video.superyacht'));
                $links = 0;

                foreach ($plan['phases'] as $row) {
                    $source = AiPhase::findOrFail($row['id']);
                    $copy = AiPhase::create($source->only(self::COPIED) + [
                        'category_id' => $plan['to']['id'],
                        'revision' => 1,
                        'status' => AiPhase::DRAFT,
                        'is_current' => false,
                        'based_on_id' => $source->id,
                        'change_note' => "Copied from {$plan['from']['slug']} {$source->phase_code} revision {$source->revision} (plan {$plan['plan_hash']}).",
                        'created_by' => 'video:spec-clone',
                    ]);

                    foreach ($row['links'] as $link) {
                        AiPhaseSchema::create(['phase_id' => $copy->id] + $link);
                        $links++;
                    }

                    $revisions->publish($copy, null);
                }

                return ['phases' => count($plan['phases']), 'links' => $links];
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
        }
    }
}
