<?php

namespace App\Console\Commands;

use App\Models\VideoProject;
use App\Services\Video\MotoCharacterReferenceService;
use App\Video\Moto\MotoCharacterPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VideoMotoCharacterOutfit extends Command
{
    protected $signature = 'video:moto-character-outfit
        {project : Video project id}
        {--character=* : A character of the approved script to show in the clothes of the script where the source names no protective equipment; give the whole list every time, none to drop the list}
        {--reason= : Why these characters are shown this way}
        {--apply : Store the list with the project and keep the one it replaces in the history}';

    protected $description = 'Preview (or with --apply store) the characters of a moto project whose reference images use the clothes of the approved script in the shots whose source names no protective equipment. Each decision is tied to the description of the character and the shots it was made from. The source keeps its null, every other character stays as it is, no model is called and nothing is rendered.';

    public function __construct(private readonly MotoCharacterReferenceService $characters)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $projectId = (string) $this->argument('project');

        if (VideoProject::query()->find($projectId) === null) {
            $this->error('Project not found.');

            return self::FAILURE;
        }

        $chosen = array_values(array_unique(array_map('strval', (array) $this->option('character'))));
        sort($chosen, SORT_STRING);
        $reason = trim((string) $this->option('reason'));

        try {
            $preview = $this->preview($projectId, $chosen);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($chosen !== [] && $reason === '') {
            $this->error('Give --reason.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $this->line('Characters in the clothes of the script where the source names no protective equipment: '.($chosen === [] ? '(none)' : implode(', ', $chosen)));
        $this->line('In use now: '.($preview['current'] === [] ? '(none)' : implode(', ', $preview['current'])).($preview['lapsed'] === [] ? '' : ' · confirmed on other sources, not applied: '.implode(', ', $preview['lapsed'])));
        $this->line('Cells of the plan in use now: '.$preview['cells_now'].' · added: '.count($preview['added']).' · no longer needed: '.count($preview['removed']).' · changed: '.count($preview['changed']));

        foreach (['added' => 'new cells', 'removed' => 'cells no longer needed (their images and history stay)', 'changed' => 'cells whose content changes'] as $key => $label) {
            foreach ($this->byCharacter($preview[$key]) as $character => $codes) {
                $this->line("  {$character}: ".count($codes)." {$label} — ".implode(', ', $codes));
            }
        }

        foreach ($preview['bindings'] as $line) {
            $this->line('  '.$line);
        }

        $this->line('Bindings unresolved: '.$preview['unresolved_after'].' (now '.$preview['unresolved_now'].')');

        if (! $apply) {
            $this->info('Run again with --apply to store it with the project.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($projectId, $chosen, $reason, $preview): void {
                $locked = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                if ($this->preview($projectId, $chosen)['hash'] !== $preview['hash']) {
                    throw new RuntimeException('The sources changed while the preview was read — run the preview again.');
                }

                $metadata = (array) ($locked->metadata_json ?? []);
                $history = (array) ($metadata[MotoCharacterPlanner::SCRIPT_OUTFIT_KEY.'_history'] ?? []);

                if (isset($metadata[MotoCharacterPlanner::SCRIPT_OUTFIT_KEY])) {
                    $history[] = $metadata[MotoCharacterPlanner::SCRIPT_OUTFIT_KEY];
                }

                $metadata[MotoCharacterPlanner::SCRIPT_OUTFIT_KEY] = ['version' => 'moto-character-script-outfit-v2', 'records' => $preview['records'], 'shot_stage_id' => $preview['shot_stage_id'], 'reason' => $reason, 'confirmed_by' => 'console', 'confirmed_at' => now()->toIso8601String()];
                $metadata[MotoCharacterPlanner::SCRIPT_OUTFIT_KEY.'_history'] = $history;
                $locked->forceFill(['metadata_json' => $metadata])->save();
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Stored.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $chosen
     * @return array<string, mixed> what storing the list changes against the plan the project uses now: the cells added, no longer needed and changed, the bindings that change, the record of every character with the hash of its sources, and one hash of all of it
     *
     * @throws RuntimeException when the shot plan cannot be read or a character cannot take the decision
     */
    private function preview(string $projectId, array $chosen): array
    {
        $source = $this->characters->source($projectId);
        $shots = $source['shots'];
        $state = MotoCharacterPlanner::scriptOutfit(VideoProject::query()->whereKey($projectId)->value('metadata_json'), $shots, $source['characters']);
        $bare = MotoCharacterPlanner::plan($shots, $source['characters'], $source['settings']);
        $unstated = [];

        foreach ($bare['bindings'] as $rows) {
            foreach ($rows as $row) {
                if ($row['type'] === 'unresolved') {
                    $unstated[(string) $row['character']] = true;
                }
            }
        }

        foreach ($chosen as $character) {
            if (trim((string) ($source['characters'][$character] ?? '')) === '') {
                throw new RuntimeException("{$character}: the approved script has no description of this character.");
            }

            if (! isset($unstated[$character])) {
                throw new RuntimeException("{$character}: no shot of the approved plan shows this character without protective equipment stated — nothing to decide.");
            }
        }

        $now = $source['plan'];
        $after = MotoCharacterPlanner::plan($shots, $source['characters'], $source['settings'], $chosen);
        $before = array_column($now['cells'], null, 'id');
        $next = array_column($after['cells'], null, 'id');
        $bindings = [];

        foreach ($after['bindings'] + $now['bindings'] as $shotId => $unused) {
            $was = array_column((array) ($now['bindings'][$shotId] ?? []), null, 'character');
            $will = array_column((array) ($after['bindings'][$shotId] ?? []), null, 'character');

            foreach ($will + $was as $character => $ignored) {
                if (($was[$character] ?? null) != ($will[$character] ?? null)) {
                    $bindings[] = "{$shotId}: {$character} — ".self::bound($was[$character] ?? null).' → '.self::bound($will[$character] ?? null);
                }
            }
        }

        $records = [];

        foreach ($chosen as $character) {
            $records[$character] = ['fingerprint' => MotoCharacterPlanner::outfitFingerprint($character, $shots, $source['characters'])];
        }

        $result = [
            'current' => $state['valid'],
            'lapsed' => $state['lapsed'],
            'cells_now' => count($before),
            'added' => array_values(array_diff_key($next, $before)),
            'removed' => array_values(array_diff_key($before, $next)),
            'changed' => array_values(array_filter(array_intersect_key($next, $before), static fn (array $cell, string $id): bool => $cell != $before[$id], ARRAY_FILTER_USE_BOTH)),
            'bindings' => $bindings,
            'unresolved_now' => count($now['problems']),
            'unresolved_after' => count($after['problems']),
            'records' => $records,
            'shot_stage_id' => (string) $source['approved']['stage_id'],
        ];

        return $result + ['hash' => hash('sha256', (string) json_encode([$result['records'], $result['shot_stage_id'], array_column($result['added'], 'fingerprint'), array_column($result['removed'], 'fingerprint'), array_column($result['changed'], 'fingerprint'), $bindings]))];
    }

    /** @param  ?array<string, mixed>  $binding */
    private static function bound(?array $binding): string
    {
        return match (true) {
            $binding === null => 'none',
            $binding['type'] === 'unresolved' => 'unresolved',
            $binding['type'] === 'continuity' => 'keyframe of '.$binding['target'],
            default => 'cell '.$binding['cell'].(isset($binding['outfit']) ? ' (clothes of the script, source names no protective equipment)' : ''),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, list<string>> the codes of the cells by character
     */
    private function byCharacter(array $cells): array
    {
        $grouped = [];

        foreach ($cells as $cell) {
            $grouped[(string) $cell['character']][] = (string) $cell['code'];
        }

        return $grouped;
    }
}
