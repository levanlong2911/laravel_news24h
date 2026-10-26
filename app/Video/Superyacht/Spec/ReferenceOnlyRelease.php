<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Spec;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\Category;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** One-time, repeatable release. Approved project inputs and outputs are never edited. */
final class ReferenceOnlyRelease
{
    private const JOBS = ['App\\Jobs\\BuildProxyJob', 'App\\Jobs\\PrepareProxyJob'];

    private const LINK_FIELDS = ['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required'];

    public function clean(string $body): string
    {
        $body = preg_replace('/\R### Proxy geometry contract[^\r\n]*\R.*?(?=^#{2,3} |\z)/ms', "\n", $body);
        $body = preg_replace('/^### Step 4B — Proxy input test[^\r\n]*\R.*?(?=^#{2,3} |\z)/ms', '', $body);
        $path = resource_path('ai/superyacht/contracts/reference-only-replacements.json');
        $replacements = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $body = str_replace(array_keys($replacements), array_values($replacements), $body);
        $body = preg_replace('/^(?:\| 3P \||\| 3D proxy \||\d+\. 3D proxy\s*$|\| Proxy match \|)[^\r\n]*(?:\R|$)/m', '', $body);

        return str_replace('"generate", "edit_from_reference", "edit_from_reference", "none"', '"generate", "edit_from_reference", "none"', $body);
    }

    /** @return array<string, mixed> Read-only: suitable for a deploy preview. */
    public function plan(): array
    {
        $config = (array) config('video.superyacht');
        $slugs = array_keys(array_filter($config['contracts']['categories'], static fn ($workflow) => $workflow === $config['workflow_code']));
        $categories = Category::whereIn('slug', $slugs)->orderBy('id')->get(['id', 'slug']);
        $path = resource_path('ai/superyacht/schemas/CompiledShot.json');
        $content = (string) file_get_contents($path);
        $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        if (($decoded->{'$id'} ?? null) !== 'https://superyacht.local/schemas/CompiledShot') {
            throw new RuntimeException('Unexpected CompiledShot schema identity.');
        }
        $version = (string) ($config['schema_versions']['CompiledShot'] ?? '');
        if ($version === '') throw new RuntimeException('CompiledShot release version is not configured.');
        $schema = AiJsonSchema::where('namespace', $config['schema_namespace'])->where('schema_key', 'CompiledShot')->where('version', $version)->first();
        if ($schema !== null && ($schema->status !== AiJsonSchema::PUBLISHED || $schema->schema_sha256 !== hash('sha256', $content) || $schema->schema_content !== $content)) {
            throw new RuntimeException('Published CompiledShot version differs from the release. Publish a new version; do not overwrite history.');
        }
        $phases = AiPhase::whereIn('category_id', $categories->pluck('id'))->where('workflow_code', $config['workflow_code'])
            ->where('is_current', true)->with('schemaLinks.schema')->orderBy('id')->get();
        $changes = [];
        $seen = [];
        foreach ($phases as $phase) {
            $key = $phase->category_id.'|'.$phase->phase_code;
            if (isset($seen[$key])) throw new RuntimeException('Duplicate current phase: '.$key);
            $seen[$key] = true;
            if ($phase->status !== AiPhase::PUBLISHED || ! hash_equals($phase->content_sha256, hash('sha256', $phase->prompt_content))) {
                throw new RuntimeException('Invalid current prompt: '.$phase->id);
            }
            $retire = $phase->phase_code === 'phase-3p';
            $body = $retire ? $phase->prompt_content : $this->clean($phase->prompt_content);
            $needs = $this->dependencies($phase->needs);
            $parallel = $this->dependencies($phase->parallel_with);
            $oldLinks = $phase->schemaLinks->map(fn ($link) => $link->only(self::LINK_FIELDS))->all();
            $links = [];
            foreach ($phase->schemaLinks as $link) {
                if ($link->slot_key === 'proxy_geometry' || $link->schema?->schema_key === 'ProxyGeometry') continue;
                $row = $link->only(self::LINK_FIELDS);
                if ($link->schema?->schema_key === 'CompiledShot') $row['schema_id'] = $schema?->id ?? '@CompiledShot';
                $links[] = $row;
            }
            if (! $retire && preg_match('/proxy_geometry|proxy3d|proxy_input|edit_from_clay|\b3P\b|proxy render|proxy mask|proxy clay|clay render/i', $body.' '.$needs.' '.$parallel)) {
                throw new RuntimeException('Unrecognized retired dependency in '.$phase->phase_code.'; review this customized prompt before deploying.');
            }
            if (! $retire && $body === $phase->prompt_content && $needs === $phase->needs && $parallel === $phase->parallel_with && $links == $oldLinks) continue;
            $changes[] = ['id' => $phase->id, 'category_id' => $phase->category_id, 'phase' => $phase->phase_code, 'revision' => $phase->revision,
                'previous_sha256' => $phase->content_sha256, 'retire' => $retire, 'content' => $body, 'needs' => $needs, 'parallel_with' => $parallel,
                'previous_links' => $oldLinks, 'links' => $links];
        }
        $jobs = [];
        foreach (DB::table('jobs')->orderBy('id')->get() as $job) {
            $payload = json_decode($job->payload, true);
            if (! in_array($payload['data']['commandName'] ?? $payload['displayName'] ?? '', self::JOBS, true)) continue;
            $jobs[] = ['id' => $job->id, 'sha256' => hash('sha256', $job->payload), 'reserved_at' => $job->reserved_at, 'attempts' => $job->attempts];
        }
        $plan = ['categories' => $categories->toArray(), 'schema' => ['namespace' => $config['schema_namespace'], 'version' => $version,
            'id' => $schema?->id, 'action' => $schema === null && $phases->isNotEmpty() ? 'insert' : 'reuse', 'sha256' => hash('sha256', $content)],
            'changes' => $changes, 'jobs' => $jobs];
        $plan['plan_hash'] = CanonicalJson::hash($plan);

        return $plan;
    }

    /** @return array<string, mixed> Changes are atomic; backups must succeed before any write. */
    public function apply(string $expectedPlan): array
    {
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', ['yacht-reference-only-release'])->acquired !== 1) {
            throw new RuntimeException('Another release is running.');
        }
        try {
            return DB::transaction(function () use ($expectedPlan): array {
                // Lock current revisions and only the reviewed jobs, then recompute to detect drift.
                $initial = $this->plan();
                AiPhase::whereIn('id', array_column($initial['changes'], 'id'))->lockForUpdate()->get();
                DB::table('jobs')->whereIn('id', array_column($initial['jobs'], 'id'))->lockForUpdate()->get();
                $plan = $this->plan();
                if (! hash_equals($plan['plan_hash'], $expectedPlan)) throw new RuntimeException('Release plan changed. Run the preview again; nothing written.');
                foreach ($plan['jobs'] as $job) {
                    if ($job['reserved_at'] !== null) throw new RuntimeException('A retired job is reserved. Stop its worker and release the reservation before retrying; nothing written.');
                }
                if ($plan['changes'] === [] && $plan['jobs'] === [] && $plan['schema']['action'] !== 'insert') {
                    return ['changed' => false, 'phases' => 0, 'jobs' => 0, 'backup' => null];
                }
                $oldPhases = AiPhase::whereIn('id', array_column($plan['changes'], 'id'))->with('schemaLinks')->get()->toArray();
                $oldJobs = DB::table('jobs')->whereIn('id', array_column($plan['jobs'], 'id'))->get()->toArray();
                $backup = 'deployments/reference-only/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
                if (! Storage::disk('local')->put($backup, json_encode(['plan' => $plan, 'phases' => $oldPhases, 'jobs' => $oldJobs], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    throw new RuntimeException('Could not save release backup; nothing written.');
                }
                $schemaId = $plan['schema']['id'];
                if ($plan['schema']['action'] === 'insert') {
                    $content = (string) file_get_contents(resource_path('ai/superyacht/schemas/CompiledShot.json'));
                    if (! hash_equals($plan['schema']['sha256'], hash('sha256', $content))) throw new RuntimeException('Schema file changed during release.');
                    $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
                    $schemaId = AiJsonSchema::create(['namespace' => $plan['schema']['namespace'], 'schema_key' => 'CompiledShot', 'version' => $plan['schema']['version'],
                        'name' => $decoded->title, 'schema_content' => $content, 'schema_sha256' => hash('sha256', $content),
                        'source_file' => 'resources/ai/superyacht/schemas/CompiledShot.json', 'json_schema_id' => $decoded->{'$id'},
                        'json_schema_dialect' => $decoded->{'$schema'}, 'dependencies' => [], 'status' => AiJsonSchema::PUBLISHED,
                        'change_note' => 'Reference-only image inputs; retain previous published schemas.', 'created_by' => 'video:reference-only-release', 'published_at' => now()])->id;
                }
                $revisions = PhaseRevisions::fromConfig(config('video.superyacht'));
                foreach ($plan['changes'] as $change) {
                    $base = AiPhase::findOrFail($change['id']);
                    if ($change['retire']) {
                        $base->is_current = false;
                        $base->save();
                        continue;
                    }
                    $draft = AiPhase::create($base->only(['category_id', 'workflow_code', 'phase_code', 'kind', 'name', 'sort_order', 'stop']) + [
                        'revision' => 1 + AiPhase::where('category_id', $base->category_id)->where('workflow_code', $base->workflow_code)->where('phase_code', $base->phase_code)->max('revision'),
                        'prompt_content' => $change['content'], 'content_sha256' => hash('sha256', $change['content']), 'needs' => $change['needs'], 'parallel_with' => $change['parallel_with'],
                        'status' => AiPhase::DRAFT, 'is_current' => false, 'based_on_id' => $base->id,
                        'change_note' => 'Retire the 3D phase and dependencies (plan '.$plan['plan_hash'].').', 'created_by' => 'video:reference-only-release',
                    ]);
                    foreach ($change['links'] as $link) {
                        if ($link['schema_id'] === '@CompiledShot') $link['schema_id'] = $schemaId;
                        AiPhaseSchema::create(['phase_id' => $draft->id] + $link);
                    }
                    $revisions->publish($draft, $base->id);
                }
                DB::table('jobs')->whereIn('id', array_column($plan['jobs'], 'id'))->delete();

                return ['changed' => true, 'phases' => count($plan['changes']), 'jobs' => count($plan['jobs']), 'backup' => $backup];
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', ['yacht-reference-only-release']);
        }
    }

    private function dependencies(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/\b3P,\s*|,\s*3P\b|\b3P\b/', '', $value);
    }
}
